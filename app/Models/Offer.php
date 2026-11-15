<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A special rate. Discounts the room price of a reservation (taxes and fees
 * are then worked out on the lower amount). `percent` takes that share off;
 * `fixed` takes that amount off the whole reservation, never below zero.
 * No code = applies automatically; with a code = only when the guest enters it.
 */
class Offer extends Model
{
    public const TYPES = ['percent', 'fixed'];

    protected $fillable = [
        'slug', 'title', 'summary', 'description', 'image',
        'discount_type', 'discount_value', 'code',
        'hotel_id', 'location_id',
        'book_from', 'book_to', 'stay_from', 'stay_to', 'min_nights',
        'status', 'sort_order', 'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'hotel_id' => 'integer',
            'location_id' => 'integer',
            'book_from' => 'date:Y-m-d',
            'book_to' => 'date:Y-m-d',
            'stay_from' => 'date:Y-m-d',
            'stay_to' => 'date:Y-m-d',
            'min_nights' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Published and inside its booking window today. */
    public function isLive(?string $today = null): bool
    {
        $today ??= now()->format('Y-m-d');

        return $this->status === 'published'
            && ($this->book_from === null || $this->book_from->format('Y-m-d') <= $today)
            && ($this->book_to === null || $this->book_to->format('Y-m-d') >= $today);
    }

    /** Shown on the public offers pages: live and not a secret code. */
    public function isPublic(?string $today = null): bool
    {
        return $this->code === null && $this->isLive($today);
    }

    /**
     * Does this offer apply to a stay? `$code` is the code the guest entered
     * (already upper case), or null.
     */
    public function appliesTo(Hotel $hotel, string $checkIn, string $checkOut, int $nights, ?string $code, ?string $today = null): bool
    {
        if (! $this->isLive($today)) {
            return false;
        }
        if ($this->code !== null && $this->code !== $code) {
            return false;
        }
        if ($this->hotel_id !== null && $this->hotel_id !== (int) $hotel->id) {
            return false;
        }
        if ($this->location_id !== null && $this->location_id !== (int) $hotel->location_id) {
            return false;
        }
        if ($this->min_nights !== null && $nights < $this->min_nights) {
            return false;
        }

        $lastNight = Carbon::createFromFormat('!Y-m-d', $checkOut)->subDay()->format('Y-m-d');
        if ($this->stay_from !== null && $checkIn < $this->stay_from->format('Y-m-d')) {
            return false;
        }
        if ($this->stay_to !== null && $lastNight > $this->stay_to->format('Y-m-d')) {
            return false;
        }

        return true;
    }

    /** Amount taken off a room price, two decimals, never more than the price. */
    public function discountFor(float $subtotal): float
    {
        $value = (float) $this->discount_value;
        $amount = $this->discount_type === 'percent' ? $subtotal * $value / 100 : $value;

        return round(max(0.0, min($subtotal, $amount)), 2);
    }
}
