<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Services\AccessService;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Admin dashboard "Quick book" — staff record a paid reservation
 * (cash / ABA QR / card) without a payment gateway.
 */
class QuickReservationController extends Controller
{
    use AuthorizesAdminPanel;

    public const PAYMENT_METHODS = ['cash', 'aba_qr', 'card'];

    private const STAFF_SOURCES = ['admin', 'phone', 'walk_in', 'email'];

    public function __construct(
        private readonly AccessService $access,
        private readonly BookingService $bookings,
    ) {}

    /**
     * Room types at a hotel with nightly price and rooms left for the dates.
     * Sold-out rooms are included (availableUnits = 0, soldOut = true).
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hotelId' => ['required', 'integer', Rule::exists(Hotel::class, 'id')],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d'],
            'guests' => ['required', 'integer', 'min:1'],
            'rooms' => ['sometimes', 'integer', 'min:1'],
        ]);

        $hotelId = (int) $data['hotelId'];
        $this->assertHotelInScope($request, $hotelId);

        $rooms = (int) ($data['rooms'] ?? 1);
        $guests = (int) $data['guests'];

        $rows = $this->bookings->inventoryForHotel(
            $hotelId,
            $data['checkIn'],
            $data['checkOut'],
            $rooms,
            $guests,
            0,
        );

        $hotel = $rows[0]['hotel'] ?? Hotel::query()->with('location')->find($hotelId);

        return response()->json([
            'hotel' => [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'slug' => $hotel->slug,
                'locationName' => $hotel->location?->name,
            ],
            'checkIn' => $data['checkIn'],
            'checkOut' => $data['checkOut'],
            'guests' => $guests,
            'rooms' => $rooms,
            'nights' => $rows[0]['nights'] ?? max(0, (int) (new Carbon($data['checkIn']))->diffInDays($data['checkOut'])),
            'roomTypes' => array_map(fn (array $row) => $this->mapQuote($row), $rows),
        ]);
    }

    /**
     * Create a confirmed, paid reservation. Price is always computed
     * on the server; a client-supplied total is ignored.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'roomTypeId' => ['required', 'integer', Rule::exists(RoomType::class, 'id')],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d'],
            'guests' => ['required', 'integer', 'min:1'],
            'rooms' => ['sometimes', 'integer', 'min:1'],
            'guestFullName' => ['required', 'string', 'max:255'],
            'guestPhone' => ['required', 'string', 'max:255'],
            'guestEmail' => ['nullable', 'email', 'max:255'],
            'paymentMethod' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'source' => ['sometimes', Rule::in(self::STAFF_SOURCES)],
            'total' => ['sometimes', 'numeric'],
        ]);

        $room = RoomType::query()->findOrFail($data['roomTypeId']);
        $this->assertHotelInScope($request, (int) $room->hotel_id);

        $booking = $this->bookings->create([
            'hotel_id' => (int) $room->hotel_id,
            'room_type_id' => (int) $room->id,
            'check_in' => $data['checkIn'],
            'check_out' => $data['checkOut'],
            'rooms' => (int) ($data['rooms'] ?? 1),
            'adults' => (int) $data['guests'],
            'children' => 0,
            'guest_full_name' => $data['guestFullName'],
            'guest_email' => $data['guestEmail'] ?? '',
            'guest_phone' => $data['guestPhone'],
            'source' => $data['source'] ?? 'admin',
            'status' => 'confirmed',
            'payment_method' => $data['paymentMethod'],
            'paid_at' => now(),
        ]);

        return response()->json([
            'booking' => new BookingResource(
                $booking->refresh()->load(['hotel.location', 'roomType', 'ratePlan'])
            ),
        ], 201);
    }

    private function assertHotelInScope(Request $request, int $hotelId): void
    {
        if (! $this->adminCanAccessHotel($request, $hotelId)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapQuote(array $row): array
    {
        /** @var RoomType $room */
        $room = $row['roomType'];
        $images = $room->images;
        if (is_string($images)) {
            $images = json_decode($images, true);
        }
        $image = is_array($images) ? (string) (collect($images)->first() ?? '') : '';

        return [
            'roomTypeId' => $room->id,
            'name' => $room->name,
            'bedType' => $room->bed_type,
            'roomSize' => $room->room_size,
            'maxGuests' => $room->max_guests,
            'image' => $image !== '' ? $image : null,
            'ratePlanId' => $row['ratePlan']->id,
            'nights' => $row['nights'],
            'nightly' => $row['nightly'],
            'subtotal' => $row['subtotal'],
            'taxesFees' => $row['taxesFees'],
            'total' => $row['total'],
            'availableUnits' => $row['availableUnits'],
            'soldOut' => $row['soldOut'],
        ];
    }
}
