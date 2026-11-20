<?php

namespace App\Http\Resources;

use App\Models\RoomTypePanoramaHotspot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomTypePanoramaHotspot
 */
class RoomTypePanoramaHotspotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'yaw' => $this->yaw,
            'pitch' => $this->pitch,
            'label' => $this->label,
            'targetPanoramaId' => $this->target_panorama_id,
        ];
    }
}
