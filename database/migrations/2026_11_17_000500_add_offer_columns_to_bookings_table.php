<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reservation keeps what it was discounted by, as a snapshot: the offer
     * can be edited or deleted later without changing a receipt. subtotal stays
     * the price before the discount; total = subtotal - discount_total + taxes_fees.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->string('offer_title')->nullable();
            $table->decimal('discount_total', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['offer_id', 'offer_title', 'discount_total']);
        });
    }
};
