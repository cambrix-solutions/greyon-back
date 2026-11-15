<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Page
 */
class PageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'blocks' => $this->blocks ?? [],
            'seoTitle' => $this->seo_title,
            'seoDescription' => $this->seo_description,
            'status' => $this->status,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'isSystem' => (bool) $this->is_system,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
