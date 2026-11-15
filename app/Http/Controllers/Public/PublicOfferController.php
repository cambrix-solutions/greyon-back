<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOfferResource;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guest Offers pages: published offers that are open for booking today.
 * Offers that need a promo code are never listed.
 */
class PublicOfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hotelSlug' => ['nullable', 'string', 'max:255'],
            'locationSlug' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Offer::query()->with(['hotel:id,name,slug', 'location:id,name,slug'])
            ->where('status', 'published')->whereNull('code');

        if (! empty($data['hotelSlug'])) {
            $hotel = Hotel::query()->where('slug', $data['hotelSlug'])->where('status', 'published')->first();
            if (! $hotel) {
                return response()->json(['offers' => []]);
            }
            $query->where(fn ($w) => $w->whereNull('hotel_id')->orWhere('hotel_id', $hotel->id))
                ->where(fn ($w) => $w->whereNull('location_id')->orWhere('location_id', $hotel->location_id));
        } elseif (! empty($data['locationSlug'])) {
            $location = Location::query()->where('slug', $data['locationSlug'])->first();
            if (! $location) {
                return response()->json(['offers' => []]);
            }
            $query->where(fn ($w) => $w->whereNull('location_id')->orWhere('location_id', $location->id))
                ->where(fn ($w) => $w->whereNull('hotel_id')->orWhereHas('hotel', fn ($h) => $h->where('location_id', $location->id)));
        }

        $offers = $query->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Offer $o) => $o->isPublic())->values();

        return response()->json(['offers' => PublicOfferResource::collection($offers)]);
    }

    public function show(string $slug): JsonResponse
    {
        $offer = Offer::query()->with(['hotel:id,name,slug', 'location:id,name,slug'])->where('slug', $slug)->first();

        if (! $offer || ! $offer->isPublic()) {
            throw new HttpException(404, 'Offer not found');
        }

        return response()->json(['offer' => new PublicOfferResource($offer)]);
    }
}
