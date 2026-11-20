<?php

namespace App\Http\Resources;

use App\Models\RoomTypePanorama;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomTypePanorama
 */
class RoomTypePanoramaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'caption' => $this->caption,
            'isPublished' => $this->is_published,
            'sortOrder' => $this->sort_order,
            'hotspots' => RoomTypePanoramaHotspotResource::collection($this->whenLoaded('hotspots')),
        ];
    }
}
