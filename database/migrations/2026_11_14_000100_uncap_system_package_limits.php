<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Uncap system-package quantity limits on already-seeded rows.
     *
     * Fresh installs pick this up from PackageSeeder (`null` max_count
     * is unlimited — see AccessService::effectiveLimit()). This
     * migration updates existing `package_limits` so live databases
     * match:
     *
     *   - Admin: locations, hotels_per_location, and managers → null,
     *     and grant `locations_create` if that permission exists.
     *   - Manager packages: hotels_per_location → null (locations
     *     stay 0; `locations_create` stays withheld).
     *
     * down() restores the previous seeded caps (Admin 0/3/3 and no
     * `locations_create`; Manager hotels_per_location 3).
     */
    public function up(): void
    {
        $now = now();

        $adminId = DB::table('packages')->where('name', 'Admin')->value('id');

        if ($adminId) {
            foreach (['locations', 'hotels_per_location', 'managers'] as $key) {
                $this->upsertLimit((int) $adminId, $key, null, $now);
            }

            $permissionId = DB::table('permissions')->where('key', 'locations_create')->value('id');

            if ($permissionId) {
                $alreadyGranted = DB::table('package_permission')
                    ->where('package_id', $adminId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $alreadyGranted) {
                    DB::table('package_permission')->insert([
                        'package_id' => $adminId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }

        foreach ($this->managerPackageIds($adminId) as $managerId) {
            $this->upsertLimit((int) $managerId, 'hotels_per_location', null, $now);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $now = now();

        $adminId = DB::table('packages')->where('name', 'Admin')->value('id');

        if ($adminId) {
            $this->upsertLimit((int) $adminId, 'locations', 0, $now);
            $this->upsertLimit((int) $adminId, 'hotels_per_location', 3, $now);
            $this->upsertLimit((int) $adminId, 'managers', 3, $now);

            $permissionId = DB::table('permissions')->where('key', 'locations_create')->value('id');

            if ($permissionId) {
                DB::table('package_permission')
                    ->where('package_id', $adminId)
                    ->where('permission_id', $permissionId)
                    ->delete();
            }
        }

        foreach ($this->managerPackageIds($adminId) as $managerId) {
            $this->upsertLimit((int) $managerId, 'hotels_per_location', 3, $now);
        }
    }

    /**
     * @return list<int>
     */
    private function managerPackageIds(mixed $adminId): array
    {
        $ids = DB::table('packages')->where('name', 'like', 'Manager%')->pluck('id');

        $roleId = DB::table('roles')->where('name', 'manager')->value('id');

        if ($roleId) {
            $ids = $ids->merge(
                DB::table('package_role')->where('role_id', $roleId)->pluck('package_id')
            );
        }

        return $ids
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $adminId !== null && $id === (int) $adminId)
            ->values()
            ->all();
    }

    private function upsertLimit(int $packageId, string $resourceKey, ?int $maxCount, mixed $now): void
    {
        $existing = DB::table('package_limits')
            ->where('package_id', $packageId)
            ->where('resource_key', $resourceKey)
            ->first();

        if ($existing) {
            DB::table('package_limits')
                ->where('id', $existing->id)
                ->update([
                    'max_count' => $maxCount,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('package_limits')->insert([
            'package_id' => $packageId,
            'resource_key' => $resourceKey,
            'max_count' => $maxCount,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
