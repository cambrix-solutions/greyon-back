<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-city landmarks ("Royal Palace - 1.2 km"). Same {place, distance}
     * shape as hotels.nearby_landmarks, so the frontend renders both alike.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->json('landmarks')->nullable()->after('highlights');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('landmarks');
        });
    }
};
