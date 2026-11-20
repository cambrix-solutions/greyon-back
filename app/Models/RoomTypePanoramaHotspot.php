<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clickable jump from one 360° photo to another in the same room type.
 */
class RoomTypePanoramaHotspot extends Model
{
    protected $fillable = [
        'panorama_id',
        'target_panorama_id',
        'yaw',
        'pitch',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'panorama_id' => 'integer',
            'target_panorama_id' => 'integer',
            'yaw' => 'float',
            'pitch' => 'float',
        ];
    }

    /**
     * @return BelongsTo<RoomTypePanorama, $this>
     */
    public function panorama(): BelongsTo
    {
        return $this->belongsTo(RoomTypePanorama::class, 'panorama_id');
    }

    /**
     * @return BelongsTo<RoomTypePanorama, $this>
     */
    public function targetPanorama(): BelongsTo
    {
        return $this->belongsTo(RoomTypePanorama::class, 'target_panorama_id');
    }
}
