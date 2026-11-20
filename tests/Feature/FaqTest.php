<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Feature;
use App\Models\Package;
use App\Models\Page;
use App\Models\Role;
use Tests\TestCase;

class FaqTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    // Full migration set is MySQL-only; build just the tables this feature touches.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000003_create_admins_table',
            '2026_09_14_000003_create_roles_table',
            '2026_09_14_000004_create_features_table',
            '2026_09_14_000005_create_packages_table',
            '2026_09_14_000006_create_package_role_table',
            '2026_09_14_000007_create_package_feature_table',
            '2026_11_20_000400_add_actions_to_package_feature_table',
            '2026_09_14_000008_create_admin_package_table',
            '2026_09_16_000001_create_package_limits_table',
            '2026_09_16_000003_create_permissions_table',
            '2026_09_16_000004_create_package_permission_table',
            '2026_11_15_000500_create_pages_table',
            '2026_11_19_000100_add_gallery_flag_and_faq_page',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Feature::create(['key' => 'pages', 'label' => 'Pages', 'category' => 'admin', 'sort_order' => 0]);
    }

    private function admin(): Admin
    {
        $admin = Admin::factory()->create();
        $package = Package::create(['name' => 'Seat', 'is_system' => true]);
        $package->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $package->features()->sync(Feature::pluck('id')->all());
        $admin->packages()->sync([$package->id => ['location_ids' => null, 'hotel_ids' => null]]);

        return $admin;
    }

    public function test_faq_page_exists_as_a_draft_system_page(): void
    {
        $page = Page::where('slug', 'faq')->first();
        $this->assertNotNull($page);
        $this->assertTrue($page->is_system);
        $this->assertSame('draft', $page->status);
    }

    public function test_admin_writes_faq_blocks(): void
    {
        $admin = $this->admin();
        $page = Page::where('slug', 'faq')->first();

        $this->actingAs($admin, 'admin')->patchJson("/admin/pages/{$page->id}", [
            'status' => 'published',
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Booking'],
                ['type' => 'faq', 'items' => [
                    ['question' => 'Can I cancel?', 'answer' => 'Yes, see terms.', 'junk' => 'x'],
                    ['question' => 'Check-in time?', 'answer' => 'From 2pm.'],
                ]],
            ],
        ], self::JSON)->assertOk();

        $blocks = $this->getJson("/admin/pages/{$page->id}", self::JSON)->assertOk()->json('page.blocks');
        $this->assertSame('faq', $blocks[1]['type']);
        $this->assertCount(2, $blocks[1]['items']);
        $this->assertSame(['question', 'answer'], array_keys($blocks[1]['items'][0]));
    }

    public function test_faq_items_need_question_and_answer(): void
    {
        $admin = $this->admin();
        $page = Page::where('slug', 'faq')->first();

        foreach ([
            [['question' => 'Only a question']],
            [['answer' => 'Only an answer']],
            [[]],
        ] as $items) {
            $this->actingAs($admin, 'admin')->patchJson("/admin/pages/{$page->id}", [
                'blocks' => [['type' => 'faq', 'items' => $items]],
            ], self::JSON)->assertStatus(422);
        }
        $this->actingAs($admin, 'admin')->patchJson("/admin/pages/{$page->id}", [
            'blocks' => [['type' => 'faq']],
        ], self::JSON)->assertStatus(422);
    }

    public function test_plain_lists_still_work_and_must_be_text(): void
    {
        $admin = $this->admin();
        $page = Page::where('slug', 'faq')->first();

        $this->actingAs($admin, 'admin')->patchJson("/admin/pages/{$page->id}", [
            'blocks' => [['type' => 'list', 'style' => 'bullet', 'items' => ['One', 'Two']]],
        ], self::JSON)->assertOk();

        $this->actingAs($admin, 'admin')->patchJson("/admin/pages/{$page->id}", [
            'blocks' => [['type' => 'list', 'style' => 'bullet', 'items' => [['question' => 'q', 'answer' => 'a']]]],
        ], self::JSON)->assertStatus(422);
    }
}
