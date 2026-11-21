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
use Carbon\Carbon;
use Tests\TestCase;

class AdminQuickReservationTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Hotel $pp;

    private Hotel $kampot;

    private RoomType $deluxe;

    private RatePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000000_create_users_table',
            '0001_01_01_000003_create_admins_table',
            '2026_09_14_000002_create_developers_table',
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
            '2026_11_20_000600_add_captions_and_hotspots_to_panoramas',
            '2026_09_15_000004_create_rate_plans_table',
            '2026_09_19_000001_create_ops_and_cms_tables',
            '2026_09_19_000002_add_user_id_to_bookings_table',
            '2026_09_20_000001_create_staff_notifications_table',
            '2026_11_21_000100_add_payment_method_to_bookings',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);

        Feature::create(['key' => 'bookings', 'label' => 'Reservations', 'category' => 'admin', 'sort_order' => 1]);

        $pp = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $kt = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);
        $this->pp = Hotel::create([
            'location_id' => $pp->id,
            'name' => 'Greyon PP',
            'slug' => 'greyon-pp',
            'status' => 'published',
            'address' => '1 River Rd',
        ]);
        $this->kampot = Hotel::create([
            'location_id' => $kt->id,
            'name' => 'Greyon Kampot',
            'slug' => 'greyon-kampot',
            'status' => 'published',
            'address' => '2 River Rd',
        ]);

        $this->deluxe = $this->room($this->pp, 'deluxe', 'Deluxe King', 80, 3, 10);
        $this->plan = $this->deluxe->ratePlans()->first();
        $this->room($this->pp, 'balcony', '1 Bedroom Balcony', 60, 0, 0);
        $this->room($this->kampot, 'kampot-deluxe', 'Kampot Deluxe', 70, 2, 0);
    }

    private function room(Hotel $hotel, string $slug, string $name, float $price, int $inventory, float $tax): RoomType
    {
        $room = RoomType::create([
            'hotel_id' => $hotel->id,
            'name' => $name,
            'slug' => $slug,
            'status' => 'published',
            'bed_type' => 'King',
            'room_size' => '40 sqm',
            'max_adults' => 2,
            'max_children' => 1,
            'max_guests' => 3,
            'base_inventory' => $inventory,
        ]);
        RatePlan::create([
            'room_type_id' => $room->id,
            'name' => 'Flexible',
            'base_price' => $price,
            'tax_percent' => $tax,
            'service_fee_percent' => 0,
            'cancellation_policy' => 'Free until 48h',
            'status' => 'published',
        ]);

        return $room->load('ratePlans');
    }

    private function staff(string $role = 'admin', ?int $locationId = null): Admin
    {
        $package = Package::create(['name' => "Seat {$role} ".uniqid(), 'is_system' => true]);
        $package->roles()->sync([Role::where('name', $role)->value('id')]);
        $package->features()->sync(Feature::where('key', 'bookings')->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => [
            'location_ids' => $role === 'manager' ? [$locationId ?? $this->pp->location_id] : null,
            'hotel_ids' => null,
        ]]);

        return $admin;
    }

    private function stay(): array
    {
        $in = Carbon::today()->addDays(14)->format('Y-m-d');
        $out = Carbon::today()->addDays(15)->format('Y-m-d');

        return ['checkIn' => $in, 'checkOut' => $out, 'guests' => 2];
    }

    public function test_availability_lists_price_and_includes_sold_out_rooms(): void
    {
        $stay = $this->stay();

        $res = $this->actingAs($this->staff(), 'admin')
            ->getJson('/admin/reservations/quick/availability?'.http_build_query([
                'hotelId' => $this->pp->id,
                ...$stay,
            ]), self::JSON)
            ->assertOk();

        $rooms = collect($res->json('roomTypes'));
        $deluxe = $rooms->firstWhere('roomTypeId', $this->deluxe->id);
        $balcony = $rooms->firstWhere('name', '1 Bedroom Balcony');

        $this->assertNotNull($deluxe);
        $this->assertFalse($deluxe['soldOut']);
        $this->assertSame(3, $deluxe['availableUnits']);
        $this->assertEquals(80.0, $deluxe['nightly']);
        $this->assertEquals(80.0, $deluxe['subtotal']);
        $this->assertEquals(8.0, $deluxe['taxesFees']);
        $this->assertEquals(88.0, $deluxe['total']);
        $this->assertSame(1, $deluxe['nights']);

        $this->assertNotNull($balcony);
        $this->assertTrue($balcony['soldOut']);
        $this->assertSame(0, $balcony['availableUnits']);
        $this->assertEquals(60.0, $balcony['nightly']);
    }

    public function test_quick_book_creates_a_confirmed_paid_reservation_and_ignores_client_total(): void
    {
        $stay = $this->stay();

        $res = $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/reservations/quick', [
                'roomTypeId' => $this->deluxe->id,
                ...$stay,
                'guestFullName' => 'Dara Sok',
                'guestPhone' => '012345678',
                'guestEmail' => 'dara@example.com',
                'paymentMethod' => 'cash',
                'total' => 1,
            ], self::JSON)
            ->assertCreated();

        $res->assertJsonPath('booking.status', 'confirmed')
            ->assertJsonPath('booking.source', 'admin')
            ->assertJsonPath('booking.paymentMethod', 'cash')
            ->assertJsonPath('booking.guestFullName', 'Dara Sok')
            ->assertJsonPath('booking.total', 88);

        $this->assertNotEmpty($res->json('booking.reference'));
        $this->assertStringStartsWith('GRY-', $res->json('booking.reference'));
        $this->assertNotEmpty($res->json('booking.paidAt'));

        $row = Booking::query()->where('reference', $res->json('booking.reference'))->first();
        $this->assertSame('confirmed', $row->status);
        $this->assertSame('cash', $row->payment_method);
        $this->assertEquals(88.0, (float) $row->total);
        $this->assertEquals(80.0, (float) $row->subtotal);
        $this->assertEquals(8.0, (float) $row->taxes_fees);
        $this->assertNotNull($row->paid_at);
        $this->assertSame((int) $this->plan->id, (int) $row->rate_plan_id);
    }

    public function test_quick_book_allows_optional_email_and_records_aba_qr(): void
    {
        $stay = $this->stay();

        $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/reservations/quick', [
                'roomTypeId' => $this->deluxe->id,
                ...$stay,
                'guestFullName' => 'Mey Chan',
                'guestPhone' => '011222333',
                'paymentMethod' => 'aba_qr',
            ], self::JSON)
            ->assertCreated()
            ->assertJsonPath('booking.paymentMethod', 'aba_qr')
            ->assertJsonPath('booking.guestEmail', '')
            ->assertJsonPath('booking.guestFullName', 'Mey Chan');
    }

    public function test_sold_out_room_is_rejected(): void
    {
        $stay = $this->stay();
        $balcony = RoomType::query()->where('slug', 'balcony')->first();

        $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/reservations/quick', [
                'roomTypeId' => $balcony->id,
                ...$stay,
                'guestFullName' => 'Dara Sok',
                'guestPhone' => '012345678',
                'paymentMethod' => 'card',
            ], self::JSON)
            ->assertStatus(409);
    }

    public function test_manager_cannot_quick_book_outside_their_location(): void
    {
        $stay = $this->stay();
        $manager = $this->staff('manager', $this->pp->location_id);
        $kampotRoom = RoomType::query()->where('slug', 'kampot-deluxe')->first();

        $this->actingAs($manager, 'admin')
            ->getJson('/admin/reservations/quick/availability?'.http_build_query([
                'hotelId' => $this->kampot->id,
                ...$stay,
            ]), self::JSON)
            ->assertNotFound();

        $this->actingAs($manager, 'admin')
            ->postJson('/admin/reservations/quick', [
                'roomTypeId' => $kampotRoom->id,
                ...$stay,
                'guestFullName' => 'Dara Sok',
                'guestPhone' => '012345678',
                'paymentMethod' => 'cash',
            ], self::JSON)
            ->assertNotFound();
    }

    public function test_manager_can_quick_book_in_their_location(): void
    {
        $stay = $this->stay();
        $manager = $this->staff('manager', $this->pp->location_id);

        $this->actingAs($manager, 'admin')
            ->postJson('/admin/reservations/quick', [
                'roomTypeId' => $this->deluxe->id,
                ...$stay,
                'guestFullName' => 'Dara Sok',
                'guestPhone' => '012345678',
                'paymentMethod' => 'card',
            ], self::JSON)
            ->assertCreated()
            ->assertJsonPath('booking.paymentMethod', 'card')
            ->assertJsonPath('booking.hotelId', $this->pp->id);
    }
}
