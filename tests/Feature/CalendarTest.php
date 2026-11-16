<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use App\Models\RateCalendar;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Hotel $pp;

    private Hotel $kampot;

    private RoomType $ppRoom;

    private RoomType $ktRoom;

    private RatePlan $ppPlan;

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
            '2026_11_20_000100_add_actions_to_package_feature_table',
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
            '2026_09_15_000004_create_rate_plans_table',
            '2026_09_19_000001_create_ops_and_cms_tables',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        foreach (['calendar', 'reports'] as $i => $key) {
            Feature::create(['key' => $key, 'label' => ucfirst($key), 'category' => 'admin', 'sort_order' => $i]);
        }

        $pp = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $kt = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);
        $this->pp = Hotel::create(['location_id' => $pp->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published']);
        $this->kampot = Hotel::create(['location_id' => $kt->id, 'name' => 'Greyon Kampot', 'slug' => 'greyon-kampot', 'status' => 'published']);

        $this->ppRoom = RoomType::create(['hotel_id' => $this->pp->id, 'name' => 'Deluxe', 'slug' => 'deluxe', 'status' => 'published', 'base_inventory' => 5]);
        $this->ktRoom = RoomType::create(['hotel_id' => $this->kampot->id, 'name' => 'Bungalow', 'slug' => 'bungalow', 'status' => 'published', 'base_inventory' => 3]);
        $this->ppPlan = RatePlan::create(['room_type_id' => $this->ppRoom->id, 'name' => 'Flexible', 'base_price' => 100, 'status' => 'published']);
    }

    private function admin(string $role = 'admin', array $features = ['calendar', 'reports']): Admin
    {
        $package = Package::create(['name' => "Seat {$role} ".uniqid(), 'is_system' => true]);
        $package->roles()->sync([Role::where('name', $role)->value('id')]);
        $package->features()->sync(Feature::whereIn('key', $features)->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => $role === 'manager' ? [1] : null, 'hotel_ids' => null]]);

        return $admin;
    }

    private function reserve(RoomType $room, string $in, string $out, int $rooms = 1, string $status = 'confirmed'): Booking
    {
        static $n = 0;
        $n++;

        return Booking::create([
            'reference' => sprintf('GRY-C%05d', $n),
            'hotel_id' => $room->hotel_id, 'room_type_id' => $room->id,
            'rate_plan_id' => RatePlan::firstOrCreate(['room_type_id' => $room->id, 'name' => 'Flexible'], ['base_price' => 100, 'status' => 'published'])->id,
            'check_in' => $in, 'check_out' => $out, 'rooms' => $rooms, 'adults' => 2, 'children' => 0,
            'guest_full_name' => 'Dara Sok', 'guest_email' => 'dara@example.com', 'guest_phone' => '012345678',
            'subtotal' => 100, 'taxes_fees' => 0, 'total' => 100, 'status' => $status, 'source' => 'website',
        ]);
    }

    private function day(array $room, string $date): array
    {
        return collect($room['days'])->firstWhere('date', $date);
    }

    public function test_month_grid_shows_units_bookings_stop_sell_and_prices(): void
    {
        $this->reserve($this->ppRoom, '2026-12-03', '2026-12-06', 2);          // nights 3,4,5
        $this->reserve($this->ppRoom, '2026-12-04', '2026-12-05', 1, 'cancelled'); // ignored
        Availability::create(['room_type_id' => $this->ppRoom->id, 'date' => '2026-12-10', 'available_units' => 2, 'stop_sell' => true]);
        RateCalendar::create(['rate_plan_id' => $this->ppPlan->id, 'date' => '2026-12-24', 'price' => 180, 'min_stay' => 2, 'max_stay' => 7]);

        $res = $this->actingAs($this->admin(), 'admin')->getJson('/admin/calendar?month=2026-12&hotelId='.$this->pp->id, self::JSON)->assertOk();
        $res->assertJsonPath('from', '2026-12-01')->assertJsonPath('to', '2026-12-31')->assertJsonCount(1, 'roomTypes');

        $room = $res->json('roomTypes.0');
        $this->assertCount(31, $room['days']);

        $this->assertSame(['units' => 5, 'booked' => 0, 'free' => 5], collect($this->day($room, '2026-12-02'))->only(['units', 'booked', 'free'])->all());
        $this->assertSame(2, $this->day($room, '2026-12-03')['booked']);
        $this->assertSame(3, $this->day($room, '2026-12-05')['free']);
        $this->assertSame(0, $this->day($room, '2026-12-06')['booked']); // check-out day is free again
        $ten = $this->day($room, '2026-12-10');
        $this->assertTrue($ten['stopSell']);
        $this->assertTrue($ten['overridden']);
        $this->assertSame(2, $ten['units']);

        $plan = $room['ratePlans'][0];
        $this->assertEquals(100, $this->day($plan, '2026-12-02')['price']);
        $this->assertFalse($this->day($plan, '2026-12-02')['overridden']);
        $xmas = $this->day($plan, '2026-12-24');
        $this->assertEquals(180, $xmas['price']);
        $this->assertSame(2, $xmas['minStay']);
        $this->assertSame(7, $xmas['maxStay']);
    }

    public function test_manager_only_sees_and_edits_their_own_hotels(): void
    {
        $manager = $this->admin('manager');

        $res = $this->actingAs($manager, 'admin')->getJson('/admin/calendar?month=2026-12', self::JSON)->assertOk();
        $this->assertSame(['Deluxe'], collect($res->json('roomTypes'))->pluck('name')->all());

        $this->actingAs($manager, 'admin')->putJson('/admin/calendar/bulk', [
            'roomTypeId' => $this->ktRoom->id, 'from' => '2026-12-01', 'to' => '2026-12-02', 'stopSell' => true,
        ], self::JSON)->assertNotFound();
        $this->assertDatabaseCount('availabilities', 0);
    }

    public function test_calendar_needs_its_feature(): void
    {
        $seat = $this->admin('admin', ['reports']);

        $this->actingAs($seat, 'admin')->getJson('/admin/calendar', self::JSON)->assertForbidden();
        $this->actingAs($seat, 'admin')->putJson('/admin/calendar/bulk', [], self::JSON)->assertForbidden();
    }

    public function test_bulk_stop_sell_and_units_for_selected_weekdays_is_repeatable(): void
    {
        $admin = $this->admin();
        // 2026-12-05 is a Saturday (6), 2026-12-06 a Sunday (0).
        $payload = ['roomTypeId' => $this->ppRoom->id, 'from' => '2026-12-01', 'to' => '2026-12-07', 'weekdays' => [6, 0], 'stopSell' => true];

        $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', $payload, self::JSON)
            ->assertOk()->assertJsonPath('dates', 2)->assertJsonPath('updatedDays.availability', 2);

        // Second run updates the same rows (no duplicates) and can set units too.
        $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', $payload + ['availableUnits' => 1], self::JSON)->assertOk();

        $this->assertSame(2, Availability::count());
        $row = Availability::orderBy('date')->first();
        $this->assertSame(1, $row->available_units);
        $this->assertTrue($row->stop_sell);

        // Units only: stop-sell is kept as it was.
        $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', [
            'roomTypeId' => $this->ppRoom->id, 'from' => '2026-12-05', 'to' => '2026-12-05', 'availableUnits' => 4,
        ], self::JSON)->assertOk();
        $this->assertTrue((bool) Availability::orderBy('date')->first()->stop_sell);
        $this->assertSame(4, Availability::orderBy('date')->first()->available_units);
    }

    public function test_bulk_new_rows_start_from_the_room_base_inventory(): void
    {
        $this->actingAs($this->admin(), 'admin')->putJson('/admin/calendar/bulk', [
            'roomTypeId' => $this->ppRoom->id, 'from' => '2026-12-01', 'to' => '2026-12-01', 'stopSell' => true,
        ], self::JSON)->assertOk();

        $this->assertSame(5, Availability::first()->available_units);
    }

    public function test_bulk_prices_and_stay_rules(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', [
            'roomTypeId' => $this->ppRoom->id, 'ratePlanId' => $this->ppPlan->id,
            'from' => '2026-12-20', 'to' => '2026-12-22', 'price' => 150, 'minStay' => 2,
        ], self::JSON)->assertOk()->assertJsonPath('updatedDays.rates', 3);

        $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', [
            'roomTypeId' => $this->ppRoom->id, 'ratePlanId' => $this->ppPlan->id,
            'from' => '2026-12-21', 'to' => '2026-12-21', 'maxStay' => 5,
        ], self::JSON)->assertOk();

        $this->assertSame(3, RateCalendar::count());
        $mid = RateCalendar::orderBy('date')->get()[1];
        $this->assertEquals(150, $mid->price); // price kept when only maxStay changes
        $this->assertSame(2, $mid->min_stay);
        $this->assertSame(5, $mid->max_stay);

        // Clearing a stay rule with null.
        $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', [
            'roomTypeId' => $this->ppRoom->id, 'ratePlanId' => $this->ppPlan->id,
            'from' => '2026-12-21', 'to' => '2026-12-21', 'maxStay' => null,
        ], self::JSON)->assertOk();
        $this->assertNull(RateCalendar::orderBy('date')->get()[1]->max_stay);
    }

    public function test_bulk_validation(): void
    {
        $admin = $this->admin();
        $other = RatePlan::create(['room_type_id' => $this->ktRoom->id, 'name' => 'Other', 'base_price' => 50, 'status' => 'published']);
        $base = ['roomTypeId' => $this->ppRoom->id, 'from' => '2026-12-01', 'to' => '2026-12-02'];

        $call = fn (array $extra) => $this->actingAs($admin, 'admin')->putJson('/admin/calendar/bulk', array_merge($base, $extra), self::JSON);

        $call([])->assertStatus(422);                                                       // nothing to change
        $call(['price' => 10])->assertStatus(422)->assertJsonValidationErrors('ratePlanId'); // plan required
        $call(['price' => 10, 'ratePlanId' => $other->id])->assertStatus(422)->assertJsonValidationErrors('ratePlanId'); // plan of another room
        $call(['ratePlanId' => $this->ppPlan->id, 'minStay' => 5, 'maxStay' => 2])->assertStatus(422)->assertJsonValidationErrors('maxStay');
        $call(['stopSell' => true, 'weekdays' => [9]])->assertStatus(422);
        $call(['stopSell' => true, 'to' => '2028-01-01'])->assertStatus(422)->assertJsonValidationErrors('to');
        $call(['stopSell' => true, 'from' => '2026-12-05', 'to' => '2026-12-01'])->assertStatus(422);

        $this->assertDatabaseCount('availabilities', 0);
        $this->assertDatabaseCount('rate_calendars', 0);
    }

    public function test_report_occupancy_uses_inventory_and_overrides(): void
    {
        // PP: 5 units/night. Range 4 nights (1-4 Dec) = 20 room-nights, one override of 1 unit on the 2nd = 16.
        Availability::create(['room_type_id' => $this->ppRoom->id, 'date' => '2026-12-02', 'available_units' => 1, 'stop_sell' => false]);
        $this->reserve($this->ppRoom, '2026-12-01', '2026-12-03', 1);                // nights 1,2 -> 2 room-nights
        $this->reserve($this->ppRoom, '2026-11-30', '2026-12-02', 2);                // night 1 inside range -> 2
        $this->reserve($this->ppRoom, '2026-12-01', '2026-12-02', 4, 'cancelled');   // ignored

        $res = $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/reports/summary?from=2026-12-01&to=2026-12-04&hotelId='.$this->pp->id, self::JSON)->assertOk();

        $res->assertJsonPath('occupancy.availableRoomNights', 16)
            ->assertJsonPath('occupancy.bookedRoomNights', 4)
            ->assertJsonPath('occupancy.rate', 25)
            ->assertJsonPath('occupancy.from', '2026-12-01')
            ->assertJsonPath('occupancy.to', '2026-12-04')
            ->assertJsonPath('occupancy.byHotel.0.hotel', 'Greyon PP');

        // All hotels in scope for an admin: PP 20-4=16 plus Kampot 3 x 4 = 12.
        $all = $this->actingAs($this->admin(), 'admin')->getJson('/admin/reports/summary?from=2026-12-01&to=2026-12-04', self::JSON)->assertOk();
        $all->assertJsonPath('occupancy.availableRoomNights', 28)->assertJsonCount(2, 'occupancy.byHotel');

        // A manager only counts their own hotel.
        $this->actingAs($this->admin('manager'), 'admin')->getJson('/admin/reports/summary?from=2026-12-01&to=2026-12-04', self::JSON)
            ->assertOk()->assertJsonPath('occupancy.availableRoomNights', 16)->assertJsonCount(1, 'occupancy.byHotel');
    }

    public function test_report_occupancy_needs_a_date_range(): void
    {
        $this->actingAs($this->admin(), 'admin')->getJson('/admin/reports/summary', self::JSON)
            ->assertOk()->assertJsonPath('occupancy', null);
    }
}
