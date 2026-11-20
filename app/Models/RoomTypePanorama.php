<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One 360° photo of a room type. A room type holds many; guests only see
 * the published ones, in `sort_order` (then id) order.
 */
class RoomTypePanorama extends Model
{
    protected $fillable = [
        'room_type_id',
        'path',
        'caption',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'room_type_id' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (RoomTypePanorama $panorama) {
            $panorama->hotspots()->delete();
            $panorama->incomingHotspots()->delete();
        });
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Hotspots drawn on this photo.
     *
     * @return HasMany<RoomTypePanoramaHotspot, $this>
     */
    public function hotspots(): HasMany
    {
        return $this->hasMany(RoomTypePanoramaHotspot::class, 'panorama_id')->orderBy('id');
    }

    /**
     * Hotspots on other photos that jump here.
     *
     * @return HasMany<RoomTypePanoramaHotspot, $this>
     */
    public function incomingHotspots(): HasMany
    {
        return $this->hasMany(RoomTypePanoramaHotspot::class, 'target_panorama_id');
    }

    /**
     * Absolute URL for a stored path. `path` may be a public-disk path
     * (uploads/rooms/...) or an http(s) URL.
     */
    public static function urlFor(?string $path): ?string
    {
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

    public function url(): ?string
    {
        return self::urlFor($this->path);
    }
}
