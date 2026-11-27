<?php

namespace App\Services;

use App\Models\Location;

/**
 * Copies each location's hotel amenity labels onto locations.amenities
 * when that column is still null (first migrate / fresh seed).
 *
 * Includes draft hotels so a destination like Phnom Penh inherits its
 * real list even before the hotel is published. Locations with no
 * hotels (Kampot today) are saved as [] so a later re-seed does not
 * treat them as "never filled." An admin-saved list, including an
 * intentional empty array, is never overwritten.
 */
class LocationAmenitiesBackfill
{
    /**
     * Fill locations whose amenities column is still null.
     *
     * @return int Number of locations filled
     */
    public static function fillMissing(): int
    {
        $filled = 0;

        Location::query()
            ->whereNull('amenities')
            ->orderBy('id')
            ->each(function (Location $location) use (&$filled): void {
                $location->amenities = self::mergeHotelAmenities($location);
                $location->save();
                $filled++;
            });

        return $filled;
    }

    /**
     * Unique hotel labels in hotel-id then list order. First spelling wins.
     *
     * @return list<string>
     */
    public static function mergeHotelAmenities(Location $location): array
    {
        $seen = [];
        $amenities = [];

        foreach ($location->hotels()->orderBy('id')->get() as $hotel) {
            foreach (self::labels($hotel->amenities) as $label) {
                $key = mb_strtolower($label);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $amenities[] = $label;
            }
        }

        return $amenities;
    }

    /**
     * @return list<string>
     */
    public static function labels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $label = trim($item);
            if ($label === '') {
                continue;
            }
            $out[] = $label;
        }

        return $out;
    }
}
