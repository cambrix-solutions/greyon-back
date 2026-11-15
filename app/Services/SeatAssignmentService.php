<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The rules for handing seats (packages) to a person: which seats an org admin
 * may assign, that scoped seats carry a location/hotel pick, and the package
 * limits. Shared by People/Team (create a person now) and Invites (create them
 * when they accept), so both enforce exactly the same thing.
 */
class SeatAssignmentService
{
    /** Roles an org admin may grant when creating people. */
    public const ASSIGNABLE_ROLES = ['manager', 'hotel_admin'];

    public function __construct(private readonly AccessService $access) {}

    /**
     * @param  list<array{packageId: int, locationIds?: list<int>, hotelIds?: list<int>}>  $entries
     * @return array<int, array{location_ids: ?list<int>, hotel_ids: ?list<int>}>
     */
    public function resolve(array $entries, Admin $actor): array
    {
        $packageIds = array_column($entries, 'packageId');
        $packages = Package::whereIn('id', $packageIds)->with(['roles', 'limits'])->get()->keyBy('id');

        $unknown = array_diff($packageIds, $packages->keys()->all());
        if ($unknown !== []) {
            throw new HttpException(400, 'Unknown seat ids: '.implode(', ', $unknown));
        }

        $sync = [];

        foreach ($entries as $entry) {
            $package = $packages[$entry['packageId']];
            $roleNames = $package->roles->pluck('name');

            if ($roleNames->contains('admin')) {
                throw ValidationException::withMessages([
                    'packages' => 'Org admins cannot assign other admin seats. Pick a manager or hotel desk seat.',
                ]);
            }

            if ($roleNames->intersect(self::ASSIGNABLE_ROLES)->isEmpty()) {
                throw ValidationException::withMessages([
                    'packages' => "Seat \"{$package->name}\" is not assignable from People.",
                ]);
            }

            $roleScopes = $package->roles->pluck('scope')->unique();
            $locationIds = array_values(array_unique($entry['locationIds'] ?? []));
            $hotelIds = array_values(array_unique($entry['hotelIds'] ?? []));

            if ($roleScopes->contains('location') && $locationIds === []) {
                throw ValidationException::withMessages([
                    'packages' => "Pick at least one location for seat \"{$package->name}\".",
                ]);
            }

            if ($roleScopes->contains('hotel') && $hotelIds === []) {
                throw ValidationException::withMessages([
                    'packages' => "Pick at least one hotel for seat \"{$package->name}\".",
                ]);
            }

            $this->assertWithinLimits($actor, $package, $locationIds, $hotelIds);

            $sync[$package->id] = [
                'location_ids' => $locationIds ?: null,
                'hotel_ids' => $hotelIds ?: null,
            ];
        }

        return $sync;
    }

    /**
     * The `managers` package-limit check (see class docblock) — how
     * many people *this admin* has added via People/Team so far, not
     * a global or per-package count. `effectiveLimit()` returning
     * `null` means unlimited.
     */
    public function assertWithinCreateLimit(Admin $actor): void
    {
        $limit = $this->access->effectiveLimit($actor, 'users', 'managers');

        if ($limit === null) {
            return;
        }

        $used = Admin::where('created_by_admin_id', $actor->id)->count();

        if ($used >= $limit) {
            throw ValidationException::withMessages([
                'packages' => "You've reached your limit of {$limit} people added ({$used}/{$limit}) for your package(s).",
            ]);
        }
    }

    /**
     * @param  list<int>  $locationIds
     * @param  list<int>  $hotelIds
     */
    private function assertWithinLimits(Admin $actor, Package $package, array $locationIds, array $hotelIds): void
    {
        $locCap = $this->access->effectiveLimit($actor, 'users', 'locations');
        if ($locCap === 0) {
            $locCap = $this->access->effectiveLimit($actor, 'locations', 'locations');
        }
        if ($locCap !== null && count($locationIds) > $locCap) {
            throw ValidationException::withMessages([
                'packages' => "Your seat allows at most {$locCap} locations per person.",
            ]);
        }

        $pkgLoc = $package->limits->firstWhere('resource_key', 'locations');
        if ($pkgLoc && $pkgLoc->max_count !== null && count($locationIds) > (int) $pkgLoc->max_count) {
            throw ValidationException::withMessages([
                'packages' => "This seat allows at most {$pkgLoc->max_count} locations.",
            ]);
        }

        $hotelsPerLoc = $this->access->effectiveLimit($actor, 'users', 'hotels_per_location');
        if ($hotelsPerLoc === 0) {
            $hotelsPerLoc = $this->access->effectiveLimit($actor, 'hotels', 'hotels_per_location');
        }
        if ($hotelsPerLoc === null) {
            $hotelsPerLoc = optional($package->limits->firstWhere('resource_key', 'hotels_per_location'))->max_count;
        }

        if ($hotelsPerLoc !== null && $hotelIds !== []) {
            $byLocation = Hotel::whereIn('id', $hotelIds)
                ->get(['id', 'location_id'])
                ->groupBy('location_id');

            foreach ($byLocation as $locationId => $group) {
                if ($group->count() > (int) $hotelsPerLoc) {
                    $locName = Location::find($locationId)?->name ?? "#{$locationId}";
                    throw ValidationException::withMessages([
                        'packages' => "At most {$hotelsPerLoc} hotels per location ({$locName}).",
                    ]);
                }
            }
        }
    }
}
