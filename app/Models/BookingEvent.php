<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class BookingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note',
    ];

    /**
     * Write a timeline row, attributing it to whoever is signed in (admin,
     * developer or guest); outside a request - seeders, jobs - it is `system`.
     * Never lets a timeline problem break a reservation.
     */
    public static function record(Booking $booking, string $type, ?string $from, ?string $to, ?string $note = null): ?self
    {
        try {
            [$actorType, $actorId] = match (true) {
                Auth::guard('admin')->check() => ['admin', Auth::guard('admin')->id()],
                Auth::guard('developer')->check() => ['developer', Auth::guard('developer')->id()],
                Auth::guard('web')->check() => ['guest', Auth::guard('web')->id()],
                default => [$type === 'created' && $booking->source === 'website' ? 'guest' : 'system', null],
            };
        } catch (\Throwable) {
            [$actorType, $actorId] = ['system', null];
        }

        try {
            return self::create([
                'booking_id' => $booking->id,
                'type' => $type,
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'note' => $note,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
