<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of roles + features, assembled by a developer and
 * assigned to admins (`admin_package`) — see
 * App\Http\Controllers\Developer\PackageController. An admin's
 * effective roles/features are the union across every package they
 * hold (App\Services\AccessService).
 */
class Package extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_note',
        'is_system',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'package_role');
    }

    /**
     * @return BelongsToMany<Feature, $this>
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'package_feature')->withPivot('actions');
    }

    /**
     * The permissions this package actually grants (2026-09-16) — an
     * explicit, à la carte selection, same as `features()`/`roles()`.
     * Holding a feature does **not** automatically grant every
     * permission listed under it (`Permission::feature_id`) — that
     * relation is catalog organization only (which permissions a
     * developer sees to pick from once they've expanded a feature in
     * the package builder), not an automatic grant path. This mirrors
     * exactly how Round 8's `locations_*` sub-feature keys worked
     * before this table existed — a package always picks its subset
     * deliberately, never inherits one automatically. (As of Round 12
     * every seeded package happens to grant all 5, but that's still an
     * explicit per-package choice, not a change to this rule.) See
     * App\Services\AccessService::effectivePermissionKeys().
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'package_permission');
    }

    /**
     * @return BelongsToMany<Admin, $this>
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'admin_package');
    }

    /**
     * Quantity caps this package imposes (2026-09-16) — see
     * App\Models\PackageLimit and App\Services\AccessService::effectiveLimit().
     *
     * @return HasMany<PackageLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(PackageLimit::class);
    }
}
