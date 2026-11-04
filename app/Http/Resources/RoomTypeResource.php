<?php

namespace App\Http\Resources;

use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomType
 */
class RoomTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotelId' => $this->hotel_id,
            'hotel' => $this->whenLoaded('hotel', fn () => [
                'id' => $this->hotel->id,
                'name' => $this->hotel->name,
                'slug' => $this->hotel->slug,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'images' => $this->images ?? [],
            'panoramaUrl' => $this->panoramaUrl(),
            'bedType' => $this->bed_type,
            'roomSize' => $this->room_size,
            'maxAdults' => $this->max_adults,
            'maxChildren' => $this->max_children,
            'maxGuests' => $this->max_guests,
            'amenities' => $this->amenities ?? [],
            'baseInventory' => $this->base_inventory,
            'status' => $this->status,
        ];
    }
}
