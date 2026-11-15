<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'heroImage' => $this->hero_image,
            'gallery' => $this->gallery ?? [],
            'highlights' => $this->highlights ?? [],
            'landmarks' => collect($this->landmarks ?? [])
                ->map(fn ($row) => [
                    'place' => is_array($row) ? ($row['place'] ?? '') : '',
                    'distance' => is_array($row) ? ($row['distance'] ?? '') : '',
                ])
                ->filter(fn ($row) => filled($row['place']))
                ->values()
                ->all(),
            'status' => $this->status,
            'seoTitle' => $this->seo_title,
            'seoDescription' => $this->seo_description,
            'hotelCount' => $this->whenCounted('hotels'),
            'createdByAdminId' => $this->created_by_admin_id,
        ];
    }
}
