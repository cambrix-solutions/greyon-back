<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\RateCalendar;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\AccessService;
use App\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec "Calendar" - perm `calendar`. One month grid of units, bookings,
 * stop-sell and prices per room type, plus a bulk editor for a date range.
 * Scope is the room type's hotel; out of scope is a 404.
 */
class CalendarController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(
        private readonly AccessService $access,
        private readonly CalendarService $calendar,
    ) {}

    /** GET /admin/calendar?month=2026-12&hotelId=&roomTypeId= */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'hotelId' => ['nullable', 'integer'],
            'roomTypeId' => ['nullable', 'integer'],
        ]);

        $from = Carbon::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
        $toExclusive = $from->copy()->addMonth();
        $dates = [];
        for ($d = $from->copy(); $d->lt($toExclusive); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $roomTypes = RoomType::query()
            ->with('hotel:id,name')
            ->when($data['hotelId'] ?? null, fn ($q, $id) => $q->where('hotel_id', $id))
            ->when($data['roomTypeId'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('hotel_id')->orderBy('name')
            ->get()
            ->filter(fn (RoomType $r) => $this->adminCanAccessHotel($request, $r->hotel_id))
            ->values();

        $ids = $roomTypes->pluck('id');
        $overrides = $this->calendar->overrides($ids, $from, $toExclusive);
        $booked = $this->calendar->booked($ids, $from, $toExclusive);

        $plans = RatePlan::query()->whereIn('room_type_id', $ids)->orderBy('id')->get()->groupBy('room_type_id');
        $rates = RateCalendar::query()
            ->whereIn('rate_plan_id', $plans->flatten()->pluck('id'))
            ->where('date', '>=', $from->format('Y-m-d'))
            ->where('date', '<', $toExclusive->format('Y-m-d'))
            ->get()
            ->keyBy(fn (RateCalendar $r) => $r->rate_plan_id.'|'.$r->date->format('Y-m-d'));

        return response()->json([
            'month' => $from->format('Y-m'),
            'from' => $dates[0],
            'to' => end($dates),
            'roomTypes' => $roomTypes->map(fn (RoomType $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'hotelId' => $room->hotel_id,
                'hotelName' => $room->hotel?->name,
                'baseInventory' => (int) $room->base_inventory,
                'days' => collect($dates)->map(function (string $date) use ($room, $overrides, $booked) {
                    $override = $overrides->get($room->id.'|'.$date);
                    $units = $override?->available_units ?? (int) $room->base_inventory;
                    $rooms = $booked[$room->id][$date] ?? 0;

                    return [
                        'date' => $date,
                        'units' => $units,
                        'booked' => $rooms,
                        'free' => max(0, $units - $rooms),
                        'stopSell' => (bool) $override?->stop_sell,
                        'overridden' => $override !== null,
                    ];
                })->values(),
                'ratePlans' => $plans->get($room->id, collect())->map(fn (RatePlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'basePrice' => (float) $plan->base_price,
                    'days' => collect($dates)->map(function (string $date) use ($plan, $rates) {
                        $row = $rates->get($plan->id.'|'.$date);

                        return [
                            'date' => $date,
                            'price' => (float) ($row?->price ?? $plan->base_price),
                            'minStay' => $row?->min_stay,
                            'maxStay' => $row?->max_stay,
                            'overridden' => $row !== null,
                        ];
                    })->values(),
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * PUT /admin/calendar/bulk - change a date range for one room type.
     * Units / stop-sell apply to the room type; price / minStay / maxStay need
     * ratePlanId. Omitted fields keep their current value (or the default).
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'roomTypeId' => ['required', 'integer', Rule::exists(RoomType::class, 'id')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'weekdays' => ['sometimes', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'availableUnits' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'stopSell' => ['sometimes', 'boolean'],
            'ratePlanId' => ['sometimes', 'integer'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'minStay' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'maxStay' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $room = RoomType::query()->findOrFail($data['roomTypeId']);
        if (! $this->adminCanAccessHotel($request, $room->hotel_id)) {
            throw new HttpException(404, 'Not found.');
        }

        $touchesUnits = array_key_exists('availableUnits', $data) || array_key_exists('stopSell', $data);
        $touchesRates = array_key_exists('price', $data) || array_key_exists('minStay', $data) || array_key_exists('maxStay', $data);

        if (! $touchesUnits && ! $touchesRates) {
            throw ValidationException::withMessages(['availableUnits' => 'Nothing to change. Send availableUnits, stopSell, price, minStay or maxStay.']);
        }

        $plan = null;
        if ($touchesRates) {
            $plan = RatePlan::query()->where('room_type_id', $room->id)->find($data['ratePlanId'] ?? 0);
            if (! $plan) {
                throw ValidationException::withMessages(['ratePlanId' => 'Choose a rate plan of this room type.']);
            }
        }
        if (isset($data['minStay'], $data['maxStay']) && $data['minStay'] > $data['maxStay']) {
            throw ValidationException::withMessages(['maxStay' => 'Maximum stay cannot be shorter than the minimum stay.']);
        }

        $from = Carbon::createFromFormat('!Y-m-d', $data['from']);
        $to = Carbon::createFromFormat('!Y-m-d', $data['to']);
        if ($from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose a range of at most one year.']);
        }

        $weekdays = $data['weekdays'] ?? null;
        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($weekdays === null || in_array($d->dayOfWeek, $weekdays, true)) {
                $dates[] = $d->format('Y-m-d');
            }
        }

        if ($dates === []) {
            return response()->json(['updatedDays' => ['availability' => 0, 'rates' => 0], 'dates' => 0]);
        }

        $first = $dates[0];
        $lastExclusive = Carbon::createFromFormat('!Y-m-d', end($dates))->addDay()->format('Y-m-d');

        $counts = DB::transaction(function () use ($dates, $room, $plan, $data, $touchesUnits, $touchesRates, $first, $lastExclusive) {
            // Matched in PHP by Y-m-d, so it behaves the same on MySQL and sqlite.
            $unitRows = $touchesUnits
                ? Availability::query()->where('room_type_id', $room->id)->where('date', '>=', $first)->where('date', '<', $lastExclusive)->get()
                    ->keyBy(fn (Availability $a) => $a->date->format('Y-m-d'))
                : collect();
            $rateRows = $touchesRates
                ? RateCalendar::query()->where('rate_plan_id', $plan->id)->where('date', '>=', $first)->where('date', '<', $lastExclusive)->get()
                    ->keyBy(fn (RateCalendar $r) => $r->date->format('Y-m-d'))
                : collect();

            $units = 0;
            $rates = 0;

            foreach ($dates as $date) {
                if ($touchesUnits) {
                    $row = $unitRows->get($date) ?? new Availability(['room_type_id' => $room->id, 'date' => $date]);
                    $row->available_units = $data['availableUnits'] ?? ($row->exists ? $row->available_units : (int) $room->base_inventory);
                    $row->stop_sell = array_key_exists('stopSell', $data) ? (bool) $data['stopSell'] : (bool) ($row->stop_sell ?? false);
                    $row->save();
                    $units++;
                }

                if ($touchesRates) {
                    $row = $rateRows->get($date) ?? new RateCalendar(['rate_plan_id' => $plan->id, 'date' => $date]);
                    $row->price = $data['price'] ?? ($row->exists ? $row->price : $plan->base_price);
                    if (array_key_exists('minStay', $data)) {
                        $row->min_stay = $data['minStay'];
                    }
                    if (array_key_exists('maxStay', $data)) {
                        $row->max_stay = $data['maxStay'];
                    }
                    $row->save();
                    $rates++;
                }
            }

            return ['availability' => $units, 'rates' => $rates];
        });

        return response()->json(['updatedDays' => $counts, 'dates' => count($dates)]);
    }
}
