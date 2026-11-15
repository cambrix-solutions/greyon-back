<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use App\Http\Resources\ReservationResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Authenticated customer (web guard) — their reservation list + detail.
 *
 * Ownership = linked user_id OR same guest_email as the account (case-insensitive),
 * so a prior guest booking with that Gmail appears after Google/email sign-in.
 */
class MyBookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->bookings->claimForUser($user);

        $bookings = Booking::query()
            ->with(['hotel', 'roomType', 'ratePlan'])
            ->where(function ($q) use ($user) {
                $this->scopeOwned($q, $user);
            })
            ->orderByDesc('check_in')
            ->get();

        return response()->json([
            'bookings' => BookingResource::collection($bookings),
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();
        $this->bookings->claimForUser($user);

        $booking = Booking::query()
            ->with(['hotel', 'roomType', 'ratePlan'])
            ->where('reference', $reference)
            ->where(function ($q) use ($user) {
                $this->scopeOwned($q, $user);
            })
            ->first();

        if (! $booking) {
            throw new HttpException(404, 'Reservation not found');
        }

        return response()->json([
            'booking' => new BookingResource($booking),
            'reservation' => new ReservationResource($booking->load('events')),
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Booking>  $q
     */
    private function scopeOwned($q, User $user): void
    {
        $email = strtolower(trim((string) $user->email));

        $q->where('user_id', $user->id);

        if ($email !== '') {
            $q->orWhereRaw('LOWER(guest_email) = ?', [$email]);
        }
    }
}
