<?php

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Adds the `roles` feature key (Access -> Roles page) on databases that
     * are already seeded, and gives it to every package that is an org
     * admin seat and already holds `users` (People). FeatureSeeder carries
     * the same key for fresh installs.
     */
    public function up(): void
    {
        $feature = Feature::updateOrCreate(
            ['key' => 'roles'],
            [
                'label' => 'Roles',
                'description' => 'Create custom roles and choose which features each role can use',
                'category' => 'admin',
                'sort_order' => 15,
            ]
        );

        Package::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->whereHas('features', fn ($q) => $q->where('key', 'users'))
            ->get()
            ->each(fn (Package $package) => $package->features()->syncWithoutDetaching([$feature->id]));
    }

    public function down(): void
    {
        $feature = Feature::where('key', 'roles')->first();

        if ($feature) {
            $feature->packages()->detach();
            $feature->delete();
        }
    }
};
