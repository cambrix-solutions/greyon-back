<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
     * Absolute URL for the optional 360° panorama, or null when unset.
     * `panorama_path` may be a public-disk path (uploads/rooms/...) or an http(s) URL.
     */
    public function panoramaUrl(): ?string
    {
        $path = $this->panorama_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $url = Storage::disk('public')->url(ltrim($path, '/'));
        if (! str_starts_with($url, 'http')) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return $url;
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
