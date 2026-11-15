<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('image', 500)->nullable();
            $table->string('discount_type', 10);                 // percent | fixed
            $table->decimal('discount_value', 10, 2);
            $table->string('code', 32)->nullable()->unique();    // upper case; null = applies automatically
            $table->unsignedBigInteger('hotel_id')->nullable();  // both null = everywhere
            $table->unsignedBigInteger('location_id')->nullable();
            $table->date('book_from')->nullable();               // when guests may book with it
            $table->date('book_to')->nullable();
            $table->date('stay_from')->nullable();               // which nights it covers
            $table->date('stay_to')->nullable();
            $table->unsignedInteger('min_nights')->nullable();
            $table->string('status', 16)->default('draft');      // draft | published
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'hotel_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
