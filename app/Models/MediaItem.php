<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Media library asset. Scoped to a destination (location); optionally
 * pinned to a hotel under that destination. Attribution via
 * created_by_admin_id (nullOnDelete).
 */
class MediaItem extends Model
{
    protected $fillable = [
        'location_id',
        'hotel_id',
        'src',
        'alt',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'hotel_id' => 'integer',
            'created_by_admin_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
