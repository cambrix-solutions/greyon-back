<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\AccessService;
use App\Services\BookingNotificationService;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec "Bookings" — perm `bookings`. Hard-scoped via canAccessHotel.
 */
class BookingController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(
        private readonly AccessService $access,
        private readonly BookingService $bookings,
        private readonly BookingNotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = Booking::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('reference', 'like', "%{$term}%")
                        ->orWhere('guest_full_name', 'like', "%{$term}%")
                        ->orWhere('guest_email', 'like', "%{$term}%")
                        ->orWhere('guest_phone', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Booking $booking) => $this->adminCanAccessHotel($request, $booking->hotel_id))
            ->values();

        return response()->json(['bookings' => BookingResource::collection($rows)]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeScope($request, $booking);

        return response()->json(['booking' => new BookingResource($booking)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedCreate($request);
        $this->assertHotelInScope($request, $data['hotel_id']);

        $booking = $this->bookings->create([
            ...$data,
            'source' => 'admin',
            'status' => $data['status'] ?? 'pending',
        ]);

        return response()->json(['booking' => new BookingResource($booking->refresh())], 201);
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeScope($request, $booking);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'cancelled', 'completed'])],
            'notes' => ['nullable', 'string'],
        ]);

        $fromStatus = $booking->status;
        $booking->update($data);

        if (array_key_exists('status', $data) && $data['status'] !== $fromStatus) {
            $this->notifications->notifyStatusChanged(
                $booking->fresh(['hotel.location']) ?? $booking,
                $fromStatus,
                (string) $data['status'],
            );
        }

        return response()->json(['booking' => new BookingResource($booking->refresh())]);
    }

    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeScope($request, $booking);

        $booking->delete();

        return response()->json(['message' => 'Booking deleted.']);
    }

    private function authorizeScope(Request $request, Booking $booking): void
    {
        if (! $this->adminCanAccessHotel($request, $booking->hotel_id)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    private function assertHotelInScope(Request $request, int $hotelId): void
    {
        if (! $this->adminCanAccessHotel($request, $hotelId)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCreate(Request $request): array
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
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'cancelled', 'completed'])],
        ]);

        return $this->mapCamel($data);
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
