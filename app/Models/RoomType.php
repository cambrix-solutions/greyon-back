<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room category under a Hotel ("Deluxe King", "Standard Twin", ...)
 * — top of the `Hotel → RoomType → RatePlan/Availability/RateCalendar/
 * Booking` chain (spec section 4). Scope is enforced via `hotel_id`
 * through App\Services\AccessService::canAccessHotel() — same pattern
 * as App\Models\Hotel, no scope columns of its own.
 */
class RoomType extends Model
{
    protected $fillable = [
        'hotel_id',
        'name',
        'slug',
        'description',
        'images',
        'panorama_path',
        'bed_type',
        'room_size',
        'max_adults',
        'max_children',
        'max_guests',
        'amenities',
        'base_inventory',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'hotel_id' => 'integer',
            'images' => 'array',
            'amenities' => 'array',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'max_guests' => 'integer',
            'base_inventory' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Absolute URL for the legacy single 360° photo, or null when unset.
     * The column mirrors the first published panorama (see
     * syncLegacyPanorama) so older clients keep working.
     */
    public function panoramaUrl(): ?string
    {
        return RoomTypePanorama::urlFor($this->panorama_path);
    }

    /**
     * All 360° photos, in the order guests see them.
     *
     * @return HasMany<RoomTypePanorama, $this>
     */
    public function panoramas(): HasMany
    {
        return $this->hasMany(RoomTypePanorama::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Point the legacy `panorama_path` column at the first published
     * panorama (or null when none is published).
     */
    public function syncLegacyPanorama(): void
    {
        $first = $this->panoramas()->where('is_published', true)->first();

        $this->forceFill(['panorama_path' => $first?->path])->save();
    }

    /**
     * @return HasMany<RatePlan, $this>
     */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    /**
     * @return HasMany<Availability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }
}
