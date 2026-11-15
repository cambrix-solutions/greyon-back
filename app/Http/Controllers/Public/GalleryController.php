<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public photo gallery. Media the staff left in the gallery, newest first.
 * Filter by `location` / `hotel` slug. Items of an unpublished destination or
 * hotel are never shown.
 */
class GalleryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:191'],
            'hotel' => ['nullable', 'string', 'max:191'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = MediaItem::query()
            ->where('in_gallery', true)
            ->whereHas('location', fn ($q) => $q->where('status', 'published'))
            ->where(fn ($q) => $q->whereNull('hotel_id')
                ->orWhereHas('hotel', fn ($h) => $h->where('status', 'published')))
            ->with(['location:id,slug,name', 'hotel:id,slug,name']);

        if (! empty($data['location'])) {
            $location = Location::query()->where('slug', $data['location'])->where('status', 'published')->first();
            abort_if(! $location, 404);
            $query->where('location_id', $location->id);
        }
        if (! empty($data['hotel'])) {
            $hotel = Hotel::query()->where('slug', $data['hotel'])->where('status', 'published')->first();
            abort_if(! $hotel, 404);
            $query->where('hotel_id', $hotel->id);
        }

        $perPage = (int) ($data['perPage'] ?? 24);
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'gallery' => $paginator->getCollection()->map(fn (MediaItem $m) => [
                'id' => $m->id,
                'src' => $m->src,
                'alt' => $m->alt ?? '',
                'location' => $m->location ? ['slug' => $m->location->slug, 'name' => $m->location->name] : null,
                'hotel' => $m->hotel ? ['slug' => $m->hotel->slug, 'name' => $m->hotel->name] : null,
            ])->values(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }
}
