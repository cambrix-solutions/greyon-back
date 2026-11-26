<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many 360° photos per room type, each with its own published flag and
     * a position the admin can rearrange.
     *
     * `room_types.panorama_path` is kept (not dropped): it now mirrors the
     * first published panorama so older clients keep working. Each existing
     * single photo is copied into the new table as a published row.
     */
    public function up(): void
    {
        Schema::create('room_type_panoramas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('path', 2048);
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['room_type_id', 'sort_order']);
        });

        $now = now();

        DB::table('room_types')
            ->whereNotNull('panorama_path')
            ->where('panorama_path', '!=', '')
            ->orderBy('id')
            ->each(function ($roomType) use ($now) {
                DB::table('room_type_panoramas')->insert([
                    'room_type_id' => $roomType->id,
                    'path' => $roomType->panorama_path,
                    'is_published' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_panoramas');
    }
};
