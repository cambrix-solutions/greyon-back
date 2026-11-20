<?php

namespace App\Http\Resources;

use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * camelCase shape for an admin-panel account. Deliberately minimal:
 * "each user just has the packages assigned by developer" — there's no
 * top-level `roles`/`featureKeys` (each package entry below already
 * carries its own, same as `PackageResource`) and no top-level
 * `locationIds`/`hotelIds` (each package *assignment* carries its own
 * scope on the `admin_package` pivot — see App\Models\AdminPackage —
 * since the same package can be scoped differently for different
 * admins, or an admin can hold several differently-scoped packages at
 * once). See App\Services\AccessService for how these are actually
 * used to compute access.
 *
 * @mixin \App\Models\Admin
 */
class AdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'createdByAdminId' => $this->created_by_admin_id,
            'packages' => $this->whenLoaded(
                'packages',
                fn () => $this->packages->map(fn ($package) => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'roles' => $package->relationLoaded('roles')
                        ? RoleResource::collection($package->roles)
                        : [],
                    'featureKeys' => $package->relationLoaded('features')
                        ? $package->features->pluck('key')->values()
                        : [],
                    'featureActions' => $package->relationLoaded('features')
                        ? (object) $package->features
                            ->mapWithKeys(fn ($f) => [$f->key => AccessService::normaliseActions($f->pivot?->actions)])
                            ->all()
                        : (object) [],
                    'permissionKeys' => $package->relationLoaded('permissions')
                        ? $package->permissions->pluck('key')->values()
                        : [],
                    'locationIds' => $package->pivot->location_ids ?? [],
                    'hotelIds' => $package->pivot->hotel_ids ?? [],
                ])->values()
            ),
        ];
    }
}
