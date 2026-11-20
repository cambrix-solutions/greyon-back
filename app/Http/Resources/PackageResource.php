<?php

namespace App\Http\Resources;

use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'priceNote' => $this->price_note,
            'isSystem' => (bool) $this->is_system,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'featureKeys' => $this->whenLoaded('features', fn () => $this->features->pluck('key')->values()),
            // Feature key => actions (view/create/edit/delete) this seat grants.
            'featureActions' => $this->whenLoaded('features', fn () => (object) $this->features
                ->mapWithKeys(fn ($f) => [$f->key => AccessService::normaliseActions($f->pivot?->actions)])
                ->all()),
            'permissionKeys' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('key')->values()),
            'limits' => $this->whenLoaded('limits', fn () => $this->limits->map(fn ($limit) => [
                'resourceKey' => $limit->resource_key,
                'maxCount' => $limit->max_count,
            ])->values()),
            'adminCount' => $this->whenCounted('admins'),
        ];
    }
}
