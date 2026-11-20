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

class GuestsReportsNotificationsTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Hotel $pp;     // Phnom Penh (location 1)

    private Hotel $kampot; // Kampot (location 2)

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
            '2026_11_17_000100_create_guest_notes_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        Role::create(['name' => 'hotel_admin', 'is_global' => false, 'scope' => 'hotel']);
        foreach (['guests', 'reports', 'bookings'] as $i => $key) {
            Feature::create(['key' => $key, 'label' => ucfirst($key), 'category' => 'admin', 'sort_order' => $i]);
        }

        $pp = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $kt = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);
        $this->pp = Hotel::create(['location_id' => $pp->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published']);
        $this->kampot = Hotel::create(['location_id' => $kt->id, 'name' => 'Greyon Kampot', 'slug' => 'greyon-kampot', 'status' => 'published']);
    }

    private function admin(string $role = 'admin', array $features = ['guests', 'reports']): Admin
    {
        $package = Package::create(['name' => "Seat {$role} ".uniqid(), 'is_system' => true]);
        $package->roles()->sync([Role::where('name', $role)->value('id')]);
        $package->features()->sync(Feature::whereIn('key', $features)->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => $role === 'manager' ? [1] : null, 'hotel_ids' => null]]);

        return $admin;
    }

    /** A custom role: carries both base roles, scoped by what is picked when the person is added. */
    private function customRoleAdmin(array $locationIds, array $hotelIds): Admin
    {
        $package = Package::create(['name' => 'Front desk '.uniqid(), 'is_system' => false]);
        $package->roles()->sync(Role::whereIn('name', ['manager', 'hotel_admin'])->pluck('id'));
        $package->features()->sync(Feature::whereIn('key', ['guests', 'reports'])->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => [
            'location_ids' => $locationIds ?: null,
            'hotel_ids' => $hotelIds ?: null,
        ]]);

        return $admin;
    }

    private function reservation(Hotel $hotel, string $email, array $over = []): Booking
    {
        static $n = 0;
        $n++;
        $room = RoomType::firstOrCreate(
            ['hotel_id' => $hotel->id, 'slug' => 'deluxe'],
            ['name' => 'Deluxe', 'status' => 'published']
        );
        $plan = RatePlan::firstOrCreate(
            ['room_type_id' => $room->id, 'name' => 'Flexible'],
            ['base_price' => 100, 'status' => 'published']
        );

        $created = $over['created_at'] ?? '2026-11-01 10:00:00';
        unset($over['created_at']);

        $b = new Booking(array_merge([
            'reference' => sprintf('GRY-T%05d', $n),
            'hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'rate_plan_id' => $plan->id,
            'check_in' => '2026-12-01', 'check_out' => '2026-12-03',
            'rooms' => 1, 'adults' => 2, 'children' => 0,
            'guest_full_name' => 'Dara Sok', 'guest_email' => $email, 'guest_phone' => '012345678',
            'subtotal' => 180, 'taxes_fees' => 20, 'total' => 200,
            'status' => 'confirmed', 'source' => 'website',
        ], $over));
        $b->created_at = $created;
        $b->save();

        return $b;
    }

    // ---------- Custom role scope ----------

    public function test_custom_role_on_a_hotel_only_sees_that_hotel(): void
    {
        $this->reservation($this->pp, 'pp@example.com');
        $this->reservation($this->kampot, 'kampot@example.com');

        $this->actingAs($this->customRoleAdmin([], [$this->kampot->id]), 'admin')
            ->getJson('/admin/guests', self::JSON)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('guests.0.email', 'kampot@example.com');
    }

    public function test_custom_role_on_a_destination_sees_every_hotel_in_it(): void
    {
        $this->reservation($this->pp, 'pp@example.com');
        $this->reservation($this->kampot, 'kampot@example.com');

        $this->actingAs($this->customRoleAdmin([$this->pp->location_id], []), 'admin')
            ->getJson('/admin/guests', self::JSON)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('guests.0.email', 'pp@example.com');
    }

    // ---------- Guests ----------

    public function test_guests_are_grouped_by_email_and_cancelled_is_not_counted_as_value(): void
    {
        $this->reservation($this->pp, 'dara@example.com', ['created_at' => '2026-11-01 10:00:00']);
        $this->reservation($this->pp, 'DARA@example.com ', ['created_at' => '2026-11-05 10:00:00', 'guest_full_name' => 'Dara S.', 'total' => 300]);
        $this->reservation($this->pp, 'dara@example.com', ['status' => 'cancelled', 'total' => 999]);
        $this->reservation($this->pp, 'mey@example.com');

        $res = $this->actingAs($this->admin(), 'admin')->getJson('/admin/guests', self::JSON)->assertOk();

        $res->assertJsonPath('meta.total', 2);
        $dara = collect($res->json('guests'))->firstWhere('email', 'dara@example.com');
        $this->assertSame(3, $dara['reservations']);
        $this->assertSame(1, $dara['cancelled']);
        $this->assertSame(500.0, (float) $dara['reservedValue']);
        $this->assertSame(4, $dara['nights']);
        $this->assertSame('Dara S.', $dara['fullName']); // newest reservation wins
    }

    public function test_guest_search_and_paging(): void
    {
        $this->reservation($this->pp, 'dara@example.com');
        $this->reservation($this->pp, 'mey@example.com', ['guest_full_name' => 'Mey Chan']);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->getJson('/admin/guests?q=chan', self::JSON)
            ->assertOk()->assertJsonCount(1, 'guests')->assertJsonPath('guests.0.email', 'mey@example.com');

        $this->actingAs($admin, 'admin')->getJson('/admin/guests?perPage=1&page=2', self::JSON)
            ->assertOk()->assertJsonCount(1, 'guests')->assertJsonPath('meta.lastPage', 2);
    }

    public function test_manager_only_sees_guests_from_their_own_location(): void
    {
        $this->reservation($this->pp, 'dara@example.com');
        $this->reservation($this->kampot, 'far@example.com');

        $manager = $this->admin('manager');

        $this->actingAs($manager, 'admin')->getJson('/admin/guests', self::JSON)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('guests.0.email', 'dara@example.com');

        $this->actingAs($manager, 'admin')->getJson('/admin/guests/profile?email=far@example.com', self::JSON)->assertNotFound();
        $this->actingAs($manager, 'admin')->putJson('/admin/guests/note', ['email' => 'far@example.com', 'note' => 'x'], self::JSON)->assertNotFound();
    }

    public function test_profile_returns_history_and_note_can_be_saved_and_cleared(): void
    {
        $this->reservation($this->pp, 'dara@example.com', ['notes' => 'INTERNAL booking note']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->putJson('/admin/guests/note', ['email' => 'Dara@Example.com', 'note' => 'Prefers a high floor'], self::JSON)
            ->assertOk()->assertJsonPath('note', 'Prefers a high floor');

        $this->actingAs($admin, 'admin')->getJson('/admin/guests/profile?email=dara@example.com', self::JSON)
            ->assertOk()
            ->assertJsonPath('guest.note', 'Prefers a high floor')
            ->assertJsonCount(1, 'reservations');

        $this->actingAs($admin, 'admin')
            ->putJson('/admin/guests/note', ['email' => 'dara@example.com', 'note' => '  '], self::JSON)
            ->assertOk()->assertJsonPath('note', null);

        $this->assertDatabaseCount('guest_notes', 0);
    }

    public function test_guests_and_reports_need_their_feature(): void
    {
        $seat = $this->admin('admin', ['bookings']);

        $this->actingAs($seat, 'admin')->getJson('/admin/guests', self::JSON)->assertForbidden();
        $this->actingAs($seat, 'admin')->getJson('/admin/reports/summary', self::JSON)->assertForbidden();
        $this->actingAs($seat, 'admin')->getJson('/admin/reports/export', self::JSON)->assertForbidden();
    }

    // ---------- Reports ----------

    public function test_report_totals_and_breakdowns(): void
    {
        $this->reservation($this->pp, 'a@example.com', ['total' => 200, 'source' => 'website']);
        $this->reservation($this->pp, 'b@example.com', ['total' => 300, 'source' => 'admin', 'check_out' => '2026-12-04']);
        $this->reservation($this->kampot, 'c@example.com', ['total' => 100, 'source' => 'website']);
        $this->reservation($this->kampot, 'd@example.com', ['status' => 'cancelled', 'total' => 500]);

        $res = $this->actingAs($this->admin(), 'admin')->getJson('/admin/reports/summary', self::JSON)->assertOk();

        $res->assertJsonPath('totals.reservations', 4)
            ->assertJsonPath('totals.cancelled', 1)
            ->assertJsonPath('totals.nights', 7); // 2 + 3 + 2
        $this->assertEquals(600, $res->json('totals.value'));

        $byDest = collect($res->json('byDestination'))->keyBy('destination');
        $this->assertSame(2, $byDest['Phnom Penh']['reservations']);
        $this->assertEquals(500, $byDest['Phnom Penh']['value']);
        $this->assertEquals(100, $byDest['Kampot']['value']); // the cancelled 500 is excluded

        $bySource = collect($res->json('bySource'))->keyBy('source');
        $this->assertSame(2, $bySource['website']['reservations']);
        $this->assertSame(1, $bySource['admin']['reservations']);

        $this->assertSame(1, collect($res->json('byStatus'))->firstWhere('status', 'cancelled')['reservations']);
        $this->assertArrayNotHasKey('value', collect($res->json('byStatus'))->first());
    }

    public function test_report_filters_by_hotel_and_dates(): void
    {
        $this->reservation($this->pp, 'a@example.com', ['created_at' => '2026-10-01 09:00:00']);
        $this->reservation($this->pp, 'b@example.com', ['created_at' => '2026-11-10 09:00:00']);
        $this->reservation($this->kampot, 'c@example.com', ['created_at' => '2026-11-12 09:00:00']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->getJson('/admin/reports/summary?from=2026-11-01&to=2026-11-30', self::JSON)
            ->assertOk()->assertJsonPath('totals.reservations', 2);

        $this->actingAs($admin, 'admin')->getJson('/admin/reports/summary?hotelId='.$this->kampot->id, self::JSON)
            ->assertOk()->assertJsonPath('totals.reservations', 1);

        $this->actingAs($admin, 'admin')->getJson('/admin/reports/summary?locationId=1&status=confirmed', self::JSON)
            ->assertOk()->assertJsonPath('totals.reservations', 2);

        $this->actingAs($admin, 'admin')->getJson('/admin/reports/summary?from=2026-11-30&to=2026-11-01', self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_manager_report_is_limited_to_their_location(): void
    {
        $this->reservation($this->pp, 'a@example.com', ['total' => 200]);
        $this->reservation($this->kampot, 'c@example.com', ['total' => 700]);

        $res = $this->actingAs($this->admin('manager'), 'admin')->getJson('/admin/reports/summary', self::JSON)
            ->assertOk()->assertJsonPath('totals.reservations', 1);
        $this->assertEquals(200, $res->json('totals.value'));
    }

    public function test_csv_export_is_scoped_and_neutralises_formulas(): void
    {
        $this->reservation($this->pp, 'a@example.com', ['guest_full_name' => '=HYPERLINK("http://x")']);
        $this->reservation($this->kampot, 'far@example.com');

        $response = $this->actingAs($this->admin('manager'), 'admin')->get('/admin/reports/export', self::JSON);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Reference,Status,Source', $csv);
        $this->assertStringContainsString('a@example.com', $csv);
        $this->assertStringNotContainsString('far@example.com', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertStringNotContainsString('INTERNAL', $csv);
    }
}
