<?php

namespace App\Services;

use App\Models\Availability;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared month-grid / occupancy maths. Read-only: it never touches the live
 * search or booking flow. A night's sellable units are the per-date override
 * (Availability) when there is one, otherwise the room type's base_inventory.
 */
class CalendarService
{
    /**
     * Rooms booked per night (cancelled reservations do not count).
     *
     * @param  iterable<int>  $roomTypeIds
     * @return array<int, array<string, int>>  roomTypeId => 'Y-m-d' => rooms
     */
    public function booked(iterable $roomTypeIds, Carbon $from, Carbon $toExclusive): array
    {
        $ids = collect($roomTypeIds)->values()->all();
        if ($ids === []) {
            return [];
        }

        $bookings = Booking::query()
            ->whereIn('room_type_id', $ids)
            ->where('status', '!=', 'cancelled')
            ->where('check_in', '<', $toExclusive->format('Y-m-d'))
            ->where('check_out', '>', $from->format('Y-m-d'))
            ->get(['room_type_id', 'check_in', 'check_out', 'rooms']);

        $map = [];
        foreach ($bookings as $booking) {
            $start = $booking->check_in->copy()->startOfDay()->max($from);
            $end = $booking->check_out->copy()->startOfDay()->min($toExclusive);

            for ($d = $start->copy(); $d->lt($end); $d->addDay()) {
                $key = $d->format('Y-m-d');
                $map[$booking->room_type_id][$key] = ($map[$booking->room_type_id][$key] ?? 0) + max(1, (int) $booking->rooms);
            }
        }

        return $map;
    }

    /**
     * Per-date availability overrides, keyed "roomTypeId|Y-m-d".
     *
     * @param  iterable<int>  $roomTypeIds
     * @return Collection<string, Availability>
     */
    public function overrides(iterable $roomTypeIds, Carbon $from, Carbon $toExclusive): Collection
    {
        return Availability::query()
            ->whereIn('room_type_id', collect($roomTypeIds)->values()->all())
            ->where('date', '>=', $from->format('Y-m-d'))
            ->where('date', '<', $toExclusive->format('Y-m-d'))
            ->get()
            ->keyBy(fn (Availability $a) => $a->room_type_id.'|'.$a->date->format('Y-m-d'));
    }

    /**
     * Occupancy for room types over from..toExclusive.
     *
     * @param  Collection<int, \App\Models\RoomType>  $roomTypes  with `hotel` loaded
     * @return array<string, mixed>
     */
    public function occupancy(Collection $roomTypes, Carbon $from, Carbon $toExclusive): array
    {
        $ids = $roomTypes->pluck('id');
        $overrides = $this->overrides($ids, $from, $toExclusive);
        $booked = $this->booked($ids, $from, $toExclusive);

        $perHotel = [];
        foreach ($roomTypes as $room) {
            $row = &$perHotel[$room->hotel_id];
            $row ??= ['hotelId' => $room->hotel_id, 'hotel' => (string) $room->hotel?->name, 'availableRoomNights' => 0, 'bookedRoomNights' => 0];

            for ($d = $from->copy(); $d->lt($toExclusive); $d->addDay()) {
                $key = $d->format('Y-m-d');
                $row['availableRoomNights'] += $overrides->get($room->id.'|'.$key)?->available_units ?? (int) $room->base_inventory;
                $row['bookedRoomNights'] += $booked[$room->id][$key] ?? 0;
            }
            unset($row);
        }

        $hotels = collect($perHotel)->map(fn (array $h) => $h + ['rate' => $this->rate($h['bookedRoomNights'], $h['availableRoomNights'])])->sortBy('hotel')->values();
        $available = (int) $hotels->sum('availableRoomNights');
        $bookedNights = (int) $hotels->sum('bookedRoomNights');

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $toExclusive->copy()->subDay()->format('Y-m-d'),
            'availableRoomNights' => $available,
            'bookedRoomNights' => $bookedNights,
            'rate' => $this->rate($bookedNights, $available),
            'byHotel' => $hotels,
        ];
    }

    /** Percent, one decimal, capped at 100; null when there is no inventory. */
    private function rate(int $booked, int $available): ?float
    {
        return $available > 0 ? min(100.0, round($booked / $available * 100, 1)) : null;
    }
}
