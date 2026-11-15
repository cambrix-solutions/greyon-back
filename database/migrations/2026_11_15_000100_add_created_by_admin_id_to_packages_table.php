<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A package an org admin creates from Access -> Roles (a "custom role")
     * records who made it. Seeded / developer-made packages leave this null,
     * which is also how the two kinds are told apart: a custom role is
     * `is_system = false` AND `created_by_admin_id IS NOT NULL`.
     *
     * `nullOnDelete`: attribution only - removing the admin who created a
     * role must not delete the role or block the deletion.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('created_by_admin_id')->nullable()->after('is_system')
                ->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_admin_id');
        });
    }
};
