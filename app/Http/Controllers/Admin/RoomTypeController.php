<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec section 8 "Room types" — perm `rooms`
 * (`permission:rooms` on every route in routes/admin.php).
 *
 * Scope via `canAccessHotel` against the room type's `hotel_id` — same
 * pattern as HotelController, out-of-scope → 404 not 403 (don't leak
 * existence).
 *
 * store()/destroy() authorization (2026-09-15 policy — see
 * LocationController/HotelController for the same change made at the
 * same time): a developer can always create (bypasses this
 * controller's `permission:` gate entirely). An admin can create iff
 * their effective packages grant the `rooms` feature — already
 * enforced by the route's `permission:rooms` middleware before this
 * method even runs, so store() adds no further scope check on top of
 * it (deliberately not `canAccessHotel($admin, $data['hotel_id'])`
 * here — holding the feature is sufficient, same relaxation applied to
 * Location/Hotel create). destroy() stays global-seats-only, same
 * reasoning as Location/Hotel: not something this round's instruction
 * touched.
 *
 * `panorama` (multipart file, jpg/webp, max 15 MB) is an optional 360°
 * image. Equirectangular 2:1 is recommended and not enforced. The file
 * is stored on the public disk under `uploads/rooms/{id}/`, the same
 * disk as seeded room photos and the same uploads/ prefix as other
 * admin images. `remove_panorama=1` on update clears it. A new upload
 * replaces any existing file.
 */
class RoomTypeController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(private readonly AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $roomTypes = RoomType::with('hotel')
            ->get()
            ->filter(fn (RoomType $roomType) => $this->adminCanAccessHotel($request, $roomType->hotel_id))
            ->values();

        return response()->json(['roomTypes' => RoomTypeResource::collection($roomTypes)]);
    }

    public function show(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeScope($request, $roomType);

        return response()->json(['roomType' => new RoomTypeResource($roomType->load('hotel'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $roomType = RoomType::create($this->validated($request));
        $this->applyPanorama($request, $roomType);

        // Re-fetch so DB column defaults not present in the request
        // (e.g. status, maxAdults) are reflected in the response —
        // Eloquent's create() doesn't otherwise pick those up on the
        // in-memory instance.
        return response()->json(['roomType' => new RoomTypeResource($roomType->refresh()->load('hotel'))], 201);
    }

    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeScope($request, $roomType);

        $roomType->update($this->validated($request, $roomType));
        $this->applyPanorama($request, $roomType);

        return response()->json(['roomType' => new RoomTypeResource($roomType->refresh()->load('hotel'))]);
    }

    public function destroy(Request $request, RoomType $roomType): JsonResponse
    {
        $this->assertGlobalSeat($request);

        $this->deletePanoramaFile($roomType->panorama_path);
        $roomType->delete();

        return response()->json(['message' => 'Room type deleted.']);
    }

    private function authorizeScope(Request $request, RoomType $roomType): void
    {
        if (! $this->adminCanAccessHotel($request, $roomType->hotel_id)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?RoomType $roomType = null): array
    {
        if ($request->has('images') && is_array($request->input('images'))) {
            $request->merge([
                'images' => array_values(array_filter(
                    $request->input('images'),
                    fn ($src) => is_string($src)
                        && $src !== ''
                        && ! str_starts_with($src, 'data:')
                        && strlen($src) <= 2048
                )),
            ]);
        }

        $data = $request->validate([
            'hotelId' => [$roomType ? 'sometimes' : 'required', 'integer', Rule::exists(Hotel::class, 'id')],
            'name' => [$roomType ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [
                $roomType ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('room_types', 'slug')
                    ->where('hotel_id', $request->input('hotelId', $roomType?->hotel_id))
                    ->ignore($roomType?->id),
            ],
            'description' => ['nullable', 'string'],
            'images' => ['array'],
            'images.*' => ['string', 'max:2048'],
            // jpg/webp, 15 MB. 2:1 equirectangular is recommended, not required.
            'panorama' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,webp', 'max:15360'],
            'remove_panorama' => ['sometimes', 'boolean'],
            'bedType' => ['nullable', 'string', 'max:255'],
            'roomSize' => ['nullable', 'string', 'max:255'],
            'maxAdults' => ['sometimes', 'integer', 'min:1'],
            'maxChildren' => ['sometimes', 'integer', 'min:0'],
            'maxGuests' => ['sometimes', 'integer', 'min:1'],
            'amenities' => ['array'],
            'amenities.*' => ['string'],
            'baseInventory' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]);

        unset($data['panorama'], $data['remove_panorama']);

        return $this->mapCamel($data);
    }

    /**
     * Store a new panorama, or clear the current one. A file wins over
     * `remove_panorama` when both are sent.
     */
    private function applyPanorama(Request $request, RoomType $roomType): void
    {
        if ($request->hasFile('panorama')) {
            $this->deletePanoramaFile($roomType->panorama_path);
            $roomType->update([
                'panorama_path' => $this->storePanorama($request->file('panorama'), $roomType),
            ]);

            return;
        }

        if ($request->boolean('remove_panorama')) {
            $this->deletePanoramaFile($roomType->panorama_path);
            $roomType->update(['panorama_path' => null]);
        }
    }

    private function storePanorama(UploadedFile $file, RoomType $roomType): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (! in_array($ext, ['jpg', 'webp'], true)) {
            $ext = 'jpg';
        }

        $path = $file->storeAs('uploads/rooms/'.$roomType->id, Str::uuid()->toString().'.'.$ext, 'public');

        if (! $path) {
            throw ValidationException::withMessages([
                'panorama' => 'Upload failed. Please try again.',
            ]);
        }

        return $path;
    }

    private function deletePanoramaFile(?string $path): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return;
        }

        $path = ltrim($path, '/');
        if (! str_starts_with($path, 'uploads/rooms/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapCamel(array $data): array
    {
        $map = [
            'hotelId' => 'hotel_id',
            'bedType' => 'bed_type',
            'roomSize' => 'room_size',
            'maxAdults' => 'max_adults',
            'maxChildren' => 'max_children',
            'maxGuests' => 'max_guests',
            'baseInventory' => 'base_inventory',
        ];

        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $data[$snake] = $data[$camel];
                unset($data[$camel]);
            }
        }

        return $data;
    }
}
