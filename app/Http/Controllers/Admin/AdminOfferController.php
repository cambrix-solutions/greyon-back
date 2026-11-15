<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Offer;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec "Offers" - perm `offers`. An offer is tied to a hotel, a destination,
 * or everywhere. Hotel / destination seats only handle offers inside their
 * scope (out of scope is a 404); "everywhere" offers need a global seat.
 */
class AdminOfferController extends Controller
{
    use AuthorizesAdminPanel;

    private const MAP = [
        'title' => 'title', 'slug' => 'slug', 'summary' => 'summary', 'description' => 'description',
        'image' => 'image', 'discountType' => 'discount_type', 'discountValue' => 'discount_value',
        'code' => 'code', 'hotelId' => 'hotel_id', 'locationId' => 'location_id',
        'bookFrom' => 'book_from', 'bookTo' => 'book_to', 'stayFrom' => 'stay_from', 'stayTo' => 'stay_to',
        'minNights' => 'min_nights', 'status' => 'status', 'sortOrder' => 'sort_order',
    ];

    public function __construct(private readonly AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $offers = Offer::query()
            ->with(['hotel:id,name', 'location:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('sort_order')->orderByDesc('id')
            ->get()
            ->filter(fn (Offer $o) => $this->inScope($request, $o))
            ->values();

        return response()->json(['offers' => OfferResource::collection($offers)]);
    }

    public function show(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeScope($request, $offer);

        return response()->json(['offer' => new OfferResource($offer->load(['hotel:id,name', 'location:id,name']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertWritable($request, $data['hotel_id'] ?? null, $data['location_id'] ?? null);

        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['title']);
        $data['status'] = $data['status'] ?? 'draft';
        $data['created_by_admin_id'] = $this->actingAdmin($request)?->id;

        $offer = Offer::create($data);

        return response()->json(['offer' => new OfferResource($offer->refresh()->load(['hotel:id,name', 'location:id,name']))], 201);
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeScope($request, $offer);

        $data = $this->validated($request, $offer);
        $this->assertWritable(
            $request,
            array_key_exists('hotel_id', $data) ? $data['hotel_id'] : $offer->hotel_id,
            array_key_exists('location_id', $data) ? $data['location_id'] : $offer->location_id,
        );

        $offer->update($data);

        return response()->json(['offer' => new OfferResource($offer->refresh()->load(['hotel:id,name', 'location:id,name']))]);
    }

    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeScope($request, $offer);
        $offer->delete();

        return response()->json(['message' => 'Offer deleted.']);
    }

    private function inScope(Request $request, Offer $offer): bool
    {
        if ($offer->hotel_id !== null) {
            return $this->adminCanAccessHotel($request, $offer->hotel_id);
        }
        if ($offer->location_id !== null) {
            return $this->adminCanAccessLocation($request, $offer->location_id);
        }

        return $this->isDeveloper($request) || $this->access->isGlobal($this->requireAdmin($request));
    }

    private function authorizeScope(Request $request, Offer $offer): void
    {
        if (! $this->inScope($request, $offer)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    /** Where the offer will end up must be inside this seat's scope. */
    private function assertWritable(Request $request, ?int $hotelId, ?int $locationId): void
    {
        if ($hotelId !== null) {
            if (! $this->adminCanAccessHotel($request, $hotelId)) {
                throw new HttpException(404, 'Not found.');
            }

            return;
        }
        if ($locationId !== null) {
            if (! $this->adminCanAccessLocation($request, $locationId)) {
                throw new HttpException(404, 'Not found.');
            }

            return;
        }

        $this->assertGlobalSeat($request);
    }

    /** @return array<string, mixed> snake_case, only the keys that were sent */
    private function validated(Request $request, ?Offer $offer = null): array
    {
        $req = $offer ? 'sometimes' : 'required';

        $input = $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('offers', 'slug')->ignore($offer?->id)],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'image' => ['nullable', 'string', 'max:500'],
            'discountType' => [$req, Rule::in(Offer::TYPES)],
            'discountValue' => [$req, 'numeric', 'gt:0', 'max:99999999'],
            'code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{3,32}$/'],
            'hotelId' => ['nullable', 'integer', Rule::exists(Hotel::class, 'id')],
            'locationId' => ['nullable', 'integer', Rule::exists(Location::class, 'id')],
            'bookFrom' => ['nullable', 'date_format:Y-m-d'],
            'bookTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bookFrom'],
            'stayFrom' => ['nullable', 'date_format:Y-m-d'],
            'stayTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:stayFrom'],
            'minNights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'sortOrder' => ['sometimes', 'integer', 'between:-9999,9999'],
        ]);

        $data = [];
        foreach (self::MAP as $camel => $snake) {
            if (array_key_exists($camel, $input)) {
                $data[$snake] = $input[$camel];
            }
        }

        if (array_key_exists('code', $data) && $data['code'] !== null) {
            $data['code'] = strtoupper($data['code']);
            $taken = Offer::query()->where('code', $data['code'])->when($offer, fn ($q) => $q->where('id', '!=', $offer->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => 'This code is already used by another offer.']);
            }
        }

        $type = $data['discount_type'] ?? $offer?->discount_type;
        $value = (float) ($data['discount_value'] ?? $offer?->discount_value);
        if ($type === 'percent' && $value > 100) {
            throw ValidationException::withMessages(['discountValue' => 'A percentage cannot be more than 100.']);
        }

        $hotelId = array_key_exists('hotel_id', $data) ? $data['hotel_id'] : $offer?->hotel_id;
        $locationId = array_key_exists('location_id', $data) ? $data['location_id'] : $offer?->location_id;
        if ($hotelId !== null && $locationId !== null && (int) Hotel::query()->whereKey($hotelId)->value('location_id') !== (int) $locationId) {
            throw ValidationException::withMessages(['locationId' => 'That hotel is not in this destination.']);
        }

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'offer';
        $slug = $base;
        for ($i = 2; Offer::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
