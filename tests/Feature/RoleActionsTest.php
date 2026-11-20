<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use Tests\TestCase;

/**
 * Per-feature actions on custom roles (view / create / edit / delete), the
 * effective access on /admin/me, the Guests CSV and staff reservation sources.
 */
class RoleActionsTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Hotel $hotel;

    // Full migration set is MySQL-only; build just the tables this feature touches.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000003_create_admins_table',
            '2026_09_23_000001_add_created_by_admin_id_to_admins_table',
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
            '2026_11_15_000100_add_created_by_admin_id_to_packages_table',
            '2026_09_15_000001_create_locations_table',
            '2026_09_30_000100_add_phone_and_email_to_locations_table',
            '2026_09_16_000002_add_created_by_admin_id_to_locations_table',
            '2026_09_15_000002_create_hotels_table',
            '2026_09_24_000100_add_map_embed_url_to_hotels_table',
            '2026_09_30_000200_add_property_type_and_star_rating_to_hotels_table',
            '2026_09_30_000400_add_area_to_hotels_table',
            '2026_09_30_000300_add_nearby_landmarks_to_hotels_table',
            '2026_09_15_000003_create_room_types_table',
            '2026_11_04_000100_add_panorama_path_to_room_types_table',
            '2026_11_20_000300_create_room_type_panoramas_table',
            '2026_09_15_000004_create_rate_plans_table',
            '2026_09_19_000001_create_ops_and_cms_tables',
            '2026_11_20_000500_allow_more_booking_sources',
            '2026_09_20_000001_create_staff_notifications_table',
            '2026_11_17_000100_create_guest_notes_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        Role::create(['name' => 'hotel_admin', 'is_global' => false, 'scope' => 'hotel']);

        foreach (['users', 'roles', 'bookings', 'guests', 'reports', 'rooms'] as $i => $key) {
            Feature::create(['key' => $key, 'label' => ucfirst($key), 'category' => 'admin', 'sort_order' => $i]);
        }

        $location = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $this->hotel = Hotel::create(['location_id' => $location->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published']);
    }

    private function orgAdmin(): Admin
    {
        $package = Package::create(['name' => 'Org admin', 'is_system' => true]);
        $package->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $package->features()->sync(Feature::pluck('id')); // actions null = every action

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => null, 'hotel_ids' => null]]);

        return $admin;
    }

    private function personWith(int $packageId): Admin
    {
        $person = Admin::factory()->create();
        $person->packages()->sync([$packageId => ['location_ids' => null, 'hotel_ids' => [$this->hotel->id]]]);

        return $person;
    }

    private function reservation(array $over = []): Booking
    {
        $room = RoomType::firstOrCreate(['hotel_id' => $this->hotel->id, 'slug' => 'deluxe'], ['name' => 'Deluxe', 'status' => 'published']);
        $plan = RatePlan::firstOrCreate(['room_type_id' => $room->id, 'name' => 'Flexible'], ['base_price' => 100, 'status' => 'published']);

        return Booking::create(array_merge([
            'reference' => 'GRY-'.strtoupper(substr(uniqid(), -6)),
            'hotel_id' => $this->hotel->id, 'room_type_id' => $room->id, 'rate_plan_id' => $plan->id,
            'check_in' => '2026-12-01', 'check_out' => '2026-12-03',
            'rooms' => 1, 'adults' => 2, 'children' => 0,
            'guest_full_name' => 'Dara Sok', 'guest_email' => 'dara@example.com', 'guest_phone' => '012345678',
            'subtotal' => 180, 'taxes_fees' => 20, 'total' => 200,
            'status' => 'confirmed', 'source' => 'website',
        ], $over));
    }

    public function test_existing_seats_keep_every_action(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin')
            ->getJson('/admin/me', self::JSON)
            ->assertOk()
            ->assertJsonPath('access.isGlobal', true)
            ->assertJsonPath('access.features.bookings', ['view', 'create', 'edit', 'delete']);
    }

    public function test_role_with_view_only_can_read_but_not_write(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');

        $id = $this->postJson('/admin/roles', [
            'name' => 'Night auditor',
            'features' => [['key' => 'bookings', 'actions' => ['view']], ['key' => 'guests', 'actions' => ['view', 'edit']]],
        ], self::JSON)
            ->assertCreated()
            ->assertJsonPath('role.featureActions.bookings', ['view'])
            ->assertJsonPath('role.featureActions.guests', ['view', 'edit'])
            ->json('role.id');

        $booking = $this->reservation();
        $person = $this->personWith($id);

        $this->actingAs($person, 'admin')->getJson('/admin/bookings', self::JSON)->assertOk();
        $this->actingAs($person, 'admin')
            ->patchJson("/admin/bookings/{$booking->id}", ['status' => 'cancelled'], self::JSON)
            ->assertForbidden();
        $this->actingAs($person, 'admin')
            ->deleteJson("/admin/bookings/{$booking->id}", [], self::JSON)
            ->assertForbidden();

        $this->actingAs($person, 'admin')
            ->getJson('/admin/me', self::JSON)
            ->assertJsonPath('access.features.bookings', ['view'])
            ->assertJsonPath('access.features.guests', ['view', 'edit']);
    }

    public function test_actions_are_unioned_across_several_roles(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');

        $viewer = $this->postJson('/admin/roles', ['name' => 'Viewer', 'features' => [['key' => 'bookings', 'actions' => ['view']]]], self::JSON)->json('role.id');
        $editor = $this->postJson('/admin/roles', ['name' => 'Editor', 'features' => [['key' => 'bookings', 'actions' => ['edit']]]], self::JSON)
            ->assertJsonPath('role.featureActions.bookings', ['view', 'edit'])
            ->json('role.id');

        $person = Admin::factory()->create();
        $person->packages()->sync([
            $viewer => ['location_ids' => null, 'hotel_ids' => [$this->hotel->id]],
            $editor => ['location_ids' => null, 'hotel_ids' => [$this->hotel->id]],
        ]);

        $booking = $this->reservation();
        $this->actingAs($person, 'admin')
            ->patchJson("/admin/bookings/{$booking->id}", ['notes' => 'Late arrival'], self::JSON)
            ->assertOk();
    }

    public function test_a_role_cannot_grant_actions_its_creator_lacks(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');
        $limitedId = $this->postJson('/admin/roles', [
            'name' => 'Desk lead',
            'features' => [['key' => 'roles', 'actions' => ['view', 'create']], ['key' => 'bookings', 'actions' => ['view']]],
        ], self::JSON)->json('role.id');

        $lead = $this->personWith($limitedId);

        $this->actingAs($lead, 'admin')
            ->postJson('/admin/roles', ['name' => 'Sneaky', 'features' => [['key' => 'bookings', 'actions' => ['delete']]]], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('features');

        $this->actingAs($lead, 'admin')
            ->getJson('/admin/roles/options', self::JSON)
            ->assertOk()
            ->assertJsonPath('features.1.actions', ['view']);
    }

    public function test_360_photo_routes_follow_the_rooms_actions(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');
        $roleId = $this->postJson('/admin/roles', [
            'name' => 'Room editor',
            'features' => [['key' => 'rooms', 'actions' => ['view', 'edit']]],
        ], self::JSON)->assertCreated()->json('role.id');

        $room = RoomType::create(['hotel_id' => $this->hotel->id, 'name' => 'Deluxe', 'slug' => 'deluxe', 'status' => 'published']);
        $photo = $room->panoramas()->create(['path' => 'https://cdn.example.com/360.jpg', 'sort_order' => 0]);
        $base = "/admin/room-types/{$room->id}/panoramas";
        $person = $this->personWith($roleId);

        // view: list. edit: publish toggle and reorder. create: upload. delete: remove.
        $this->actingAs($person, 'admin')->getJson($base, self::JSON)->assertOk();
        $this->actingAs($person, 'admin')->patchJson("{$base}/{$photo->id}", ['isPublished' => false], self::JSON)->assertOk();
        $this->actingAs($person, 'admin')->patchJson("{$base}/reorder", ['ids' => [$photo->id]], self::JSON)->assertOk();
        $this->actingAs($person, 'admin')->postJson($base, [], self::JSON)->assertForbidden();
        $this->actingAs($person, 'admin')->deleteJson("{$base}/{$photo->id}", [], self::JSON)->assertForbidden();
    }

    public function test_guests_csv_export(): void
    {
        $this->reservation();
        $this->reservation(['status' => 'cancelled', 'total' => 999]);

        $csv = $this->actingAs($this->orgAdmin(), 'admin')
            ->get('/admin/guests/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Guest,Email,Phone,Reservations,Cancelled,Nights,Value', $csv);
        $this->assertStringContainsString('"Dara Sok",dara@example.com,012345678,2,1,2,200', $csv);
    }

    public function test_staff_can_record_where_a_reservation_came_from(): void
    {
        $this->assertSame('website', $this->reservation()->fresh()->source);
        $this->assertSame('walk_in', $this->reservation(['source' => 'walk_in'])->fresh()->source);
    }
}