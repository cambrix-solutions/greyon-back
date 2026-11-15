<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminInvite extends Model
{
    public const TTL_DAYS = 7;

    protected $fillable = [
        'email', 'name', 'token_hash', 'packages',
        'invited_by_admin_id', 'admin_id',
        'expires_at', 'accepted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return array{0: string, 1: string} [plainToken, hash] - the plain token is never stored. */
    public static function newToken(): array
    {
        $plain = Str::random(64);

        return [$plain, self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByToken(string $plain): ?self
    {
        return self::where('token_hash', self::hash($plain))->first();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function isPending(): bool
    {
        return $this->status() === 'pending';
    }

    /** @return BelongsTo<Admin, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'invited_by_admin_id');
    }
}
