<?php

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Existing databases: add the `calendar` feature key and give it to every
     * seat that already manages rates. FeatureSeeder / PackageSeeder do the
     * same for fresh installs.
     */
    public function up(): void
    {
        $feature = Feature::updateOrCreate(
            ['key' => 'calendar'],
            [
                'label' => 'Calendar',
                'description' => 'Month grid of units, reservations, stop-sell and prices, with bulk edit',
                'category' => 'admin',
                'sort_order' => 19,
            ]
        );

        Package::query()
            ->whereHas('features', fn ($q) => $q->where('key', 'rates'))
            ->get()
            ->each(fn (Package $package) => $package->features()->syncWithoutDetaching([$feature->id]));
    }

    public function down(): void
    {
        $feature = Feature::where('key', 'calendar')->first();
        if ($feature) {
            $feature->packages()->detach();
            $feature->delete();
        }
    }
};
