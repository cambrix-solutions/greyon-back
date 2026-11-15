<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination (Phnom Penh, Kampot, ...). Scope is enforced via
 * App\Services\AccessService::canAccessLocation() against a
 * location-scoped package's `location_ids` (App\Models\AdminPackage,
 * the `admin_package` pivot) — this model carries no manager/owner
 * column itself.
 *
 * `created_by_admin_id` (2026-09-16) is attribution only, used by
 * AccessService::effectiveLimit()'s `locations` cap ("this admin may
 * create up to N locations") — never set by request input, always by
 * the controller from the authenticated admin. Nullable: locations
 * seeded before this column existed (or created any other way) simply
 * don't count against anyone's quota.
 */
class Location extends Model
{
    protected $fillable = [
        'created_by_admin_id',
        'name',
        'slug',
        'description',
        'phone',
        'email',
        'hero_image',
        'gallery',
        'highlights',
        'landmarks',
        'status',
        'seo_title',
        'seo_description',
    ];

    protected function casts(): array
    {
        return [
            'created_by_admin_id' => 'integer',
            'gallery' => 'array',
            'highlights' => 'array',
            'landmarks' => 'array',
        ];
    }

    /**
     * @return HasMany<Hotel, $this>
     */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
