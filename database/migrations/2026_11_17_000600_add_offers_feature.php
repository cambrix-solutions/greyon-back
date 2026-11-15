<?php

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Existing databases: add `offers` and give it to every seat that manages rates. */
    public function up(): void
    {
        $feature = Feature::updateOrCreate(
            ['key' => 'offers'],
            [
                'label' => 'Offers',
                'description' => 'Special rates and promo codes shown to guests and applied at quote time',
                'category' => 'admin',
                'sort_order' => 20,
            ]
        );

        Package::query()
            ->whereHas('features', fn ($q) => $q->where('key', 'rates'))
            ->get()
            ->each(fn (Package $package) => $package->features()->syncWithoutDetaching([$feature->id]));
    }

    public function down(): void
    {
        $feature = Feature::where('key', 'offers')->first();
        if ($feature) {
            $feature->packages()->detach();
            $feature->delete();
        }
    }
};
