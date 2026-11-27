<?php

namespace Tests\Feature;

use App\Http\Resources\LocationResource;
use App\Models\Hotel;
use App\Models\Location;
use App\Services\LocationAmenitiesBackfill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationAmenitiesTest extends TestCase
{
    /**
     * The full migration set uses MySQL-only SQL and cannot run on the
     * phpunit sqlite :memory: DB, so only the tables this feature needs
     * are created here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('highlights')->nullable();
            $table->json('amenities')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('amenities')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function test_location_resource_exposes_amenities(): void
    {
        $location = new Location([
            'name' => 'Sihanoukville',
            'amenities' => ['Free WiFi', 'Gym', "Kid's Playground"],
        ]);

        $data = (new LocationResource($location))->toArray(Request::create('/'));

        $this->assertSame(['Free WiFi', 'Gym', "Kid's Playground"], $data['amenities']);
    }

    public function test_location_without_amenities_returns_an_empty_list(): void
    {
        $data = (new LocationResource(new Location(['name' => 'Kampot'])))->toArray(Request::create('/'));

        $this->assertSame([], $data['amenities']);
    }

    public function test_merge_copies_draft_hotel_amenities_in_order_and_dedupes(): void
    {
        $pp = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        Hotel::create([
            'location_id' => $pp->id,
            'name' => 'Greyon Phnom Penh',
            'slug' => 'greyon-phnom-penh',
            'status' => 'draft',
            'amenities' => [
                'Free WiFi',
                'Gym / Fitness Center',
                'Rooftop Outdoor Space',
                'Free Parking Space',
                '24/7 Security',
                'Fire Protection System',
                'Elevator',
            ],
        ]);
        Hotel::create([
            'location_id' => $pp->id,
            'name' => 'Later property',
            'slug' => 'later-property',
            'status' => 'published',
            'amenities' => ['free wifi', 'Elevator', '  ', 'Billiard'],
        ]);

        $this->assertSame([
            'Free WiFi',
            'Gym / Fitness Center',
            'Rooftop Outdoor Space',
            'Free Parking Space',
            '24/7 Security',
            'Fire Protection System',
            'Elevator',
            'Billiard',
        ], LocationAmenitiesBackfill::mergeHotelAmenities($pp));
    }

    public function test_merge_leaves_a_location_with_no_hotels_empty(): void
    {
        $kampot = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);

        $this->assertSame([], LocationAmenitiesBackfill::mergeHotelAmenities($kampot));
    }

    public function test_fill_missing_is_idempotent_and_never_overwrites_saved_lists(): void
    {
        $shv = Location::create(['name' => 'Sihanoukville', 'slug' => 'sihanoukville', 'status' => 'published']);
        $pp = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $kampot = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);
        $edited = Location::create([
            'name' => 'Edited',
            'slug' => 'edited',
            'status' => 'published',
            'amenities' => ['Keep me'],
        ]);
        $cleared = Location::create([
            'name' => 'Cleared',
            'slug' => 'cleared',
            'status' => 'published',
            'amenities' => [],
        ]);

        Hotel::create([
            'location_id' => $shv->id,
            'name' => 'Greyon SHV',
            'slug' => 'greyon-shv',
            'status' => 'published',
            'amenities' => ['Free WiFi', 'Gym', "Kid's Playground", 'Billiard', 'Table Tennis', 'Parking Space', 'BBQ Kitchen', 'Rooftop Outdoor Space', '24/7 Security'],
        ]);
        Hotel::create([
            'location_id' => $pp->id,
            'name' => 'Greyon PP',
            'slug' => 'greyon-pp',
            'status' => 'draft',
            'amenities' => ['Free WiFi', 'Elevator'],
        ]);
        Hotel::create([
            'location_id' => $edited->id,
            'name' => 'Should not land',
            'slug' => 'should-not-land',
            'status' => 'published',
            'amenities' => ['Invented'],
        ]);
        Hotel::create([
            'location_id' => $cleared->id,
            'name' => 'Also ignored',
            'slug' => 'also-ignored',
            'status' => 'published',
            'amenities' => ['Invented too'],
        ]);

        $this->assertSame(3, LocationAmenitiesBackfill::fillMissing());
        $this->assertSame(0, LocationAmenitiesBackfill::fillMissing());

        $this->assertSame([
            'Free WiFi',
            'Gym',
            "Kid's Playground",
            'Billiard',
            'Table Tennis',
            'Parking Space',
            'BBQ Kitchen',
            'Rooftop Outdoor Space',
            '24/7 Security',
        ], $shv->fresh()->amenities);
        $this->assertSame(['Free WiFi', 'Elevator'], $pp->fresh()->amenities);
        $this->assertSame([], $kampot->fresh()->amenities);
        $this->assertSame(['Keep me'], $edited->fresh()->amenities);
        $this->assertSame([], $cleared->fresh()->amenities);
    }
}
