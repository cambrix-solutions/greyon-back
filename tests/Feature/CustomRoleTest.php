<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Feature;
use App\Models\Package;
use App\Models\Role;
use Tests\TestCase;

class CustomRoleTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    // The full migration set uses MySQL-only SQL and can't run on the phpunit
    // sqlite :memory: DB, so only the tables this feature touches are built.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000003_create_admins_table',
            '2026_09_23_000001_add_created_by_admin_id_to_admins_table',
            '2026_09_14_000003_create_roles_table',
            '2026_09_14_000004_create_features_table',
            '2026_09_14_000005_create_packages_table',
            '2026_09_14_000006_create_package_role_table',
            '2026_09_14_000007_create_package_feature_table',
            '2026_11_20_000100_add_actions_to_package_feature_table',
            '2026_09_14_000008_create_admin_package_table',
            '2026_09_16_000001_create_package_limits_table',
            '2026_09_16_000003_create_permissions_table',
            '2026_09_16_000004_create_package_permission_table',
            '2026_11_15_000100_add_created_by_admin_id_to_packages_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        Role::create(['name' => 'hotel_admin', 'is_global' => false, 'scope' => 'hotel']);

        foreach (['users', 'roles', 'rooms', 'bookings', 'news', 'features'] as $i => $key) {
            Feature::create(['key' => $key, 'label' => ucfirst($key), 'category' => 'admin', 'sort_order' => $i]);
        }
    }

    /** An org admin holding users + roles + rooms + bookings + features (not news). */
    private function orgAdmin(array $featureKeys = ['users', 'roles', 'rooms', 'bookings', 'features']): Admin
    {
        $package = Package::create(['name' => 'Org admin', 'is_system' => true]);
        $package->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $package->features()->sync(Feature::whereIn('key', $featureKeys)->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => null, 'hotel_ids' => null]]);

        return $admin;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Front desk',
            'description' => 'Reception staff',
            'scope' => 'hotel',
            'featureKeys' => ['bookings', 'rooms'],
        ], $over);
    }

    public function test_admin_creates_a_custom_role(): void
    {
        $admin = $this->orgAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/roles', $this->payload(), self::JSON)
            ->assertCreated()
            ->assertJsonPath('role.name', 'Front desk')
            ->assertJsonPath('role.isSystem', false);

        $package = Package::where('name', 'Front desk')->first();
        $this->assertFalse($package->is_system);
        $this->assertSame($admin->id, $package->created_by_admin_id);
        $this->assertSame(['hotel_admin'], $package->roles()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['bookings', 'rooms'], $package->features()->pluck('key')->all());
    }

    public function test_role_cannot_grant_a_feature_the_admin_does_not_hold(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin')
            ->postJson('/admin/roles', $this->payload(['featureKeys' => ['bookings', 'news']]), self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('featureKeys');

        $this->assertDatabaseMissing('packages', ['name' => 'Front desk']);
    }

    public function test_developer_only_features_key_is_never_grantable(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin')
            ->postJson('/admin/roles', $this->payload(['featureKeys' => ['features']]), self::JSON)
            ->assertStatus(422);
    }

    public function test_scope_must_be_location_or_hotel(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin')
            ->postJson('/admin/roles', $this->payload(['scope' => 'none']), self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    public function test_options_lists_only_features_the_admin_can_grant(): void
    {
        $keys = $this->actingAs($this->orgAdmin(), 'admin')
            ->getJson('/admin/roles/options', self::JSON)
            ->assertOk()
            ->json('features.*.key');

        $this->assertEqualsCanonicalizing(['users', 'roles', 'rooms', 'bookings'], $keys);
    }

    public function test_admin_without_the_roles_feature_is_forbidden(): void
    {
        $this->actingAs($this->orgAdmin(['users', 'rooms']), 'admin')
            ->getJson('/admin/roles', self::JSON)
            ->assertForbidden();
    }

    public function test_update_changes_features_but_system_seats_are_404(): void
    {
        $admin = $this->orgAdmin();
        $this->actingAs($admin, 'admin');

        $id = $this->postJson('/admin/roles', $this->payload(), self::JSON)->json('role.id');

        $this->patchJson("/admin/roles/{$id}", ['featureKeys' => ['bookings']], self::JSON)->assertOk();
        $this->assertSame(['bookings'], Package::find($id)->features()->pluck('key')->all());

        $systemId = Package::where('name', 'Org admin')->value('id');
        $this->patchJson("/admin/roles/{$systemId}", ['name' => 'Hacked'], self::JSON)->assertNotFound();
        $this->deleteJson("/admin/roles/{$systemId}", [], self::JSON)->assertNotFound();
    }

    public function test_scope_cannot_change_once_assigned_and_assigned_roles_cannot_be_deleted(): void
    {
        $admin = $this->orgAdmin();
        $this->actingAs($admin, 'admin');

        $id = $this->postJson('/admin/roles', $this->payload(), self::JSON)->json('role.id');

        $person = Admin::factory()->create();
        $person->packages()->sync([$id => ['location_ids' => null, 'hotel_ids' => [1]]]);

        $this->patchJson("/admin/roles/{$id}", ['scope' => 'location'], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');

        $this->deleteJson("/admin/roles/{$id}", [], self::JSON)->assertStatus(400);

        $person->packages()->detach();
        $this->deleteJson("/admin/roles/{$id}", [], self::JSON)->assertOk();
        $this->assertDatabaseMissing('packages', ['id' => $id]);
    }

    public function test_index_lists_custom_roles_with_people_count(): void
    {
        $admin = $this->orgAdmin();
        $this->actingAs($admin, 'admin');
        $this->postJson('/admin/roles', $this->payload(), self::JSON)->assertCreated();

        $names = $this->getJson('/admin/roles', self::JSON)->assertOk()->json('roles.*.name');

        $this->assertContains('Front desk', $names);
        $this->assertNotContains('Org admin', $names); // admin seats are never listed here
    }
}
