<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Reservations the signed-in admin may see (hotel scope enforced), with the
 * shared report filters. Used by Guests and Reports so both agree on scope.
 * The using class must also use AuthorizesAdminPanel.
 */
trait ScopesReservations
{
    /**
     * @return array{from: ?string, to: ?string, hotelId: ?int, locationId: ?int, status: ?string, basis: string}
     */
    protected function reportFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'hotelId' => ['nullable', 'integer'],
            'locationId' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,confirmed,cancelled,completed'],
            'basis' => ['nullable', 'in:created,stay'],
        ]);

        return [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'hotelId' => isset($data['hotelId']) ? (int) $data['hotelId'] : null,
            'locationId' => isset($data['locationId']) ? (int) $data['locationId'] : null,
            'status' => $data['status'] ?? null,
            'basis' => $data['basis'] ?? 'created',
        ];
    }

    /**
     * @param  array<string, mixed>  $f  from reportFilters(); empty = everything
     * @return Collection<int, Booking>
     */
    protected function scopedReservations(Request $request, array $f = []): Collection
    {
        $column = ($f['basis'] ?? 'created') === 'stay' ? 'check_in' : 'created_at';

        return Booking::query()
            ->with(['hotel:id,name,location_id', 'hotel.location:id,name', 'roomType:id,name'])
            ->when($f['hotelId'] ?? null, fn ($q, $id) => $q->where('hotel_id', $id))
            ->when($f['locationId'] ?? null, fn ($q, $id) => $q->whereHas('hotel', fn ($h) => $h->where('location_id', $id)))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate($column, '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate($column, '<=', $d))
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Booking $b) => $this->adminCanAccessHotel($request, $b->hotel_id))
            ->values();
    }
}
