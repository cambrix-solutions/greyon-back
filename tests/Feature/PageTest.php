<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Feature;
use App\Models\Package;
use App\Models\Page;
use App\Models\Role;
use Tests\TestCase;

class PageTest extends TestCase
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
            '2026_09_14_000008_create_admin_package_table',
            '2026_09_16_000001_create_package_limits_table',
            '2026_09_16_000003_create_permissions_table',
            '2026_09_16_000004_create_package_permission_table',
            '2026_11_15_000500_create_pages_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        Feature::create(['key' => 'pages', 'label' => 'Pages', 'category' => 'admin', 'sort_order' => 0]);
    }

    private function adminWith(string $role = 'admin', bool $pages = true): Admin
    {
        $package = Package::create(['name' => "Seat {$role}", 'is_system' => true]);
        $package->roles()->sync([Role::where('name', $role)->value('id')]);
        if ($pages) {
            $package->features()->sync(Feature::where('key', 'pages')->pluck('id'));
        }

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => $role === 'manager' ? [1] : null, 'hotel_ids' => null]]);

        return $admin;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'FAQ',
            'slug' => 'faq',
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Check-in'],
                ['type' => 'paragraph', 'text' => 'From 2 pm.'],
                ['type' => 'list', 'style' => 'bullet', 'items' => ['ID needed', 'Card on arrival']],
                ['type' => 'image', 'url' => 'uploads/pages/lobby.jpg', 'alt' => 'Lobby'],
                ['type' => 'divider'],
            ],
            'status' => 'published',
        ], $over);
    }

    public function test_admin_creates_a_page_with_blocks(): void
    {
        $this->actingAs($this->adminWith(), 'admin')
            ->postJson('/admin/pages', $this->payload(), self::JSON)
            ->assertCreated()
            ->assertJsonPath('page.slug', 'faq')
            ->assertJsonPath('page.isSystem', false)
            ->assertJsonCount(5, 'page.blocks');

        $this->assertNotNull(Page::where('slug', 'faq')->value('published_at'));
    }

    public function test_unknown_block_type_and_missing_fields_are_rejected(): void
    {
        $admin = $this->adminWith();
        $this->actingAs($admin, 'admin');

        $this->postJson('/admin/pages', $this->payload(['blocks' => [['type' => 'script', 'text' => 'x']]]), self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('blocks.0.type');

        $this->postJson('/admin/pages', $this->payload(['blocks' => [['type' => 'paragraph']]]), self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('blocks.0.text');

        $this->postJson('/admin/pages', $this->payload(['blocks' => [['type' => 'image', 'url' => 'javascript:alert(1)']]]), self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('blocks.0.url');
    }

    public function test_stray_keys_are_not_stored(): void
    {
        $this->actingAs($this->adminWith(), 'admin')
            ->postJson('/admin/pages', $this->payload(['blocks' => [['type' => 'paragraph', 'text' => 'Hi', 'html' => '<script>x</script>']]]), self::JSON)
            ->assertCreated();

        $this->assertSame([['type' => 'paragraph', 'text' => 'Hi']], Page::where('slug', 'faq')->value('blocks'));
    }

    public function test_slug_must_be_unique_and_url_safe(): void
    {
        $this->actingAs($this->adminWith(), 'admin');
        $this->postJson('/admin/pages', $this->payload(), self::JSON)->assertCreated();

        $this->postJson('/admin/pages', $this->payload(), self::JSON)->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/admin/pages', $this->payload(['slug' => 'Bad Slug!']), self::JSON)->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_built_in_pages_keep_their_slug_and_cannot_be_deleted(): void
    {
        $page = Page::create(['slug' => 'privacy', 'title' => 'Privacy Policy', 'blocks' => [], 'status' => 'draft', 'is_system' => true]);
        $this->actingAs($this->adminWith(), 'admin');

        $this->patchJson("/admin/pages/{$page->id}", ['slug' => 'privacy-2'], self::JSON)->assertStatus(400);
        $this->patchJson("/admin/pages/{$page->id}", ['title' => 'Privacy', 'status' => 'published'], self::JSON)->assertOk()->assertJsonPath('page.title', 'Privacy');
        $this->deleteJson("/admin/pages/{$page->id}", [], self::JSON)->assertStatus(400);
    }

    public function test_custom_page_can_be_deleted(): void
    {
        $this->actingAs($this->adminWith(), 'admin');
        $id = $this->postJson('/admin/pages', $this->payload(), self::JSON)->json('page.id');

        $this->deleteJson("/admin/pages/{$id}", [], self::JSON)->assertOk();
        $this->assertDatabaseMissing('pages', ['id' => $id]);
    }

    public function test_admin_without_pages_feature_is_forbidden(): void
    {
        $this->actingAs($this->adminWith('admin', pages: false), 'admin')
            ->getJson('/admin/pages', self::JSON)->assertForbidden();
    }

    public function test_non_global_seat_cannot_write(): void
    {
        $this->actingAs($this->adminWith('manager'), 'admin')
            ->postJson('/admin/pages', $this->payload(), self::JSON)->assertForbidden();
    }

    public function test_public_shows_only_published_pages(): void
    {
        Page::create(['slug' => 'terms', 'title' => 'Terms', 'blocks' => [['type' => 'paragraph', 'text' => 'T']], 'status' => 'published', 'is_system' => true]);
        Page::create(['slug' => 'privacy', 'title' => 'Privacy', 'blocks' => [], 'status' => 'draft', 'is_system' => true]);

        $this->getJson('/pages/terms', self::JSON)->assertOk()->assertJsonPath('page.title', 'Terms');
        $this->getJson('/pages/privacy', self::JSON)->assertNotFound();
        $this->getJson('/pages', self::JSON)->assertOk()->assertJsonCount(1, 'pages')->assertJsonPath('pages.0.slug', 'terms');
    }
}
