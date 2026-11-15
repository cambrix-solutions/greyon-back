<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Find my reservation": reference + the email it was made with. A wrong
 * reference and a wrong email get the same answer, so the endpoint cannot be
 * used to discover which references exist. Throttled at the route.
 */
class ReservationLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $booking = Booking::query()
            ->with(['hotel', 'roomType', 'ratePlan', 'events'])
            ->where('reference', strtoupper(trim($data['reference'])))
            ->whereRaw('LOWER(guest_email) = ?', [strtolower(trim($data['email']))])
            ->first();

        if (! $booking) {
            throw new HttpException(404, 'Reservation not found. Check the reference and the email you used.');
        }

        return response()->json(['reservation' => new ReservationResource($booking)]);
    }
}
