<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Guest or admin-created reservation.
 * Scope via hotel_id through AccessService::canAccessHotel().
 */
class Booking extends Model
{
    protected $fillable = [
        'user_id',
        'reference',
        'hotel_id',
        'room_type_id',
        'rate_plan_id',
        'check_in',
        'check_out',
        'rooms',
        'adults',
        'children',
        'guest_full_name',
        'guest_email',
        'guest_phone',
        'special_requests',
        'subtotal',
        'taxes_fees',
        'total',
        'status',
        'source',
        'payment_method',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'hotel_id' => 'integer',
            'room_type_id' => 'integer',
            'rate_plan_id' => 'integer',
            'check_in' => 'date:Y-m-d',
            'check_out' => 'date:Y-m-d',
            'rooms' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal' => 'decimal:2',
            'taxes_fees' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    /** Number of nights between check-in and check-out. */
    public function nights(): int
    {
        if (! $this->check_in || ! $this->check_out) {
            return 0;
        }

        return max(0, (int) $this->check_in->diffInDays($this->check_out));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }
}
