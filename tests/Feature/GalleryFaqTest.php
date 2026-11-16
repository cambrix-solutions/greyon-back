<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\MediaItem;
use App\Models\Package;
use App\Models\Page;
use App\Models\Role;
use Tests\TestCase;

class GalleryFaqTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Location $pp;

    private Location $draftLoc;

    private Hotel $hotel;

    // Full migration set is MySQL-only; build just the tables this feature touches.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000000_create_users_table',
            '2026_09_14_000002_create_developers_table',
            '0001_01_01_000003_create_admins_table',
            '2026_09_14_000003_create_roles_table',
            '2026_09_14_000004_create_features_table',
            '2026_09_14_000005_create_packages_table',
            '2026_09_14_000006_create_package_role_table',
            '2026_09_14_000007_create_package_feature_table',
            '2026_11_20_000100_add_actions_to_package_feature_table',
            '2026_09_14_000008_create_admin_package_table',
            '2026_09_16_000001_create_package_limits_table',
            '2026_09_16_000003_create_permissions_table',
            '2026_09_16_000004_create_package_permission_table',
            '2026_09_15_000001_create_locations_table',
            '2026_09_30_000100_add_phone_and_email_to_locations_table',
            '2026_09_16_000002_add_created_by_admin_id_to_locations_table',
            '2026_09_15_000002_create_hotels_table',
            '2026_09_19_000001_create_ops_and_cms_tables',
            '2026_09_27_000100_add_location_and_hotel_to_media_items_table',
            '2026_11_15_000500_create_pages_table',
            '2026_11_19_000100_add_gallery_flag_and_faq_page',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Feature::create(['key' => 'pages', 'label' => 'Pages', 'category' => 'admin', 'sort_order' => 0]);
        Feature::create(['key' => 'media', 'label' => 'Media', 'category' => 'admin', 'sort_order' => 1]);

        $this->pp = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $this->draftLoc = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'draft']);
        $this->hotel = Hotel::create(['location_id' => $this->pp->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published']);
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

    private function media(array $over = []): MediaItem
    {
        return MediaItem::create(array_merge(['location_id' => $this->pp->id, 'src' => 'uploads/a.jpg', 'alt' => 'A'], $over));
    }

    public function test_faq_page_exists_as_a_draft_system_page(): void
    {
        $page = Page::where('slug', 'faq')->first();
        $this->assertNotNull($page);
        $this->assertTrue($page->is_system);
        $this->assertSame('draft', $page->status);
        $this->getJson('/pages/faq', self::JSON)->assertNotFound();
    }

    public function test_admin_writes_faq_blocks_and_public_reads_them(): void
    {
        $admin = $this->admin();
        $page = Page::where('slug', 'faq')->first();

        $res = $this->actingAs($admin, 'admin')->patchJson("/admin/pages/{$page->id}", [
            'status' => 'published',
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Booking'],
                ['type' => 'faq', 'items' => [
                    ['question' => 'Can I cancel?', 'answer' => 'Yes, see terms.', 'junk' => 'x'],
                    ['question' => 'Check-in time?', 'answer' => 'From 2pm.'],
                ]],
            ],
        ], self::JSON);
        $res->assertOk();

        $blocks = $this->getJson('/pages/faq', self::JSON)->assertOk()->json('page.blocks');
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

    public function test_gallery_lists_only_public_items(): void
    {
        $shown = $this->media(['src' => 'uploads/shown.jpg']);
        $this->media(['src' => 'uploads/off.jpg', 'in_gallery' => false]);
        $this->media(['src' => 'uploads/draft-city.jpg', 'location_id' => $this->draftLoc->id]);
        $withHotel = $this->media(['src' => 'uploads/hotel.jpg', 'hotel_id' => $this->hotel->id]);

        $res = $this->getJson('/gallery', self::JSON)->assertOk();
        $this->assertEqualsCanonicalizing([$shown->id, $withHotel->id], array_column($res->json('gallery'), 'id'));
        $this->assertSame(2, $res->json('meta.total'));

        $row = collect($res->json('gallery'))->firstWhere('id', $withHotel->id);
        $this->assertSame('phnom-penh', $row['location']['slug']);
        $this->assertSame('greyon-pp', $row['hotel']['slug']);
        $this->assertArrayNotHasKey('createdByAdminId', $row);
    }

    public function test_gallery_filters_paging_and_unknown_slug(): void
    {
        foreach (range(1, 5) as $i) {
            $this->media(['src' => "uploads/$i.jpg"]);
        }
        $hotelItem = $this->media(['src' => 'uploads/h.jpg', 'hotel_id' => $this->hotel->id]);

        $page = $this->getJson('/gallery?perPage=4&page=2', self::JSON)->assertOk();
        $this->assertCount(2, $page->json('gallery'));
        $this->assertSame(2, $page->json('meta.lastPage'));

        $byHotel = $this->getJson('/gallery?hotel=greyon-pp', self::JSON)->assertOk();
        $this->assertSame([$hotelItem->id], array_column($byHotel->json('gallery'), 'id'));

        $this->assertSame(6, $this->getJson('/gallery?location=phnom-penh', self::JSON)->json('meta.total'));
        $this->getJson('/gallery?location=kampot', self::JSON)->assertNotFound();
        $this->getJson('/gallery?hotel=nope', self::JSON)->assertNotFound();
        $this->getJson('/gallery?perPage=500', self::JSON)->assertStatus(422);
    }

    public function test_staff_can_hide_an_item_from_the_gallery(): void
    {
        $admin = $this->admin();
        $item = $this->media();

        $this->actingAs($admin, 'admin')->patchJson("/admin/media/{$item->id}", ['inGallery' => false], self::JSON)
            ->assertOk()->assertJsonPath('mediaItem.inGallery', false);

        $this->assertSame(0, $this->getJson('/gallery', self::JSON)->json('meta.total'));
    }
}
