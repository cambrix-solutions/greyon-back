<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One recorded change. Written by App\Observers\ActivityObserver, never edited. */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_type', 'actor_id', 'actor_name', 'action',
        'subject_type', 'subject_id', 'subject_label',
        'hotel_id', 'location_id', 'changes',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'hotel_id' => 'integer',
            'location_id' => 'integer',
            'changes' => 'array',
        ];
    }
}
