<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Hotel;
use App\Models\Location;
use App\Observers\ActivityObserver;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spec "Activity log" - perm `activity`. Global seats and developers see
 * everything; other seats only entries for their own hotels / destinations.
 */
class ActivityController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(private readonly AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $types = array_values(ActivityObserver::SUBJECTS);

        $f = $request->validate([
            'subjectType' => ['nullable', Rule::in($types)],
            'subjectId' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::in(['created', 'updated', 'deleted'])],
            'actorType' => ['nullable', Rule::in(['admin', 'developer'])],
            'actorId' => ['nullable', 'integer'],
            'hotelId' => ['nullable', 'integer'],
            'locationId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ActivityLog::query()
            ->when($f['subjectType'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['subjectId'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['actorType'] ?? null, fn ($q, $v) => $q->where('actor_type', $v))
            ->when($f['actorId'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['hotelId'] ?? null, fn ($q, $v) => $q->where('hotel_id', $v))
            ->when($f['locationId'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('subject_label', 'like', "%{$term}%")
                ->orWhere('actor_name', 'like', "%{$term}%")));

        if (! $this->seesEverything($request)) {
            $hotelIds = Hotel::query()->get(['id'])->filter(fn (Hotel $h) => $this->adminCanAccessHotel($request, $h->id))->pluck('id')->all();
            $locationIds = Location::query()->get(['id'])->filter(fn (Location $l) => $this->adminCanAccessLocation($request, $l->id))->pluck('id')->all();

            $query->where(fn ($w) => $w->whereIn('hotel_id', $hotelIds)->orWhereIn('location_id', $locationIds));
        }

        $page = $query->orderByDesc('id')->paginate((int) ($f['perPage'] ?? 25));

        return response()->json([
            'activity' => $page->getCollection()->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'actor' => ['type' => $log->actor_type, 'id' => $log->actor_id, 'name' => $log->actor_name],
                'action' => $log->action,
                'subject' => ['type' => $log->subject_type, 'id' => $log->subject_id, 'label' => $log->subject_label],
                'hotelId' => $log->hotel_id,
                'locationId' => $log->location_id,
                'changes' => collect($log->changes ?? [])->map(fn ($c, $field) => ['field' => $field, 'from' => $c['from'] ?? null, 'to' => $c['to'] ?? null])->values(),
            ])->values(),
            'subjectTypes' => $types,
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    private function seesEverything(Request $request): bool
    {
        return $this->isDeveloper($request) || $this->access->isGlobal($this->requireAdmin($request));
    }
}
