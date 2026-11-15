<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a guest sees on the Offers pages. Never the code, never a draft.
 *
 * @mixin \App\Models\Offer
 */
class PublicOfferResource extends JsonResource
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
            'hotel' => $this->whenLoaded('hotel', fn () => $this->hotel ? ['name' => $this->hotel->name, 'slug' => $this->hotel->slug] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['name' => $this->location->name, 'slug' => $this->location->slug] : null),
            'bookUntil' => $this->book_to?->format('Y-m-d'),
            'stayFrom' => $this->stay_from?->format('Y-m-d'),
            'stayTo' => $this->stay_to?->format('Y-m-d'),
            'minNights' => $this->min_nights,
        ];
    }
}
