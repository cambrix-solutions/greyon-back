<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesStaffNotifiable;
use App\Http\Resources\StaffNotificationResource;
use App\Models\StaffNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * In-app booking inbox for admin + developer guards.
 */
class StaffNotificationController extends Controller
{
    use ResolvesStaffNotifiable;

    /**
     * Inbox page. Filters (all optional): unread=1, type, hotelId, from, to
     * (Y-m-d, on the created date), page, perPage (default 50, max 100).
     * `unreadCount` is always the full unread total, whatever the filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->staffNotifiable($request);

        $f = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:64'],
            'hotelId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $mine = fn () => StaffNotification::query()->whereMorphedTo('notifiable', $user);

        $page = $mine()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['hotelId'] ?? null, fn ($q, $id) => $q->where('hotel_id', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($f['perPage'] ?? 50));

        return response()->json([
            'notifications' => StaffNotificationResource::collection($page->getCollection()),
            'unreadCount' => $mine()->whereNull('read_at')->count(),
            'types' => $mine()->distinct()->orderBy('type')->pluck('type'),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->staffNotifiable($request);

        $unread = StaffNotification::query()
            ->whereMorphedTo('notifiable', $user)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unreadCount' => $unread]);
    }

    public function markRead(Request $request, StaffNotification $notification): JsonResponse
    {
        $this->assertOwns($request, $notification);
        $notification->markRead();

        return response()->json([
            'notification' => new StaffNotificationResource($notification->refresh()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->staffNotifiable($request);

        StaffNotification::query()
            ->whereMorphedTo('notifiable', $user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function assertOwns(Request $request, StaffNotification $notification): void
    {
        $user = $this->staffNotifiable($request);

        if ($notification->notifiable_type !== $user::class
            || (int) $notification->notifiable_id !== (int) $user->getKey()) {
            throw new HttpException(404, 'Not found.');
        }
    }
}
