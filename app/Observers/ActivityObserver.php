<?php

namespace App\Observers;

use App\Models\Admin;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\HeroSlide;
use App\Models\Location;
use App\Models\MediaItem;
use App\Models\News;
use App\Models\Offer;
use App\Models\Package;
use App\Models\Page;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records create / update / delete of admin-managed records, but only when a
 * signed-in admin or developer made the change (seeders, guests and system
 * jobs are not logged). Passwords, tokens and ids from other services are
 * never stored. A problem here never breaks the change being logged.
 */
class ActivityObserver
{
    /** Model => the subject name used in the log and its filter. */
    public const SUBJECTS = [
        Location::class => 'location',
        Hotel::class => 'hotel',
        RoomType::class => 'room_type',
        RatePlan::class => 'rate_plan',
        Booking::class => 'reservation',
        Offer::class => 'offer',
        Page::class => 'page',
        News::class => 'news',
        Service::class => 'service',
        Product::class => 'product',
        HeroSlide::class => 'hero_slide',
        MediaItem::class => 'media',
        SiteSetting::class => 'site_settings',
        Admin::class => 'person',
        Package::class => 'role',
    ];

    private const IGNORE = ['created_at', 'updated_at', 'remember_token'];

    private const HIDDEN = '/password|token|secret|api_key|google_id/i';

    public function created(Model $model): void
    {
        $this->write($model, 'created', null);
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach ($model->getChanges() as $field => $to) {
            if (in_array($field, self::IGNORE, true)) {
                continue;
            }

            $hidden = (bool) preg_match(self::HIDDEN, $field);
            $changes[$field] = [
                'from' => $hidden ? '(hidden)' : $this->show($model->getOriginal($field)),
                'to' => $hidden ? '(hidden)' : $this->show($to),
            ];
        }

        if ($changes !== []) {
            $this->write($model, 'updated', $changes);
        }
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', null);
    }

    /** @param array<string, array{from: mixed, to: mixed}>|null $changes */
    private function write(Model $model, string $action, ?array $changes): void
    {
        try {
            $actor = $this->actor();
            if ($actor === null) {
                return;
            }

            [$hotelId, $locationId] = $this->scopeOf($model);

            ActivityLog::create([
                'actor_type' => $actor[0],
                'actor_id' => $actor[1],
                'actor_name' => $actor[2],
                'action' => $action,
                'subject_type' => self::SUBJECTS[$model::class] ?? class_basename($model),
                'subject_id' => $model->getKey(),
                'subject_label' => $this->label($model),
                'hotel_id' => $hotelId,
                'location_id' => $locationId,
                'changes' => $changes,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{0: string, 1: int, 2: string}|null */
    private function actor(): ?array
    {
        foreach (['admin', 'developer'] as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();

                return [$guard, (int) $user->getAuthIdentifier(), (string) ($user->name ?? $user->email ?? '')];
            }
        }

        return null;
    }

    private function label(Model $model): string
    {
        $label = $model->getAttribute('title')
            ?? $model->getAttribute('name')
            ?? $model->getAttribute('reference')
            ?? $model->getAttribute('site_name')
            ?? '#'.$model->getKey();

        return mb_substr((string) $label, 0, 255);
    }

    /**
     * Which hotel / destination a change belongs to. Records that belong to
     * neither (site settings, services, ...) are visible to global seats only.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function scopeOf(Model $model): array
    {
        $hotelId = match (true) {
            $model instanceof Hotel => $model->getKey(),
            $model instanceof RoomType, $model instanceof Booking, $model instanceof Offer, $model instanceof MediaItem => $model->getAttribute('hotel_id'),
            $model instanceof RatePlan => RoomType::query()->whereKey($model->getAttribute('room_type_id'))->value('hotel_id'),
            default => null,
        };

        $locationId = match (true) {
            $model instanceof Location => $model->getKey(),
            $model instanceof Hotel, $model instanceof News, $model instanceof Offer, $model instanceof MediaItem => $model->getAttribute('location_id'),
            default => null,
        };

        if ($locationId === null && $hotelId !== null) {
            $locationId = Hotel::query()->whereKey($hotelId)->value('location_id');
        }

        return [$hotelId !== null ? (int) $hotelId : null, $locationId !== null ? (int) $locationId : null];
    }

    /** Keeps the log readable: long text is shortened, lists and objects are summarised. */
    private function show(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return '(list)';
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return '(list)';
            }

            return mb_strlen($value) > 120 ? mb_substr($value, 0, 120).'…' : $value;
        }

        return $value;
    }
}
