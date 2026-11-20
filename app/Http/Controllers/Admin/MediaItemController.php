<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Resources\MediaItemResource;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\MediaItem;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec "Media" — perm `media`. URL-based library (upload storage TBD).
 * Assets attach to a destination and may optionally pin to a hotel.
 *
 * Access (product 2026-09-22): Managers keep scoped media when storage
 * upgrades — list/create only within assigned locationIds / hotelIds.
 * Do not regress to Admin/Developer-only.
 */
class MediaItemController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(private readonly AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->query('locationId');
        $hotelId = $request->query('hotelId');

        $items = MediaItem::query()
            ->with(['location', 'hotel'])
            ->when($locationId !== null && $locationId !== '', fn ($q) => $q->where('location_id', (int) $locationId))
            ->when($hotelId !== null && $hotelId !== '', fn ($q) => $q->where('hotel_id', (int) $hotelId))
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (MediaItem $item) => $this->canSee($request, $item))
            ->values();

        return response()->json(['media' => MediaItemResource::collection($items)]);
    }

    public function show(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeSee($request, $mediaItem);

        return response()->json(['mediaItem' => new MediaItemResource($mediaItem->load(['location', 'hotel']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertScope($request, $data['location_id'] ?? null, $data['hotel_id'] ?? null);
        $data['created_by_admin_id'] = $this->actingAdmin($request)?->id;

        $item = MediaItem::create($data);

        return response()->json([
            'mediaItem' => new MediaItemResource($item->refresh()->load(['location', 'hotel'])),
        ], 201);
    }

    public function update(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeSee($request, $mediaItem);

        $data = $this->validated($request, $mediaItem);
        $locationId = array_key_exists('location_id', $data)
            ? $data['location_id']
            : $mediaItem->location_id;
        $hotelId = array_key_exists('hotel_id', $data)
            ? $data['hotel_id']
            : $mediaItem->hotel_id;
        $this->assertScope($request, $locationId, $hotelId);

        $mediaItem->update($data);

        return response()->json([
            'mediaItem' => new MediaItemResource($mediaItem->refresh()->load(['location', 'hotel'])),
        ]);
    }

    public function destroy(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeSee($request, $mediaItem);
        $this->assertGlobalSeat($request);

        $mediaItem->delete();

        return response()->json(['message' => 'Media item deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MediaItem $mediaItem = null): array
    {
        $data = $request->validate([
            'locationId' => [
                $mediaItem ? 'sometimes' : 'required',
                'nullable',
                'integer',
                Rule::exists(Location::class, 'id'),
            ],
            'hotelId' => ['nullable', 'integer', Rule::exists(Hotel::class, 'id')],
            'src' => [$mediaItem ? 'sometimes' : 'required', 'string', 'max:2048'],
            'alt' => ['sometimes', 'string', 'max:255'],
        ]);

        $mapped = $this->mapCamel($data);

        $locationId = $mapped['location_id'] ?? $mediaItem?->location_id;
        $hotelId = array_key_exists('hotel_id', $mapped)
            ? $mapped['hotel_id']
            : $mediaItem?->hotel_id;

        if ($hotelId) {
            $hotel = Hotel::query()->find($hotelId);
            if (! $hotel) {
                throw new HttpException(422, 'Hotel not found.');
            }
            // Hotel pins the destination when location omitted / mismatched.
            if (! $locationId) {
                $mapped['location_id'] = $hotel->location_id;
            } elseif ((int) $locationId !== (int) $hotel->location_id) {
                throw new HttpException(422, 'Hotel must belong to the selected destination.');
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapCamel(array $data): array
    {
        $map = [
            'locationId' => 'location_id',
            'hotelId' => 'hotel_id',
        ];

        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $data[$snake] = $data[$camel];
                unset($data[$camel]);
            }
        }

        return $data;
    }

    private function authorizeSee(Request $request, MediaItem $item): void
    {
        if (! $this->canSee($request, $item)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    private function canSee(Request $request, MediaItem $item): bool
    {
        if ($this->isDeveloper($request)) {
            return true;
        }

        $admin = $this->actingAdmin($request);
        if (! $admin) {
            return false;
        }

        if ($this->access->isGlobal($admin)) {
            return true;
        }

        if ($item->hotel_id) {
            return $this->access->canAccessHotel($admin, $item->hotel_id);
        }

        if ($item->location_id) {
            return $this->access->canAccessLocation($admin, $item->location_id);
        }

        // Unscoped legacy assets — global seats only (already returned above).
        return false;
    }

    private function assertScope(Request $request, mixed $locationId, mixed $hotelId): void
    {
        if ($hotelId && ! $this->adminCanAccessHotel($request, $hotelId)) {
            throw new HttpException(404, 'Not found.');
        }

        if ($locationId && ! $this->adminCanAccessLocation($request, $locationId)) {
            throw new HttpException(404, 'Not found.');
        }
    }
}
