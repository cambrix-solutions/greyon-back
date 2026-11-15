<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who changed what and when. Actor and subject names are copied in, so an
     * entry still reads correctly after the person or record is gone.
     * hotel_id / location_id say where a change belongs, so scoped seats only
     * see their own.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 16)->nullable();        // admin | developer
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 16);                        // created | updated | deleted
            $table->string('subject_type', 32);                  // hotel, room_type, offer, ...
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->unsignedBigInteger('hotel_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->json('changes')->nullable();                 // {field: {from, to}}
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('hotel_id');
            $table->index('location_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
