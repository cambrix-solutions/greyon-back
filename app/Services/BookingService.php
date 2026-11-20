<?php

namespace App\Services;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\RateCalendar;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Port of Nest BookingsService search/create (skip mail — Log::info only).
 * Staff booking alerts fan out via BookingNotificationService.
 */
class BookingService
{
    public function __construct(
        private readonly BookingNotificationService $notifications,
    ) {}

    /**
     * @return list<array{
     *   hotel: Hotel,
     *   roomType: RoomType,
     *   ratePlan: RatePlan,
     *   nights: int,
     *   availableUnits: int,
     *   subtotal: float,
     *   taxesFees: float,
     *   total: float
     * }>
     */
    public function search(
        string $checkIn,
        string $checkOut,
        int $rooms,
        int $adults,
        int $children,
        ?string $locationSlug = null,
        ?string $hotelSlug = null,
    ): array {
        $this->assertDates($checkIn, $checkOut);

        $nightDates = $this->eachNight($checkIn, $checkOut);
        $nights = count($nightDates);
        if ($nights < 1) {
            return [];
        }

        $hotels = Hotel::query()
            ->with('location')
            ->where('status', 'published')
            ->when($hotelSlug, fn ($q) => $q->where('slug', $hotelSlug))
            ->when(! $hotelSlug && $locationSlug, function ($q) use ($locationSlug) {
                $q->whereHas('location', fn ($lq) => $lq->where('slug', $locationSlug));
            })
            ->get();

        if ($hotels->isEmpty()) {
            return [];
        }

        $hotelIds = $hotels->pluck('id');
        $roomTypes = RoomType::query()
            ->where('status', 'published')
            ->whereIn('hotel_id', $hotelIds)
            ->get()
            ->groupBy('hotel_id');

        $roomTypeIds = $roomTypes->flatten()->pluck('id');
        $ratePlans = RatePlan::query()
            ->where('status', 'published')
            ->whereIn('room_type_id', $roomTypeIds)
            ->get()
            ->groupBy('room_type_id');

        $availabilities = Availability::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->whereIn('date', $nightDates)
            ->get()
            ->groupBy(fn (Availability $row) => $row->room_type_id.'|'.$row->date->format('Y-m-d'));

        $ratePlanIds = $ratePlans->flatten()->pluck('id');
        $calendars = RateCalendar::query()
            ->whereIn('rate_plan_id', $ratePlanIds)
            ->whereIn('date', $nightDates)
            ->get()
            ->groupBy(fn (RateCalendar $row) => $row->rate_plan_id.'|'.$row->date->format('Y-m-d'));

        $bookedByRoomDate = $this->bookedUnitsByRoomAndNight($roomTypeIds, $nightDates);

        $results = [];

        foreach ($hotels as $hotel) {
            foreach ($roomTypes->get($hotel->id, collect()) as $room) {
                $guests = $adults + $children;
                if ($adults > $room->max_adults
                    || $children > $room->max_children
                    || $guests > $room->max_guests) {
                    continue;
                }

                foreach ($ratePlans->get($room->id, collect()) as $plan) {
                    $blocked = false;
                    $availableUnits = PHP_INT_MAX;
                    $nightTotal = 0.0;

                    foreach ($nightDates as $date) {
                        $availKey = $room->id.'|'.$date;
                        $avail = $availabilities->get($availKey)?->first();
                        $units = $avail?->stop_sell
                            ? 0
                            : ($avail?->available_units ?? $room->base_inventory);

                        $booked = $bookedByRoomDate[$room->id][$date] ?? 0;
                        $free = $units - $booked;
                        if ($free < $rooms) {
                            $blocked = true;
                            break;
                        }

                        $availableUnits = min($availableUnits, $free);

                        $calKey = $plan->id.'|'.$date;
                        $cal = $calendars->get($calKey)?->first();
                        if ($cal?->min_stay && $nights < $cal->min_stay) {
                            $blocked = true;
                            break;
                        }
                        if ($cal?->max_stay && $nights > $cal->max_stay) {
                            $blocked = true;
                            break;
                        }

                        $nightTotal += (float) ($cal?->price ?? $plan->base_price);
                    }

                    if ($blocked || $availableUnits === PHP_INT_MAX) {
                        continue;
                    }

                    $subtotal = $nightTotal * $rooms;
                    $taxesFees = $subtotal * (((float) $plan->tax_percent + (float) $plan->service_fee_percent) / 100);

                    $results[] = [
                        'hotel' => $hotel,
                        'roomType' => $room,
                        'ratePlan' => $plan,
                        'nights' => $nights,
                        'availableUnits' => $availableUnits,
                        'subtotal' => round($subtotal, 2),
                        'taxesFees' => round($taxesFees, 2),
                        'total' => round($subtotal + $taxesFees, 2),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @param  array{
     *   hotel_id: int,
     *   room_type_id: int,
     *   rate_plan_id: int,
     *   check_in: string,
     *   check_out: string,
     *   rooms: int,
     *   adults: int,
     *   children: int,
     *   guest_full_name: string,
     *   guest_email: string,
     *   guest_phone: string,
     *   special_requests?: string|null,
     *   source?: string,
     *   notes?: string|null,
     *   status?: string
     * }  $input
     */
    public function create(array $input): Booking
    {
        $hotel = Hotel::query()->find($input['hotel_id']);
        if (! $hotel) {
            throw new HttpException(404, 'Hotel not found.');
        }

        $matches = $this->search(
            $input['check_in'],
            $input['check_out'],
            $input['rooms'],
            $input['adults'],
            $input['children'],
            null,
            $hotel->slug,
        );

        $match = collect($matches)->first(fn (array $row) => (int) $row['hotel']->id === (int) $input['hotel_id']
            && (int) $row['roomType']->id === (int) $input['room_type_id']
            && (int) $row['ratePlan']->id === (int) $input['rate_plan_id']);

        if (! $match) {
            throw new HttpException(409, 'Selected room is no longer available for these dates.');
        }

        $booking = Booking::create([
            'user_id' => $input['user_id'] ?? null,
            'reference' => $this->generateReference(),
            'hotel_id' => $input['hotel_id'],
            'room_type_id' => $input['room_type_id'],
            'rate_plan_id' => $input['rate_plan_id'],
            'check_in' => $input['check_in'],
            'check_out' => $input['check_out'],
            'rooms' => $input['rooms'],
            'adults' => $input['adults'],
            'children' => $input['children'],
            'guest_full_name' => $input['guest_full_name'],
            'guest_email' => strtolower(trim((string) $input['guest_email'])),
            'guest_phone' => $input['guest_phone'],
            'special_requests' => $input['special_requests'] ?? null,
            'subtotal' => $match['subtotal'],
            'taxes_fees' => $match['taxesFees'],
            'total' => $match['total'],
            'status' => $input['status'] ?? 'pending',
            'source' => $input['source'] ?? 'website',
            'notes' => $input['notes'] ?? null,
        ]);

        Log::info('Booking created (mail skipped)', [
            'reference' => $booking->reference,
            'guestEmail' => $booking->guest_email,
            'total' => $booking->total,
        ]);

        if (! empty($input['user_id'])) {
            $user = User::query()->find($input['user_id']);
            if ($user) {
                $this->syncProfilePhoneFromGuest($user, (string) ($input['guest_phone'] ?? ''));
            }
        }

        $this->notifications->notifyCreated($booking->fresh(['hotel.location']) ?? $booking);

        return $booking;
    }

    public function findByReference(string $reference): ?Booking
    {
        return Booking::query()->where('reference', $reference)->first();
    }

    public function generateReference(): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $chars[random_int(0, 35)];
            }
            $reference = 'GRY-'.$suffix;
        } while (Booking::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function assertDates(string $checkIn, string $checkOut): void
    {
        $today = Carbon::today();
        $start = Carbon::createFromFormat('Y-m-d', $checkIn)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $checkOut)->startOfDay();

        if (! ($start->gte($today) && $end->gt($start))) {
            throw new HttpException(400, 'Invalid check-in / check-out dates');
        }
    }

    /**
     * @return list<string>
     */
    private function eachNight(string $checkIn, string $checkOut): array
    {
        $nights = [];
        $cursor = Carbon::createFromFormat('Y-m-d', $checkIn)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $checkOut)->startOfDay();

        while ($cursor->lt($end)) {
            $nights[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $nights;
    }

    /**
     * @param  Collection<int, int>|list<int>  $roomTypeIds
     * @param  list<string>  $nightDates
     * @return array<int, array<string, int>>
     */
    private function bookedUnitsByRoomAndNight($roomTypeIds, array $nightDates): array
    {
        if (empty($nightDates) || collect($roomTypeIds)->isEmpty()) {
            return [];
        }

        $from = $nightDates[0];
        $toExclusive = Carbon::createFromFormat('Y-m-d', end($nightDates))->addDay()->format('Y-m-d');

        $bookings = Booking::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->where('status', '!=', 'cancelled')
            ->where('check_in', '<', $toExclusive)
            ->where('check_out', '>', $from)
            ->get(['room_type_id', 'check_in', 'check_out', 'rooms']);

        $map = [];
        foreach ($bookings as $booking) {
            foreach ($nightDates as $date) {
                $next = Carbon::createFromFormat('Y-m-d', $date)->addDay()->format('Y-m-d');
                $checkIn = $booking->check_in->format('Y-m-d');
                $checkOut = $booking->check_out->format('Y-m-d');
                if ($checkIn < $next && $checkOut > $date) {
                    $map[$booking->room_type_id][$date] = ($map[$booking->room_type_id][$date] ?? 0) + $booking->rooms;
                }
            }
        }

        return $map;
    }

    /**
     * Attach orphan guest bookings that used this account's email.
     * Same Gmail as a prior guest checkout → stay shows under My account.
     * Also backfills users.phone from the latest booking guest phone when empty.
     */
    public function claimForUser(User $user): int
    {
        $email = strtolower(trim((string) $user->email));
        $claimed = 0;

        if ($email !== '') {
            $claimed = Booking::query()
                ->whereNull('user_id')
                ->whereRaw('LOWER(guest_email) = ?', [$email])
                ->update(['user_id' => $user->id]);
        }

        $this->backfillPhoneFromBookings($user);

        return $claimed;
    }

    /**
     * Persist the guest contact phone onto the linked account (latest wins).
     */
    public function syncProfilePhoneFromGuest(User $user, string $guestPhone): void
    {
        $phone = trim($guestPhone);
        if ($phone === '') {
            return;
        }

        if (trim((string) $user->phone) === $phone) {
            return;
        }

        $user->forceFill(['phone' => $phone])->save();
    }

    /**
     * If the account has no phone yet, copy the most recent booking guest_phone.
     */
    public function backfillPhoneFromBookings(User $user): void
    {
        if (trim((string) $user->phone) !== '') {
            return;
        }

        $email = strtolower(trim((string) $user->email));

        $phone = Booking::query()
            ->where(function ($q) use ($user, $email) {
                $q->where('user_id', $user->id);
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(guest_email) = ?', [$email]);
                }
            })
            ->whereNotNull('guest_phone')
            ->where('guest_phone', '!=', '')
            ->orderByDesc('created_at')
            ->value('guest_phone');

        if (is_string($phone) && trim($phone) !== '') {
            $user->forceFill(['phone' => trim($phone)])->save();
        }
    }
}
