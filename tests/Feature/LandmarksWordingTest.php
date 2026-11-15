<?php

namespace Tests\Feature;

use App\Http\Resources\LocationResource;
use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Tests\TestCase;

class LandmarksWordingTest extends TestCase
{
    public function test_location_exposes_cleaned_landmarks(): void
    {
        $location = new Location(['name' => 'Phnom Penh', 'landmarks' => [
            ['place' => 'Royal Palace', 'distance' => '1.2 km'],
            ['place' => '', 'distance' => '5 km'],      // blank place is dropped
            ['place' => 'Central Market'],               // missing distance becomes ''
            'junk',
        ]]);

        $data = (new LocationResource($location))->toArray(Request::create('/'));

        $this->assertSame([
            ['place' => 'Royal Palace', 'distance' => '1.2 km'],
            ['place' => 'Central Market', 'distance' => ''],
        ], $data['landmarks']);
    }

    public function test_location_without_landmarks_returns_an_empty_list(): void
    {
        $data = (new LocationResource(new Location(['name' => 'Kampot'])))->toArray(Request::create('/'));

        $this->assertSame([], $data['landmarks']);
    }

    public function test_room_type_carries_the_hotel_name_when_the_hotel_is_loaded(): void
    {
        $room = (new RoomType(['name' => 'Deluxe King', 'hotel_id' => 3]))
            ->setRelation('hotel', new Hotel(['name' => 'Riverside', 'slug' => 'riverside']));

        $data = (new RoomTypeResource($room))->toArray(Request::create('/'));

        $this->assertSame('Riverside', $data['hotelName']);
        $this->assertSame('Riverside', $data['hotel']['name']);
    }

    public function test_notification_titles_say_reservation(): void
    {
        $source = file_get_contents(app_path('Services/BookingNotificationService.php'));

        $this->assertStringContainsString("title: 'New reservation'", $source);
        $this->assertStringContainsString("title: 'Reservation '.\$to", $source);
        $this->assertStringNotContainsString("'New booking'", $source);
    }
}
