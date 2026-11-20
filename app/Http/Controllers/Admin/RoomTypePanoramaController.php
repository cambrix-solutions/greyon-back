<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoomTypePanoramaResource;
use App\Models\RoomType;
use App\Models\RoomTypePanorama;
use App\Services\AccessService;
use App\Services\RoomPanoramaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Many 360° photos per room type — perm `rooms`
 * (`permission:rooms` on every route in routes/admin.php).
 *
 * Scope follows RoomTypeController: a room type outside the admin's
 * hotels answers 404. Every write ends by re-pointing the legacy
 * `room_types.panorama_path` at the first published photo and returns
 * the full ordered list, so the admin UI can simply replace its state.
 */
class RoomTypePanoramaController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(
        private readonly AccessService $access,
        private readonly RoomPanoramaStore $store,
    ) {}

    public function index(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeScope($request, $roomType);

        return $this->listing($roomType);
    }

    /**
     * Upload one (`panorama`) or many (`panoramas[]`) files. They are
     * appended to the end of the list, published by default.
     */
    public function store(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeScope($request, $roomType);

        $request->validate([
            'panorama' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,webp', 'max:15360'],
            'panoramas' => ['sometimes', 'array', 'max:20'],
            'panoramas.*' => ['file', 'image', 'mimes:jpg,jpeg,webp', 'max:15360'],
            'isPublished' => ['sometimes', 'boolean'],
        ]);

        $files = array_filter([
            $request->file('panorama'),
            ...array_values((array) $request->file('panoramas', [])),
        ]);

        if ($files === []) {
            abort(422, 'Choose at least one 360° photo.');
        }

        $published = $request->boolean('isPublished', true);

        foreach ($files as $file) {
            $this->store->add($roomType, $file, $published);
        }

        $roomType->syncLegacyPanorama();

        return $this->listing($roomType, 201);
    }

    public function update(Request $request, RoomType $roomType, RoomTypePanorama $panorama): JsonResponse
    {
        $this->authorizeScope($request, $roomType);
        $this->assertBelongs($roomType, $panorama);

        $data = $request->validate([
            'isPublished' => ['required', 'boolean'],
        ]);

        $panorama->update(['is_published' => $data['isPublished']]);
        $roomType->syncLegacyPanorama();

        return $this->listing($roomType);
    }

    public function destroy(Request $request, RoomType $roomType, RoomTypePanorama $panorama): JsonResponse
    {
        $this->authorizeScope($request, $roomType);
        $this->assertBelongs($roomType, $panorama);

        $this->store->remove($panorama);
        $roomType->syncLegacyPanorama();

        return $this->listing($roomType);
    }

    /**
     * Body: `{"ids": [3, 1, 2]}` — the new order. Ids must belong to this
     * room type; any photo left out keeps its relative order after them.
     */
    public function reorder(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeScope($request, $roomType);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $owned = $roomType->panoramas()->pluck('id')->all();
        $ids = array_map('intval', $data['ids']);

        if (array_diff($ids, $owned) !== []) {
            abort(422, 'Some photos do not belong to this room.');
        }

        $ordered = [...$ids, ...array_values(array_diff($owned, $ids))];

        DB::transaction(function () use ($ordered, $roomType) {
            foreach ($ordered as $position => $id) {
                $roomType->panoramas()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        $roomType->syncLegacyPanorama();

        return $this->listing($roomType);
    }

    private function listing(RoomType $roomType, int $status = 200): JsonResponse
    {
        return response()->json([
            'panoramas' => RoomTypePanoramaResource::collection($roomType->panoramas()->get())->resolve(),
        ], $status);
    }

    private function authorizeScope(Request $request, RoomType $roomType): void
    {
        if (! $this->adminCanAccessHotel($request, $roomType->hotel_id)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    private function assertBelongs(RoomType $roomType, RoomTypePanorama $panorama): void
    {
        if ($panorama->room_type_id !== $roomType->id) {
            throw new HttpException(404, 'Not found.');
        }
    }
}
