<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoomTypePanoramaHotspotResource;
use App\Models\RoomType;
use App\Models\RoomTypePanorama;
use App\Models\RoomTypePanoramaHotspot;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Clickable jumps between 360° photos of one room type — perm `rooms`
 * (`permission:rooms` on every route in routes/admin.php). POST create,
 * PATCH edit, DELETE delete, same as the panorama endpoints.
 *
 * Scope follows RoomTypePanoramaController: a room type outside the
 * admin's hotels answers 404. Every write returns the photo's full
 * hotspot list so the admin UI can replace its state.
 */
class RoomTypePanoramaHotspotController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(
        private readonly AccessService $access,
    ) {}

    public function index(Request $request, RoomType $roomType, RoomTypePanorama $panorama): JsonResponse
    {
        $this->authorizePhoto($request, $roomType, $panorama);

        return $this->listing($panorama);
    }

    public function store(Request $request, RoomType $roomType, RoomTypePanorama $panorama): JsonResponse
    {
        $this->authorizePhoto($request, $roomType, $panorama);

        $data = $request->validate([
            'targetPanoramaId' => ['required', 'integer'],
            'yaw' => ['required', 'numeric', 'between:-180,180'],
            'pitch' => ['required', 'numeric', 'between:-90,90'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $target = $this->resolveTarget($roomType, $panorama, (int) $data['targetPanoramaId']);

        $panorama->hotspots()->create([
            'target_panorama_id' => $target->id,
            'yaw' => $data['yaw'],
            'pitch' => $data['pitch'],
            'label' => $this->nullableText($data['label'] ?? null),
        ]);

        return $this->listing($panorama, 201);
    }

    public function update(
        Request $request,
        RoomType $roomType,
        RoomTypePanorama $panorama,
        RoomTypePanoramaHotspot $hotspot,
    ): JsonResponse {
        $this->authorizePhoto($request, $roomType, $panorama);
        $this->assertBelongs($panorama, $hotspot);

        $data = $request->validate([
            'targetPanoramaId' => ['sometimes', 'integer'],
            'yaw' => ['sometimes', 'numeric', 'between:-180,180'],
            'pitch' => ['sometimes', 'numeric', 'between:-90,90'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        if ($data === []) {
            abort(422, 'Provide a target, position or label.');
        }

        $updates = [];
        if (array_key_exists('targetPanoramaId', $data)) {
            $updates['target_panorama_id'] = $this->resolveTarget(
                $roomType,
                $panorama,
                (int) $data['targetPanoramaId']
            )->id;
        }
        if (array_key_exists('yaw', $data)) {
            $updates['yaw'] = $data['yaw'];
        }
        if (array_key_exists('pitch', $data)) {
            $updates['pitch'] = $data['pitch'];
        }
        if (array_key_exists('label', $data)) {
            $updates['label'] = $this->nullableText($data['label']);
        }

        $hotspot->update($updates);

        return $this->listing($panorama);
    }

    public function destroy(
        Request $request,
        RoomType $roomType,
        RoomTypePanorama $panorama,
        RoomTypePanoramaHotspot $hotspot,
    ): JsonResponse {
        $this->authorizePhoto($request, $roomType, $panorama);
        $this->assertBelongs($panorama, $hotspot);

        $hotspot->delete();

        return $this->listing($panorama);
    }

    private function listing(RoomTypePanorama $panorama, int $status = 200): JsonResponse
    {
        return response()->json([
            'hotspots' => RoomTypePanoramaHotspotResource::collection(
                $panorama->hotspots()->get()
            )->resolve(),
        ], $status);
    }

    private function authorizePhoto(Request $request, RoomType $roomType, RoomTypePanorama $panorama): void
    {
        if (! $this->adminCanAccessHotel($request, $roomType->hotel_id)) {
            throw new HttpException(404, 'Not found.');
        }

        if ($panorama->room_type_id !== $roomType->id) {
            throw new HttpException(404, 'Not found.');
        }
    }

    private function assertBelongs(RoomTypePanorama $panorama, RoomTypePanoramaHotspot $hotspot): void
    {
        if ($hotspot->panorama_id !== $panorama->id) {
            throw new HttpException(404, 'Not found.');
        }
    }

    private function resolveTarget(RoomType $roomType, RoomTypePanorama $panorama, int $targetId): RoomTypePanorama
    {
        if ($targetId === $panorama->id) {
            abort(422, 'A hotspot cannot point at the same photo.');
        }

        $target = RoomTypePanorama::query()
            ->where('room_type_id', $roomType->id)
            ->whereKey($targetId)
            ->first();

        if (! $target) {
            abort(422, 'The target photo must belong to this room.');
        }

        return $target;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
