<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Hotel;
use App\Models\Package;
use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "who can see/do what." An admin's roles
 * and features are the union of every package they're assigned
 * (`admin_package` → `package_role`/`package_feature`) — see
 * App\Models\Admin, Package, Role, Feature, AdminPackage. Developer
 * accounts never go through here at all: they're a separate table/
 * guard (App\Models\Developer) and bypass this entirely.
 *
 * Every controller and the `permission` route middleware should go
 * through `can()` / `canAccessHotel()` / `canAccessLocation()` rather
 * than hand-rolling a check — one place to get this right.
 *
 * Scoping is entirely schema-driven, not name-matched: `Role::is_global`
 * and `Role::scope` (`none`|`location`|`hotel`) decide *what kind* of
 * scoping a role implies, and `location_ids`/`hotel_ids` on the
 * `admin_package` pivot (App\Models\AdminPackage) — not on the admin
 * itself — decide the actual scope for *that* package assignment. An
 * admin holding two location-scoped packages can be scoped to two
 * different sets of locations; a developer creating a brand-new
 * location- or hotel-scoped role via Developer\RoleController gets
 * real, working scope checks immediately.
 */
class AccessService
{
    /**
     * This admin's packages, each with `roles` loaded and its pivot
     * (`location_ids`/`hotel_ids` for *that* assignment) already
     * present — the source every method below reads from.
     *
     * @return Collection<int, Package>
     */
    private function loadedPackages(Admin $admin): Collection
    {
        return $admin->packages->loadMissing('roles');
    }

    /**
     * Every distinct Role this admin effectively holds, across all of
     * their packages — the source `effectiveRoles()` and `isGlobal()`
     * read from. Scope checks below don't use this: they need each
     * role paired with the *specific package assignment* it came from
     * (for that assignment's location_ids/hotel_ids), not a flat union.
     *
     * @return Collection<int, Role>
     */
    public function effectiveRoleModels(Admin $admin): Collection
    {
        return $this->loadedPackages($admin)
            ->flatMap(fn (Package $package) => $package->roles)
            ->unique('id')
            ->values();
    }

    /**
     * Union of every role name across this admin's packages.
     *
     * @return list<string>
     */
    public function effectiveRoles(Admin $admin): array
    {
        return $this->effectiveRoleModels($admin)->pluck('name')->all();
    }

    /**
     * Union of every feature key across this admin's packages — the
     * set `can()` checks membership against.
     *
     * @return list<string>
     */
    public function effectiveFeatureKeys(Admin $admin): array
    {
        return $admin->packages
            ->loadMissing('features')
            ->flatMap(fn ($package) => $package->features->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * `perm` ∈ this admin's effective feature keys. No separate
     * role × permission matrix on top of this — a package already
     * decides which features it grants, that's the whole gate.
     *
     * This is module-level visibility (`Feature::key`, what
     * `permission:<key>` route middleware checks) — **not** the same
     * thing as `hasPermission()` below, despite the similar name.
     */
    public function can(Admin $admin, string $perm): bool
    {
        return in_array($perm, $this->effectiveFeatureKeys($admin), true);
    }

    /**
     * Union of every permission key across this admin's packages —
     * `Permission::key` (2026-09-16), the fine-grained layer under
     * `features`. Only counts a package's own explicit
     * `Package::permissions()` grants; holding a feature does **not**
     * implicitly grant the permissions catalogued under it (see
     * `Package::permissions()`'s docblock) — so this deliberately does
     * *not* traverse `package->features->permissions`.
     *
     * @return list<string>
     */
    public function effectivePermissionKeys(Admin $admin): array
    {
        return $admin->packages
            ->loadMissing('permissions')
            ->flatMap(fn (Package $package) => $package->permissions->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * `permissionKey` ∈ this admin's effective permission keys. Callers
     * needing a fine-grained, within-module check (e.g.
     * `Admin\LocationController::authorizeFields()`) use this instead
     * of `can()` — the two operate on separate catalogs (Feature vs.
     * Permission) that happen to share some key-naming conventions
     * (`locations_publish` reads like a child of `locations`, but
     * they're different tables now). The module-level
     * `permission:<key>` route middleware always runs first via
     * `can()`, so by the time a controller calls this the admin has
     * already cleared the coarser module gate.
     */
    public function hasPermission(Admin $admin, string $permissionKey): bool
    {
        return in_array($permissionKey, $this->effectivePermissionKeys($admin), true);
    }

    /** The actions a role can grant on a feature, in display order. */
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /**
     * Normalise a `package_feature.actions` value. `null` (every seat made
     * before per-feature actions existed) means all actions; `view` is always
     * implied by any other action.
     *
     * @return list<string>
     */
    public static function normaliseActions(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return self::ACTIONS;
        }

        $actions = array_values(array_intersect(self::ACTIONS, $raw));

        return $actions === [] ? [] : array_values(array_unique(['view', ...$actions]));
    }

    /**
     * Feature key => actions this admin holds, unioned across their packages
     * (most generous wins, same as effectiveFeatureKeys()).
     *
     * @return array<string, list<string>>
     */
    public function effectiveFeatureActions(Admin $admin): array
    {
        $map = [];

        foreach ($admin->packages->loadMissing('features') as $package) {
            foreach ($package->features as $feature) {
                $actions = self::normaliseActions($feature->pivot?->actions ?? null);
                $map[$feature->key] = array_merge($map[$feature->key] ?? [], $actions);
            }
        }

        return array_map(
            fn (array $actions) => array_values(array_intersect(self::ACTIONS, array_unique($actions))),
            $map
        );
    }

    /** True if the admin holds `$featureKey` with `$action` (view|create|edit|delete). */
    public function canAction(Admin $admin, string $featureKey, string $action): bool
    {
        return in_array($action, $this->effectiveFeatureActions($admin)[$featureKey] ?? [], true);
    }

    /**
     * The action an admin API request needs: reads are `view`, POST is
     * `create`, PUT/PATCH is `edit`, DELETE is `delete`. POST endpoints that
     * change existing rows (upsert, reorder) count as `edit`.
     */
    public static function actionForRequest(string $method, ?string $routeName = null): string
    {
        $method = strtoupper($method);

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return 'view';
        }

        if ($method === 'DELETE') {
            return 'delete';
        }

        if ($method === 'POST') {
            $name = (string) $routeName;

            return str_ends_with($name, '.upsert') || str_ends_with($name, '.reorder') ? 'edit' : 'create';
        }

        return 'edit';
    }
    /**
     * True if any of this admin's effective roles is flagged
     * `is_global` — sees everything regardless of any package's
     * location_ids/hotel_ids.
     */
    public function isGlobal(Admin $admin): bool
    {
        return $this->effectiveRoleModels($admin)->contains(fn (Role $role) => $role->is_global);
    }

    /**
     * True if this admin holds a package granting a location-scoped
     * role whose *own pivot* `location_ids` includes `$locationId` —
     * i.e. they may create resources under that location. Narrower
     * than `canAccessLocation()`, which also returns true for a
     * hotel-scoped package that merely owns a hotel there (see
     * `Admin\HotelController::store()`, the reason this exists
     * separately). Does not itself check `isGlobal()` — callers that
     * want global seats to pass too should check that separately, same
     * as `canAccessLocation()`'s callers used to.
     */
    public function isLocationManagerOf(Admin $admin, mixed $locationId): bool
    {
        foreach ($this->loadedPackages($admin) as $package) {
            if (! $package->roles->contains(fn (Role $role) => $role->scope === 'location')) {
                continue;
            }

            /** @var \App\Models\AdminPackage $pivot */
            $pivot = $package->pivot;

            if (in_array($locationId, $pivot->locationIds(), true)) {
                return true;
            }
        }

        return false;
    }

    public function canAccessHotel(Admin $admin, mixed $hotelId): bool
    {
        if ($this->isGlobal($admin)) {
            return true;
        }

        foreach ($this->loadedPackages($admin) as $package) {
            $scopes = $package->roles->pluck('scope');

            /** @var \App\Models\AdminPackage $pivot */
            $pivot = $package->pivot;

            if ($scopes->contains('hotel') && in_array($hotelId, $pivot->hotelIds(), true)) {
                return true;
            }

            if ($scopes->contains('location')) {
                $hotel = Hotel::find($hotelId);

                if ($hotel && in_array($hotel->location_id, $pivot->locationIds(), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canAccessLocation(Admin $admin, mixed $locationId): bool
    {
        if ($this->isGlobal($admin)) {
            return true;
        }

        if ($this->isLocationManagerOf($admin, $locationId)) {
            return true;
        }

        foreach ($this->loadedPackages($admin) as $package) {
            if (! $package->roles->contains(fn (Role $role) => $role->scope === 'hotel')) {
                continue;
            }

            /** @var \App\Models\AdminPackage $pivot */
            $pivot = $package->pivot;

            $hasHotelThere = Hotel::where('location_id', $locationId)
                ->whereIn('id', $pivot->hotelIds())
                ->exists();

            if ($hasHotelThere) {
                return true;
            }
        }

        return false;
    }

    /**
     * This admin's effective cap on `resourceKey`, across every
     * package they hold that grants `featureKey` — `null` means
     * unlimited (2026-09-16, App\Models\PackageLimit).
     *
     * Only packages granting `featureKey` are considered, since a
     * package that doesn't grant the feature at all contributes
     * nothing either way (the admin can't reach the action through
     * that package regardless of any limit row on it).
     *
     * "Most generous wins", same philosophy as `effectiveFeatureKeys()`
     * unioning permissively across packages: if *any* qualifying
     * package has no `package_limits` row at all for `resourceKey`,
     * that package imposes no cap, so the admin is unlimited overall
     * — a stricter package held at the same time doesn't claw that
     * back. Only when every qualifying package explicitly caps this
     * key does the admin's effective limit become the highest of
     * those caps.
     *
     * Callers are expected to already know which feature key pairs
     * with which resource key for the action they're gating (e.g.
     * LocationController pairs `locations`/`locations`; HotelController
     * pairs `hotels`/`hotels_per_location`) — this method doesn't
     * infer that mapping, since it isn't always 1:1 (see the
     * create_package_limits_table migration's docblock).
     */
    public function effectiveLimit(Admin $admin, string $featureKey, string $resourceKey): ?int
    {
        $qualifying = $this->loadedPackages($admin)
            ->loadMissing(['features', 'limits'])
            ->filter(fn (Package $package) => $package->features->pluck('key')->contains($featureKey));

        if ($qualifying->isEmpty()) {
            return 0;
        }

        $caps = [];

        foreach ($qualifying as $package) {
            $limit = $package->limits->firstWhere('resource_key', $resourceKey);

            if (! $limit || $limit->max_count === null) {
                return null;
            }

            $caps[] = $limit->max_count;
        }

        return max($caps);
    }
}
