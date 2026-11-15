<?php

use App\Models\Package;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * A custom role no longer has its own "Works at". It carries both base
     * roles so the destinations and hotels chosen when a person is added
     * decide where they work. Existing custom roles get the missing base
     * role; people keep exactly the access they have now.
     */
    public function up(): void
    {
        $roleIds = Role::whereIn('name', ['manager', 'hotel_admin'])->pluck('id')->all();

        if (count($roleIds) !== 2) {
            return;
        }

        Package::where('is_system', false)->whereNotNull('created_by_admin_id')->get()
            ->each(fn (Package $package) => $package->roles()->syncWithoutDetaching($roleIds));
    }

    public function down(): void
    {
        // Intentionally empty: the roles stay assigned.
    }
};
