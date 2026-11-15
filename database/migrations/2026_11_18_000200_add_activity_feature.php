<?php

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Existing databases: add `activity` and give it to every seat that works with reservations. */
    public function up(): void
    {
        $feature = Feature::updateOrCreate(
            ['key' => 'activity'],
            [
                'label' => 'Activity log',
                'description' => 'Who changed what and when, limited to the hotels the seat can see',
                'category' => 'admin',
                'sort_order' => 21,
            ]
        );

        Package::query()
            ->whereHas('features', fn ($q) => $q->where('key', 'bookings'))
            ->get()
            ->each(fn (Package $package) => $package->features()->syncWithoutDetaching([$feature->id]));
    }

    public function down(): void
    {
        $feature = Feature::where('key', 'activity')->first();
        if ($feature) {
            $feature->packages()->detach();
            $feature->delete();
        }
    }
};
