<?php

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const FEATURES = [
        'guests' => ['Guests', 'Guest list, reservation history and staff notes', 17],
        'reports' => ['Reports', 'Reservation totals by destination, hotel and source, with CSV export', 18],
    ];

    /**
     * Existing databases: add the two feature keys and give them to every seat
     * that already works with reservations (admin and manager seats).
     * FeatureSeeder / PackageSeeder do the same for fresh installs.
     */
    public function up(): void
    {
        foreach (self::FEATURES as $key => [$label, $description, $sort]) {
            $feature = Feature::updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'description' => $description, 'category' => 'admin', 'sort_order' => $sort]
            );

            Package::query()
                ->whereHas('features', fn ($q) => $q->where('key', 'bookings'))
                ->get()
                ->each(fn (Package $package) => $package->features()->syncWithoutDetaching([$feature->id]));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FEATURES) as $key) {
            $feature = Feature::where('key', $key)->first();
            if ($feature) {
                $feature->packages()->detach();
                $feature->delete();
            }
        }
    }
};
