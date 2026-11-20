<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Calendar, Offers and Activity log were dropped from the product. Take
     * their feature keys out of existing databases so they no longer show in
     * packages or the role picker. Tables and columns they created are left
     * alone (no data is deleted).
     */
    public function up(): void
    {
        Feature::query()->whereIn('key', ['calendar', 'offers', 'activity'])->get()->each(function (Feature $feature) {
            $feature->packages()->detach();
            $feature->delete();
        });
    }

    public function down(): void
    {
        // Intentionally empty: the features no longer exist in code.
    }
};
