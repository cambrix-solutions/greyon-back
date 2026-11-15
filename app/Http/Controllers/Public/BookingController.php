<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Public guest booking create + lookup by reference.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hotelId' => ['required', 'integer', Rule::exists(Hotel::class, 'id')],
            'roomTypeId' => ['required', 'integer', Rule::exists(RoomType::class, 'id')],
            'ratePlanId' => ['required', 'integer', Rule::exists(RatePlan::class, 'id')],
            'checkIn' => ['required', 'date_format:Y-m-d'],
            'checkOut' => ['required', 'date_format:Y-m-d'],
            'rooms' => ['required', 'integer', 'min:1'],
            'adults' => ['required', 'integer', 'min:1'],
            'children' => ['required', 'integer', 'min:0'],
            'guestFullName' => ['required', 'string', 'max:255'],
            'guestEmail' => ['required', 'email', 'max:255'],
            'guestPhone' => ['required', 'string', 'max:255'],
            'specialRequests' => ['nullable', 'string'],
            'offerCode' => ['nullable', 'string', 'max:64'],
        ]);

        $payload = $this->mapCamel($data);
        $payload['user_id'] = $this->resolveUserId($payload['guest_email'] ?? null);

        $booking = $this->bookings->create($payload);

        return response()->json([
            'booking' => new BookingResource(
                $booking->refresh()->load(['hotel', 'roomType', 'ratePlan'])
            ),
        ], 201);
    }

    public function show(string $reference): JsonResponse
    {
        $booking = $this->bookings->findByReference($reference);
        if (! $booking) {
            throw new HttpException(404, 'Reservation not found');
        }

        return response()->json([
            'booking' => new BookingResource($booking->load(['hotel', 'roomType', 'ratePlan'])),
        ]);
    }

    /**
     * Attach to the signed-in customer when present; otherwise match by email.
     */
    private function resolveUserId(?string $guestEmail): ?int
    {
        $authUser = Auth::guard('web')->user();
        if ($authUser instanceof User && $authUser->isActive()) {
            return (int) $authUser->id;
        }

        if (! $guestEmail) {
            return null;
        }

        $matched = User::query()
            ->where('email', strtolower($guestEmail))
            ->where('status', 'active')
            ->first();

        return $matched?->id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapCamel(array $data): array
    {
        $map = [
            'hotelId' => 'hotel_id',
            'roomTypeId' => 'room_type_id',
            'ratePlanId' => 'rate_plan_id',
            'checkIn' => 'check_in',
            'checkOut' => 'check_out',
            'guestFullName' => 'guest_full_name',
            'guestEmail' => 'guest_email',
            'guestPhone' => 'guest_phone',
            'specialRequests' => 'special_requests',
            'offerCode' => 'offer_code',
        ];

        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $data[$snake] = $data[$camel];
                unset($data[$camel]);
            }
        }

        return $data;
    }
}
