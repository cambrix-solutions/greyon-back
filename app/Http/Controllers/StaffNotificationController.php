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

    public function index(Request $request): JsonResponse
    {
        $user = $this->staffNotifiable($request);

        $rows = StaffNotification::query()
            ->whereMorphedTo('notifiable', $user)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $unread = StaffNotification::query()
            ->whereMorphedTo('notifiable', $user)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => StaffNotificationResource::collection($rows),
            'unreadCount' => $unread,
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
