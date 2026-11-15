<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Offer;
use App\Models\Package;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use Tests\TestCase;

class OfferTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Hotel $pp;

    private Hotel $kampot;

    private Location $ppLoc;

    private Location $ktLoc;

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
            '2026_09_19_000002_add_user_id_to_bookings_table',
            '2026_09_20_000001_create_staff_notifications_table',
            '2026_11_16_000300_create_booking_events_table',
            '2026_11_17_000400_create_offers_table',
            '2026_11_17_000500_add_offer_columns_to_bookings_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        Feature::create(['key' => 'offers', 'label' => 'Offers', 'category' => 'admin', 'sort_order' => 0]);
        Feature::create(['key' => 'bookings', 'label' => 'Reservations', 'category' => 'admin', 'sort_order' => 1]);

        $this->ppLoc = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $this->ktLoc = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);
        $this->pp = Hotel::create(['location_id' => $this->ppLoc->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published']);
        $this->kampot = Hotel::create(['location_id' => $this->ktLoc->id, 'name' => 'Greyon Kampot', 'slug' => 'greyon-kampot', 'status' => 'published']);

        // Phnom Penh: 100 per night, 10% tax. 3 nights = 300 + 30 tax.
        $room = RoomType::create(['hotel_id' => $this->pp->id, 'name' => 'Deluxe', 'slug' => 'deluxe', 'status' => 'published', 'base_inventory' => 5, 'max_adults' => 2, 'max_children' => 0, 'max_guests' => 2]);
        RatePlan::create(['room_type_id' => $room->id, 'name' => 'Flexible', 'base_price' => 100, 'tax_percent' => 10, 'status' => 'published']);
    }

    private function admin(string $role = 'admin', array $features = ['offers']): Admin
    {
        $package = Package::create(['name' => "Seat {$role} ".uniqid(), 'is_system' => true]);
        $package->roles()->sync([Role::where('name', $role)->value('id')]);
        $package->features()->sync(Feature::whereIn('key', $features)->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => $role === 'manager' ? [$this->ppLoc->id] : null, 'hotel_ids' => null]]);

        return $admin;
    }

    private function offer(array $over = []): Offer
    {
        return Offer::create(array_merge([
            'slug' => 'o-'.uniqid(), 'title' => 'Early bird',
            'discount_type' => 'percent', 'discount_value' => 10, 'status' => 'published',
        ], $over));
    }

    private function search(array $q = [])
    {
        return $this->getJson('/availability?'.http_build_query(array_merge([
            'hotelSlug' => 'greyon-pp', 'checkIn' => '2026-12-10', 'checkOut' => '2026-12-13',
            'rooms' => 1, 'adults' => 2, 'children' => 0,
        ], $q)), self::JSON)->assertOk();
    }

    private function row($response): array
    {
        return $response->json('results.0');
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->format('Y-m-d');
    }

    // ---------- Admin ----------

    public function test_admin_creates_updates_and_deletes_an_offer(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->postJson('/admin/offers', [
            'title' => 'Early bird', 'discountType' => 'percent', 'discountValue' => 10,
            'hotelId' => $this->pp->id, 'code' => 'summer', 'minNights' => 2, 'status' => 'published',
            'bookFrom' => '2026-01-01', 'bookTo' => '2099-12-31',
        ], self::JSON)->assertCreated();

        $res->assertJsonPath('offer.slug', 'early-bird')
            ->assertJsonPath('offer.code', 'SUMMER')
            ->assertJsonPath('offer.hotelName', 'Greyon PP')
            ->assertJsonPath('offer.live', true);
        $id = $res->json('offer.id');

        // Same title again: the slug is made unique.
        $this->actingAs($admin, 'admin')->postJson('/admin/offers', [
            'title' => 'Early bird', 'discountType' => 'fixed', 'discountValue' => 25, 'hotelId' => $this->pp->id,
        ], self::JSON)->assertCreated()->assertJsonPath('offer.slug', 'early-bird-2')->assertJsonPath('offer.status', 'draft');

        $patched = $this->actingAs($admin, 'admin')->patchJson("/admin/offers/$id", ['discountValue' => 15, 'status' => 'draft'], self::JSON)
            ->assertOk()->assertJsonPath('offer.status', 'draft')->assertJsonPath('offer.live', false);
        $this->assertEquals(15, $patched->json('offer.discountValue'));

        $this->actingAs($admin, 'admin')->getJson('/admin/offers', self::JSON)->assertOk()->assertJsonCount(2, 'offers');
        $this->actingAs($admin, 'admin')->getJson('/admin/offers?status=published', self::JSON)->assertOk()->assertJsonCount(0, 'offers');

        $this->actingAs($admin, 'admin')->deleteJson("/admin/offers/$id", [], self::JSON)->assertOk();
        $this->assertDatabaseMissing('offers', ['id' => $id]);
    }

    public function test_admin_validation(): void
    {
        $admin = $this->admin();
        $ok = ['title' => 'X', 'discountType' => 'percent', 'discountValue' => 10, 'hotelId' => $this->pp->id];
        $post = fn (array $extra) => $this->actingAs($admin, 'admin')->postJson('/admin/offers', array_merge($ok, $extra), self::JSON);

        $post(['discountValue' => 150])->assertStatus(422)->assertJsonValidationErrors('discountValue');
        $post(['discountValue' => 0])->assertStatus(422)->assertJsonValidationErrors('discountValue');
        $post(['discountType' => 'free'])->assertStatus(422)->assertJsonValidationErrors('discountType');
        $post(['code' => 'a b'])->assertStatus(422)->assertJsonValidationErrors('code');
        $post(['hotelId' => $this->pp->id, 'locationId' => $this->ktLoc->id])->assertStatus(422)->assertJsonValidationErrors('locationId');
        $post(['bookFrom' => '2026-12-10', 'bookTo' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('bookTo');
        $post(['minNights' => 0])->assertStatus(422)->assertJsonValidationErrors('minNights');

        $post(['code' => 'DUPE'])->assertCreated();
        $post(['code' => 'dupe'])->assertStatus(422)->assertJsonValidationErrors('code');

        // Switching an existing fixed offer to percent re-checks the value.
        $fixed = $this->offer(['discount_type' => 'fixed', 'discount_value' => 500, 'hotel_id' => $this->pp->id]);
        $this->actingAs($admin, 'admin')->patchJson("/admin/offers/{$fixed->id}", ['discountType' => 'percent'], self::JSON)
            ->assertStatus(422)->assertJsonValidationErrors('discountValue');
    }

    public function test_scoped_seat_only_handles_its_own_scope(): void
    {
        $mine = $this->offer(['hotel_id' => $this->pp->id, 'title' => 'Mine']);
        $location = $this->offer(['location_id' => $this->ppLoc->id, 'title' => 'My destination']);
        $theirs = $this->offer(['hotel_id' => $this->kampot->id, 'title' => 'Theirs']);
        $everywhere = $this->offer(['title' => 'Everywhere']);

        $manager = $this->admin('manager');
        $api = fn () => $this->actingAs($manager, 'admin');

        $titles = collect($api()->getJson('/admin/offers', self::JSON)->assertOk()->json('offers'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Mine', 'My destination'], $titles);

        $api()->getJson("/admin/offers/{$theirs->id}", self::JSON)->assertNotFound();
        $api()->getJson("/admin/offers/{$everywhere->id}", self::JSON)->assertNotFound();
        $api()->getJson("/admin/offers/{$mine->id}", self::JSON)->assertOk();
        $api()->deleteJson("/admin/offers/{$theirs->id}", [], self::JSON)->assertNotFound();

        $new = ['title' => 'New', 'discountType' => 'percent', 'discountValue' => 5];
        $api()->postJson('/admin/offers', $new + ['hotelId' => $this->kampot->id], self::JSON)->assertNotFound();
        $api()->postJson('/admin/offers', $new + ['locationId' => $this->ktLoc->id], self::JSON)->assertNotFound();
        $api()->postJson('/admin/offers', $new, self::JSON)->assertForbidden(); // everywhere = global seat only
        $api()->postJson('/admin/offers', $new + ['hotelId' => $this->pp->id], self::JSON)->assertCreated();

        // Cannot move one of its own offers out of scope.
        $api()->patchJson("/admin/offers/{$mine->id}", ['hotelId' => $this->kampot->id], self::JSON)->assertNotFound();
        $this->assertSame($this->pp->id, $mine->fresh()->hotel_id);
    }

    public function test_offers_need_their_feature(): void
    {
        $seat = $this->admin('admin', ['bookings']);

        $this->actingAs($seat, 'admin')->getJson('/admin/offers', self::JSON)->assertForbidden();
        $this->actingAs($seat, 'admin')->postJson('/admin/offers', [], self::JSON)->assertForbidden();
    }

    // ---------- Quote time ----------

    public function test_without_an_offer_the_quote_is_unchanged(): void
    {
        $row = $this->row($this->search());

        $this->assertEquals(300, $row['subtotal']);
        $this->assertEquals(0, $row['discount']);
        $this->assertNull($row['offer']);
        $this->assertEquals(30, $row['taxesFees']);
        $this->assertEquals(330, $row['total']);
    }

    public function test_automatic_percent_offer_discounts_the_room_price_before_taxes(): void
    {
        $this->offer(['title' => 'Ten off', 'discount_value' => 10]);

        $row = $this->row($this->search());

        $this->assertEquals(300, $row['subtotal']);   // price before the discount
        $this->assertEquals(30, $row['discount']);
        $this->assertSame('Ten off', $row['offer']['title']);
        $this->assertEquals(27, $row['taxesFees']);   // 10% of 270
        $this->assertEquals(297, $row['total']);
    }

    public function test_the_biggest_discount_wins_and_fixed_is_capped(): void
    {
        $this->offer(['title' => 'Ten percent', 'discount_value' => 10]);
        $this->offer(['title' => 'Fifty off', 'discount_type' => 'fixed', 'discount_value' => 50]);

        $row = $this->row($this->search());
        $this->assertSame('Fifty off', $row['offer']['title']);
        $this->assertEquals(50, $row['discount']);
        $this->assertEquals(275, $row['total']); // 250 + 25

        Offer::query()->delete();
        $this->offer(['discount_type' => 'fixed', 'discount_value' => 5000]);
        $row = $this->row($this->search());
        $this->assertEquals(300, $row['discount']);
        $this->assertEquals(0, $row['total']);
    }

    public function test_offer_conditions_decide_whether_it_applies(): void
    {
        $applies = fn (array $q = []) => $this->row($this->search($q))['offer'] !== null;

        $this->offer(['min_nights' => 4]);
        $this->assertFalse($applies());                                    // 3 nights
        $this->assertTrue($applies(['checkOut' => '2026-12-14']));         // 4 nights
        Offer::query()->delete();

        $this->offer(['stay_from' => '2026-12-11']);
        $this->assertFalse($applies());                                    // arrives 10th
        Offer::query()->delete();

        $this->offer(['stay_to' => '2026-12-12']);
        $this->assertTrue($applies());                                     // last night is the 12th
        Offer::query()->delete();
        $this->offer(['stay_to' => '2026-12-11']);
        $this->assertFalse($applies());
        Offer::query()->delete();

        $this->offer(['book_to' => $this->day(-1)]);
        $this->assertFalse($applies());                                    // booking window closed
        Offer::query()->delete();

        $this->offer(['hotel_id' => $this->kampot->id]);
        $this->assertFalse($applies());                                    // another hotel
        Offer::query()->delete();

        $this->offer(['location_id' => $this->ppLoc->id]);
        $this->assertTrue($applies());                                     // this hotel's destination
        Offer::query()->delete();

        $this->offer(['status' => 'draft']);
        $this->assertFalse($applies());
    }

    // ---------- Reservation ----------

    public function test_a_reservation_keeps_its_discount_even_if_the_offer_is_deleted(): void
    {
        $offer = $this->offer(['title' => 'Summer', 'discount_value' => 10]);
        $room = RoomType::first();
        $plan = RatePlan::first();

        $res = $this->postJson('/bookings', [
            'hotelId' => $this->pp->id, 'roomTypeId' => $room->id, 'ratePlanId' => $plan->id,
            'checkIn' => '2026-12-10', 'checkOut' => '2026-12-13', 'rooms' => 1, 'adults' => 2, 'children' => 0,
            'guestFullName' => 'Dara Sok', 'guestEmail' => 'Dara@Example.com', 'guestPhone' => '012345678',
        ], self::JSON)->assertCreated();

        $this->assertEquals(297, $res->json('booking.total'));
        $this->assertEquals(30, $res->json('booking.discountTotal'));
        $this->assertSame('Summer', $res->json('booking.offerTitle'));
        $reference = $res->json('booking.reference');
        $this->assertDatabaseHas('bookings', ['reference' => $reference, 'offer_id' => $offer->id]);

        $offer->delete();

        // The reservation keeps its own copy of the discount.
        $this->assertDatabaseHas('bookings', ['reference' => $reference, 'offer_title' => 'Summer']);
        $this->assertEquals(30, Booking::where('reference', $reference)->value('discount_total'));
    }

    public function test_a_reservation_without_an_offer_has_no_discount(): void
    {
        $res = $this->postJson('/bookings', [
            'hotelId' => $this->pp->id, 'roomTypeId' => RoomType::first()->id, 'ratePlanId' => RatePlan::first()->id,
            'checkIn' => '2026-12-10', 'checkOut' => '2026-12-13', 'rooms' => 1, 'adults' => 2, 'children' => 0,
            'guestFullName' => 'Dara Sok', 'guestEmail' => 'dara@example.com', 'guestPhone' => '012345678',
        ], self::JSON)->assertCreated()
            ->assertJsonPath('booking.offerTitle', null);
        $this->assertEquals(0, $res->json('booking.discountTotal'));
    }
}
