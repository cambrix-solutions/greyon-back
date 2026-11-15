<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\HotelResource;
use App\Http\Resources\RatePlanResource;
use App\Http\Resources\RoomTypeResource;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public availability search — BookingService::search.
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locationSlug' => ['nullable', 'string', 'max:255'],
            'hotelSlug' => ['nullable', 'string', 'max:255'],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d'],
            'rooms' => ['required', 'integer', 'min:1'],
            'adults' => ['required', 'integer', 'min:1'],
            'children' => ['required', 'integer', 'min:0'],
        ]);

        $results = $this->bookings->search(
            $data['checkIn'],
            $data['checkOut'],
            (int) $data['rooms'],
            (int) $data['adults'],
            (int) $data['children'],
            $data['locationSlug'] ?? null,
            $data['hotelSlug'] ?? null,
        );

        $payload = array_map(fn (array $row) => [
            'hotel' => (new HotelResource($row['hotel']))->resolve(),
            'roomType' => (new RoomTypeResource($row['roomType']))->resolve(),
            'ratePlan' => (new RatePlanResource($row['ratePlan']))->resolve(),
            'nights' => $row['nights'],
            'availableUnits' => $row['availableUnits'],
            'subtotal' => $row['subtotal'],
            'taxesFees' => $row['taxesFees'],
            'total' => $row['total'],
        ], $results);

        return response()->json(['results' => $payload]);
    }
}
