<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Developer;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    // Full migration set is MySQL-only; build just the tables this feature touches.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000003_create_admins_table.php',
            '2026_09_15_000001_create_locations_table.php',
            '2026_09_30_000100_add_phone_and_email_to_locations_table.php',
            '2026_09_16_000002_add_created_by_admin_id_to_locations_table.php',
            '2026_09_15_000002_create_hotels_table.php',
            '2026_09_24_000100_add_map_embed_url_to_hotels_table.php',
            '2026_09_30_000200_add_property_type_and_star_rating_to_hotels_table.php',
            '2026_09_30_000400_add_area_to_hotels_table.php',
            '2026_09_30_000300_add_nearby_landmarks_to_hotels_table.php',
            '2026_09_15_000003_create_room_types_table.php',
            '2026_11_04_000100_add_panorama_path_to_room_types_table.php',
            '2026_11_20_000300_create_room_type_panoramas_table.php',
            '2026_09_15_000004_create_rate_plans_table.php',
            '2026_09_19_000001_create_ops_and_cms_tables.php',
            '2026_11_16_000300_create_booking_events_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    private function booking(array $over = []): Booking
    {
        $location = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $hotel = Hotel::create(['location_id' => $location->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published', 'address' => '1 River Rd']);
        $room = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Deluxe King', 'slug' => 'deluxe-king', 'status' => 'published']);
        $plan = RatePlan::create(['room_type_id' => $room->id, 'name' => 'Flexible', 'base_price' => 100, 'cancellation_policy' => 'Free until 48h', 'status' => 'published']);

        return Booking::create(array_merge([
            'reference' => 'GRY-ABC123',
            'hotel_id' => $hotel->id,
            'room_type_id' => $room->id,
            'rate_plan_id' => $plan->id,
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-04',
            'rooms' => 1, 'adults' => 2, 'children' => 0,
            'guest_full_name' => 'Dara Sok',
            'guest_email' => 'dara@example.com',
            'guest_phone' => '012345678',
            'subtotal' => 300, 'taxes_fees' => 30, 'total' => 330,
            'status' => 'pending',
            'source' => 'website',
            'notes' => 'INTERNAL: VIP, upgrade if possible',
        ], $over));
    }

    public function test_lookup_returns_the_reservation_with_receipt_and_timeline(): void
    {
        $booking = $this->booking();
        BookingEvent::record($booking, 'created', null, 'pending');
        BookingEvent::record($booking, 'status_changed', 'pending', 'confirmed');

        $this->postJson('/reservations/lookup', ['reference' => 'gry-abc123', 'email' => 'DARA@example.com'], self::JSON)
            ->assertOk()
            ->assertJsonPath('reservation.reference', 'GRY-ABC123')
            ->assertJsonPath('reservation.nights', 3)
            ->assertJsonPath('reservation.hotel.name', 'Greyon PP')
            ->assertJsonPath('reservation.ratePlan.cancellationPolicy', 'Free until 48h')
            ->assertJsonPath('reservation.pricing.total', 330)
            ->assertJsonCount(2, 'reservation.timeline')
            ->assertJsonPath('reservation.timeline.1.status', 'confirmed');
    }

    public function test_lookup_hides_internal_notes_and_staff_identity(): void
    {
        $booking = $this->booking();
        BookingEvent::record($booking, 'created', null, 'pending');

        $body = $this->postJson('/reservations/lookup', ['reference' => 'GRY-ABC123', 'email' => 'dara@example.com'], self::JSON)->getContent();

        $this->assertStringNotContainsString('INTERNAL', $body);
        $this->assertStringNotContainsString('actor', $body);
    }

    public function test_wrong_email_or_reference_look_identical(): void
    {
        $this->booking();

        $wrongEmail = $this->postJson('/reservations/lookup', ['reference' => 'GRY-ABC123', 'email' => 'other@example.com'], self::JSON);
        $wrongRef = $this->postJson('/reservations/lookup', ['reference' => 'GRY-ZZZZZZ', 'email' => 'dara@example.com'], self::JSON);

        $wrongEmail->assertNotFound();
        $wrongRef->assertNotFound();
        $this->assertSame($wrongEmail->json('message'), $wrongRef->json('message'));
    }

    public function test_lookup_validates_input(): void
    {
        $this->postJson('/reservations/lookup', ['reference' => 'GRY-ABC123'], self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_public_reference_endpoint_no_longer_leaks_internal_notes(): void
    {
        $this->booking();

        $response = $this->getJson('/bookings/GRY-ABC123', self::JSON)->assertOk();

        $this->assertStringNotContainsString('INTERNAL', $response->getContent());
        $response->assertJsonPath('booking.reference', 'GRY-ABC123');
    }

    public function test_events_record_who_did_it(): void
    {
        $booking = $this->booking();

        $system = BookingEvent::record($booking, 'created', null, 'pending');
        $this->assertSame('guest', $system->actor_type); // website booking, nobody signed in

        Auth::guard('developer')->setUser(Developer::factory()->make(['id' => 7]));
        $changed = BookingEvent::record($booking, 'status_changed', 'pending', 'confirmed');

        $this->assertSame('developer', $changed->actor_type);
        $this->assertSame(7, (int) $changed->actor_id);
        $this->assertSame('confirmed', $changed->to_status);
    }

    public function test_notification_service_records_the_timeline(): void
    {
        $source = file_get_contents(app_path('Services/BookingNotificationService.php'));

        $this->assertStringContainsString("BookingEvent::record(\$booking, 'created'", $source);
        $this->assertStringContainsString("BookingEvent::record(\$booking, 'status_changed'", $source);
    }
}
