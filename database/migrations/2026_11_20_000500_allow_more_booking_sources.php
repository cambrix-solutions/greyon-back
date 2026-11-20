<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports group reservations by where they came from. The column was an
     * enum of website|admin; staff also take reservations by phone and at the
     * desk, so it becomes a short string. Existing rows keep their value and
     * new public reservations still default to `website`.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY source VARCHAR(20) NOT NULL DEFAULT 'website'");

            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 20)->default('website')->change();
        });
    }

    public function down(): void
    {
        DB::table('bookings')->whereNotIn('source', ['website', 'admin'])->update(['source' => 'admin']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY source ENUM('website','admin') NOT NULL DEFAULT 'website'");
        }
    }
};