<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional caption per 360° photo, plus clickable hotspots that
     * jump from one photo to another in the same room type.
     */
    public function up(): void
    {
        Schema::table('room_type_panoramas', function (Blueprint $table) {
            $table->string('caption', 80)->nullable()->after('path');
        });

        Schema::create('room_type_panorama_hotspots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panorama_id')
                ->constrained('room_type_panoramas')
                ->cascadeOnDelete();
            $table->foreignId('target_panorama_id')
                ->constrained('room_type_panoramas')
                ->cascadeOnDelete();
            $table->decimal('yaw', 8, 5);
            $table->decimal('pitch', 8, 5);
            $table->string('label', 80)->nullable();
            $table->timestamps();

            $table->index('panorama_id');
            $table->index('target_panorama_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_panorama_hotspots');

        Schema::table('room_type_panoramas', function (Blueprint $table) {
            $table->dropColumn('caption');
        });
    }
};
