<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of an offer (includes the promo code and drafts).
 *
 * @mixin \App\Models\Offer
 */
class OfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'description' => $this->description,
            'image' => $this->image,
            'discountType' => $this->discount_type,
            'discountValue' => (float) $this->discount_value,
            'code' => $this->code,
            'hotelId' => $this->hotel_id,
            'hotelName' => $this->whenLoaded('hotel', fn () => $this->hotel?->name),
            'locationId' => $this->location_id,
            'locationName' => $this->whenLoaded('location', fn () => $this->location?->name),
            'bookFrom' => $this->book_from?->format('Y-m-d'),
            'bookTo' => $this->book_to?->format('Y-m-d'),
            'stayFrom' => $this->stay_from?->format('Y-m-d'),
            'stayTo' => $this->stay_to?->format('Y-m-d'),
            'minNights' => $this->min_nights,
            'status' => $this->status,
            'live' => $this->isLive(),
            'sortOrder' => $this->sort_order,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
