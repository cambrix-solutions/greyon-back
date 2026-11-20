<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-feature actions on a role (package): which of view / create / edit /
     * delete it grants for each feature it holds.
     *
     * `null` means every action, so every existing seat (Admin, Manager,
     * Hotel desk and custom roles made before this) keeps exactly the access
     * it has today. Only roles saved with an explicit action list are narrowed.
     */
    public function up(): void
    {
        if (Schema::hasColumn('package_feature', 'actions')) {
            return;
        }

        Schema::table('package_feature', function (Blueprint $table) {
            $table->json('actions')->nullable()->after('feature_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('package_feature', 'actions')) {
            return;
        }

        Schema::table('package_feature', function (Blueprint $table) {
            $table->dropColumn('actions');
        });
    }
};