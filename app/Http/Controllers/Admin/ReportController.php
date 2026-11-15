<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Concerns\ScopesReservations;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RoomType;
use App\Services\AccessService;
use App\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec "Reports" - perm `reports`. Reservation figures for the hotels this
 * seat can see. "Value" is the total of reservations that were not cancelled;
 * it is not money received (payments are a separate, parked step).
 */
class ReportController extends Controller
{
    use AuthorizesAdminPanel, ScopesReservations;

    public function __construct(
        private readonly AccessService $access,
        private readonly CalendarService $calendar,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $filters = $this->reportFilters($request);
        $rows = $this->scopedReservations($request, $filters);
        $kept = $rows->where('status', '!=', 'cancelled');

        return response()->json([
            'filters' => $filters,
            'totals' => [
                'reservations' => $rows->count(),
                'cancelled' => $rows->count() - $kept->count(),
                'nights' => $kept->sum(fn (Booking $b) => $b->nights() * max(1, (int) $b->rooms)),
                'value' => $this->money($kept),
                'averageValue' => $kept->isEmpty() ? 0.0 : round($this->money($kept) / $kept->count(), 2),
                'averageNights' => $kept->isEmpty() ? 0.0 : round($kept->avg(fn (Booking $b) => $b->nights()), 1),
            ],
            'byStatus' => $this->group($rows, fn (Booking $b) => $b->status, 'status', false),
            'bySource' => $this->group($kept, fn (Booking $b) => $b->source ?: 'unknown', 'source'),
            'byDestination' => $this->group($kept, fn (Booking $b) => (string) ($b->hotel?->location?->name ?? 'Unknown'), 'destination'),
            'byHotel' => $this->group($kept, fn (Booking $b) => (string) ($b->hotel?->name ?? 'Unknown'), 'hotel'),
            'byRoomType' => $this->group($kept, fn (Booking $b) => (string) ($b->roomType?->name ?? 'Unknown'), 'roomType')->take(10)->values(),
            'byDay' => $this->byDay($kept, $filters['basis']),
            'occupancy' => $this->occupancy($request, $filters),
            'note' => 'Value is the total of reservations that were not cancelled, not money received. Occupancy needs a from and to date (at most one year).',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->reportFilters($request);
        $rows = $this->scopedReservations($request, $filters);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Status', 'Source', 'Created', 'Check-in', 'Check-out', 'Nights', 'Rooms', 'Adults', 'Children', 'Destination', 'Hotel', 'Room type', 'Guest', 'Email', 'Phone', 'Subtotal', 'Taxes & fees', 'Total']);

            foreach ($rows as $b) {
                fputcsv($out, array_map($this->safeCell(...), [
                    $b->reference, $b->status, $b->source,
                    $b->created_at?->format('Y-m-d H:i'),
                    $b->check_in?->format('Y-m-d'), $b->check_out?->format('Y-m-d'),
                    $b->nights(), $b->rooms, $b->adults, $b->children,
                    $b->hotel?->location?->name, $b->hotel?->name, $b->roomType?->name,
                    $b->guest_full_name, $b->guest_email, $b->guest_phone,
                    $b->subtotal, $b->taxes_fees, $b->total,
                ]));
            }
            fclose($out);
        }, 'reservations-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Booked room-nights over available room-nights for from..to (inclusive),
     * for the hotels this seat can see. Null without a date range.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>|null
     */
    private function occupancy(Request $request, array $f): ?array
    {
        if (! $f['from'] || ! $f['to']) {
            return null;
        }

        $from = Carbon::createFromFormat('!Y-m-d', $f['from']);
        $toExclusive = Carbon::createFromFormat('!Y-m-d', $f['to'])->addDay();
        if ($from->diffInDays($toExclusive) > 366) {
            return null;
        }

        $roomTypes = RoomType::query()
            ->with('hotel:id,name,location_id')
            ->when($f['hotelId'], fn ($q, $id) => $q->where('hotel_id', $id))
            ->when($f['locationId'], fn ($q, $id) => $q->whereHas('hotel', fn ($h) => $h->where('location_id', $id)))
            ->get()
            ->filter(fn (RoomType $r) => $this->adminCanAccessHotel($request, $r->hotel_id))
            ->values();

        return $this->calendar->occupancy($roomTypes, $from, $toExclusive);
    }

    /** Stops spreadsheet formulas: a cell starting with = + - @ is read as a formula by Excel. */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** @param Collection<int, Booking> $rows */
    private function money(Collection $rows): float
    {
        return round((float) $rows->sum('total'), 2);
    }

    /**
     * @param  Collection<int, Booking>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function group(Collection $rows, callable $by, string $label, bool $withValue = true): Collection
    {
        return $rows->groupBy($by)
            ->map(fn (Collection $g, string $name) => array_filter([
                $label => $name,
                'reservations' => $g->count(),
                'nights' => $g->sum(fn (Booking $b) => $b->nights() * max(1, (int) $b->rooms)),
                'value' => $withValue ? $this->money($g) : null,
            ], fn ($v) => $v !== null))
            ->sortByDesc('reservations')
            ->values();
    }

    /**
     * @param  Collection<int, Booking>  $rows
     * @return Collection<int, array{date: string, reservations: int, value: float}>
     */
    private function byDay(Collection $rows, string $basis): Collection
    {
        return $rows
            ->groupBy(fn (Booking $b) => ($basis === 'stay' ? $b->check_in : $b->created_at)?->format('Y-m-d') ?? 'unknown')
            ->map(fn (Collection $g, string $date) => ['date' => $date, 'reservations' => $g->count(), 'value' => $this->money($g)])
            ->sortKeys()
            ->values();
    }
}
