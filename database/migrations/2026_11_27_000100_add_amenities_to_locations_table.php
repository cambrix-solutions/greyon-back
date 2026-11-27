<?php

use App\Services\LocationAmenitiesBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-destination amenities, same JSON string-array shape as hotels.
     * One-time backfill copies each location's existing hotel lists
     * (including draft hotels) in order. Kampot has no hotels, so it
     * becomes []. Re-running fillMissing() is a no-op once a value
     * (including []) has been saved.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->json('amenities')->nullable()->after('highlights');
        });

        LocationAmenitiesBackfill::fillMissing();
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('amenities');
        });
    }
};
