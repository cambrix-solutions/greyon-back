<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional 360° panorama for a room type.
     *
     * Room photos already live on `room_types.images` (JSON list of
     * public URLs), not a related media table, so the panorama is a
     * nullable path on the same table. The file itself sits on the
     * public disk under `uploads/rooms/{id}/` — the same disk as seeded
     * room photos and the same `uploads/` prefix as other admin uploads
     * (SeedAssetsSeeder never touches `uploads/`).
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('panorama_path')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('panorama_path');
        });
    }
};
