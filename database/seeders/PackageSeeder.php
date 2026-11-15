<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * System packages an admin can be assigned (see AdminSeeder). System
 * packages can't be deleted (App\Http\Controllers\Developer\PackageController).
 *
 * Shape mirrors the live local DB:
 *
 *   - `Admin` — main org seat. Every admin feature except `settings`,
 *     plus public product surfaces. Caps: locations, hotels per
 *     location, and managers are unlimited (`null`). Permission
 *     `locations_create` is granted.
 *   - `Manager · <City>` × 3 — same feature set as live Manager rows,
 *     same "no create destination" rule (`locations` limit 0, no
 *     `locations_create`). Hotels per location are unlimited (`null`).
 *
 * Permissions are derived from the package's features, minus any
 * explicitly denied keys.
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        // Main org package — matches packages.id=1 in the live DB.
        // `settings` exists as a feature but is not on the Admin seat.
        $adminFeatureKeys = Feature::query()
            ->where(function ($q) {
                $q->where('category', 'admin')
                    ->where('key', '!=', 'settings');
            })
            ->orWhere('category', 'public')
            ->pluck('key')
            ->all();

        $this->makePackage(
            'Admin',
            'Full admin-panel access — can create destinations, hotels, and managers without a quantity cap.',
            null,
            ['admin'],
            $adminFeatureKeys,
            limits: ['locations' => null, 'hotels_per_location' => null, 'managers' => null],
        );

        // City manager seats — feature set taken from live Manager · *
        // rows (enquiries / media / features included; no public keys,
        // no users / settings). Destination create stays denied (Admin may now create).
        // `media` is intentional: product (Seth, 2026-09-22) — Managers keep
        // scoped Media when the library is fully built (not Admin-only).
        $managerFeatureKeys = [
            'dashboard',
            'locations',
            'hotels',
            'rooms',
            'rates',
            'bookings',
            'news',
            'enquiries',
            'media',
            'features',
            // 2026-10-05: managers pick services and products on their
            // own hotels too.
            'services',
            'products',
        ];
        $managerLimits = ['locations' => 0, 'hotels_per_location' => null];

        foreach (['Phnom Penh', 'Kampot', 'Sihanoukville'] as $city) {
            $this->makePackage(
                "Manager · {$city}",
                "Location-scoped content, rooms, rates, reservations and news for {$city} — can add hotels here without a quantity cap, cannot create new destinations.",
                null,
                ['manager'],
                $managerFeatureKeys,
                limits: $managerLimits,
                denyPermissionKeys: ['locations_create'],
            );
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $featureKeys
     * @param  array<string, int|null>  $limits  resourceKey => maxCount (null = unlimited)
     * @param  list<string>  $denyPermissionKeys  withheld even if under a granted feature
     */
    private function makePackage(
        string $name,
        string $description,
        ?string $priceNote,
        array $roleNames,
        array $featureKeys,
        array $limits = [],
        array $denyPermissionKeys = [],
    ): void {
        $package = Package::updateOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'price_note' => $priceNote,
                'is_system' => true,
            ]
        );

        $package->roles()->sync(Role::whereIn('name', $roleNames)->pluck('id'));
        $package->features()->sync(Feature::whereIn('key', $featureKeys)->pluck('id'));

        $permissionIds = Permission::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('key', $featureKeys))
            ->when(
                $denyPermissionKeys !== [],
                fn ($q) => $q->whereNotIn('key', $denyPermissionKeys)
            )
            ->pluck('id');
        $package->permissions()->sync($permissionIds);

        $keepKeys = array_keys($limits);
        if ($keepKeys === []) {
            $package->limits()->delete();
        } else {
            $package->limits()->whereNotIn('resource_key', $keepKeys)->delete();
        }

        foreach ($limits as $resourceKey => $maxCount) {
            $package->limits()->updateOrCreate(
                ['resource_key' => $resourceKey],
                ['max_count' => $maxCount],
            );
        }
    }
}
