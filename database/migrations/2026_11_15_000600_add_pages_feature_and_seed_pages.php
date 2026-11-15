<?php

use App\Models\Feature;
use App\Models\Package;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * On databases that are already seeded: add the `pages` feature key, give it
     * to org admin seats that hold `users`, and create the three legal pages
     * (as drafts, so the frontend keeps its static text until the client
     * publishes real content). PageSeeder / FeatureSeeder do the same for fresh installs.
     */
    public function up(): void
    {
        $feature = Feature::updateOrCreate(
            ['key' => 'pages'],
            [
                'label' => 'Pages',
                'description' => 'Edit Privacy, Terms, Booking terms and other site pages',
                'category' => 'admin',
                'sort_order' => 16,
            ]
        );

        Package::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->whereHas('features', fn ($q) => $q->where('key', 'users'))
            ->get()
            ->each(fn (Package $package) => $package->features()->syncWithoutDetaching([$feature->id]));

        foreach (Page::SYSTEM_PAGES as $slug => $title) {
            Page::firstOrCreate(
                ['slug' => $slug],
                ['title' => $title, 'blocks' => [], 'status' => 'draft', 'is_system' => true]
            );
        }
    }

    public function down(): void
    {
        Page::where('is_system', true)->delete();

        $feature = Feature::where('key', 'pages')->first();
        if ($feature) {
            $feature->packages()->detach();
            $feature->delete();
        }
    }
};
