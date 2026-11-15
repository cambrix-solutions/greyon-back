<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An admin-panel account — everyone who isn't a `Developer` (its own
 * table/guard) or a `users` guest/manager. What an admin can actually
 * do is entirely determined by the `packages` they're assigned
 * (`admin_package`): each package grants a set of `roles` and
 * `features`, unioned across every package the admin holds, and each
 * *assignment* carries its own `location_ids`/`hotel_ids` scope (see
 * App\Models\AdminPackage) — see App\Services\AccessService. There's
 * no `role` column here on purpose, and no `location_ids`/`hotel_ids`
 * here either as of the schema-driven-scoping pass: both were tried as
 * flat columns on this table and backed out (see the migration
 * history) once it turned out scope belongs to a package assignment,
 * not the admin as a whole — an admin's real attributes beyond
 * name/email/password/status are "which packages, each with its own
 * scope" and, since 2026-09-23, `created_by_admin_id` — pure
 * attribution (nullable, `nullOnDelete`) recording which org admin
 * added this account via People/Team, so App\Http\Controllers\
 * Admin\TeamController can enforce a per-admin cap on how many people
 * they've added (App\Models\PackageLimit resource key `managers`,
 * same pattern as `locations.created_by_admin_id`). Developer-created
 * admins never get this set — developers bypass package limits
 * entirely.
 *
 * Backed by its own `admins` table — never the `users` table. Admin
 * accounts are not self-registerable; they're created by a developer
 * via `App\Http\Controllers\Developer\AdminController` (or seeder/
 * tinker).
 */
class Admin extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, Notifiable, \Illuminate\Auth\MustVerifyEmail;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'created_by_admin_id',
        'name',
        'email',
        'google_id',
        'password',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_by_admin_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Every package this admin is assigned, each carrying its own
     * `location_ids`/`hotel_ids` scope on the pivot (`$package->pivot`,
     * an App\Models\AdminPackage). Effective roles/features are the
     * union across all of these — see AccessService.
     *
     * @return BelongsToMany<Package, $this, AdminPackage>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'admin_package')
            ->using(AdminPackage::class)
            ->withPivot(['location_ids', 'hotel_ids']);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
