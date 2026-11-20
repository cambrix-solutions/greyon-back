<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Developer;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private Location $ppLoc;

    private Location $ktLoc;

    private Hotel $pp;

    private Hotel $kampot;

    // Full migration set is MySQL-only; build just the tables this feature touches.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '2026_09_14_000002_create_developers_table',
            '0001_01_01_000003_create_admins_table',
            '2026_09_14_000003_create_roles_table',
            '2026_09_14_000004_create_features_table',
            '2026_09_14_000005_create_packages_table',
            '2026_09_14_000006_create_package_role_table',
            '2026_09_14_000007_create_package_feature_table',
            '2026_09_14_000008_create_admin_package_table',
            '2026_09_16_000001_create_package_limits_table',
            '2026_09_16_000003_create_permissions_table',
            '2026_09_16_000004_create_package_permission_table',
            '2026_09_15_000001_create_locations_table',
            '2026_09_30_000100_add_phone_and_email_to_locations_table',
            '2026_09_16_000002_add_created_by_admin_id_to_locations_table',
            '2026_09_15_000002_create_hotels_table',
            '2026_09_24_000100_add_map_embed_url_to_hotels_table',
            '2026_09_30_000200_add_property_type_and_star_rating_to_hotels_table',
            '2026_09_30_000400_add_area_to_hotels_table',
            '2026_09_30_000300_add_nearby_landmarks_to_hotels_table',
            '2026_09_15_000003_create_room_types_table',
            '2026_11_04_000100_add_panorama_path_to_room_types_table',
            '2026_11_20_000300_create_room_type_panoramas_table',
            '2026_09_15_000004_create_rate_plans_table',
            '2026_10_05_000100_create_services_table',
            '2026_11_18_000100_create_activity_logs_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'manager', 'is_global' => false, 'scope' => 'location']);
        Feature::create(['key' => 'activity', 'label' => 'Activity log', 'category' => 'admin', 'sort_order' => 0]);
        Feature::create(['key' => 'hotels', 'label' => 'Hotels', 'category' => 'admin', 'sort_order' => 1]);

        // Set up before anyone is signed in, so none of this is logged.
        $this->ppLoc = Location::create(['name' => 'Phnom Penh', 'slug' => 'phnom-penh', 'status' => 'published']);
        $this->ktLoc = Location::create(['name' => 'Kampot', 'slug' => 'kampot', 'status' => 'published']);
        $this->pp = Hotel::create(['location_id' => $this->ppLoc->id, 'name' => 'Greyon PP', 'slug' => 'greyon-pp', 'status' => 'published']);
        $this->kampot = Hotel::create(['location_id' => $this->ktLoc->id, 'name' => 'Greyon Kampot', 'slug' => 'greyon-kampot', 'status' => 'published']);
    }

    private function admin(string $role = 'admin', array $features = ['activity'], string $name = 'Seat Admin'): Admin
    {
        $package = Package::create(['name' => "Seat {$role} ".uniqid(), 'is_system' => true]);
        $package->roles()->sync([Role::where('name', $role)->value('id')]);
        $package->features()->sync(Feature::whereIn('key', $features)->pluck('id'));

        $admin = Admin::factory()->create(['name' => $name]);
        $admin->packages()->sync([$package->id => ['location_ids' => $role === 'manager' ? [$this->ppLoc->id] : null, 'hotel_ids' => null]]);

        return $admin;
    }

    /** Make the following model changes as this admin. */
    private function actAs(Admin $admin): void
    {
        Auth::guard('admin')->setUser($admin);
    }

    private function log(Admin $viewer, string $qs = '')
    {
        return $this->actingAs($viewer, 'admin')->getJson('/admin/activity'.$qs, self::JSON)->assertOk();
    }

    public function test_changes_are_recorded_with_who_what_and_old_and_new_values(): void
    {
        $editor = $this->admin('admin', ['activity'], 'Sophea Admin');
        $viewer = $this->admin();
        $this->actAs($editor);

        $this->pp->update(['name' => 'Greyon Riverside']);

        $entry = $this->log($viewer)->json('activity.0');

        $this->assertSame('updated', $entry['action']);
        $this->assertSame(['type' => 'admin', 'id' => $editor->id, 'name' => 'Sophea Admin'], $entry['actor']);
        $this->assertSame('hotel', $entry['subject']['type']);
        $this->assertSame('Greyon Riverside', $entry['subject']['label']);
        $this->assertSame($this->pp->id, $entry['hotelId']);
        $this->assertSame($this->ppLoc->id, $entry['locationId']);
        $this->assertSame([['field' => 'name', 'from' => 'Greyon PP', 'to' => 'Greyon Riverside']], $entry['changes']);
    }

    public function test_create_and_delete_are_recorded_and_no_change_means_no_entry(): void
    {
        $this->actAs($this->admin());

        $room = RoomType::create(['hotel_id' => $this->pp->id, 'name' => 'Deluxe', 'slug' => 'deluxe', 'status' => 'published']);
        $room->update(['name' => 'Deluxe']);          // nothing actually changed
        $room->delete();

        $rows = ActivityLog::where('subject_type', 'room_type')->orderBy('id')->get();
        $this->assertSame(['created', 'deleted'], $rows->pluck('action')->all());
        $this->assertSame('Deluxe', $rows[1]->subject_label);
        $this->assertSame($this->pp->id, $rows[0]->hotel_id);
        $this->assertSame($this->ppLoc->id, $rows[0]->location_id);
    }

    public function test_rate_plan_belongs_to_the_hotel_of_its_room_type(): void
    {
        $room = RoomType::create(['hotel_id' => $this->kampot->id, 'name' => 'Bungalow', 'slug' => 'bungalow', 'status' => 'published']);
        $this->actAs($this->admin());

        RatePlan::create(['room_type_id' => $room->id, 'name' => 'Flexible', 'base_price' => 50, 'status' => 'published']);

        $row = ActivityLog::where('subject_type', 'rate_plan')->first();
        $this->assertSame($this->kampot->id, $row->hotel_id);
        $this->assertSame($this->ktLoc->id, $row->location_id);
    }

    public function test_nothing_is_recorded_without_a_signed_in_admin(): void
    {
        $this->pp->update(['name' => 'Changed by a seeder']);
        Service::create(['name' => 'Spa', 'slug' => 'spa', 'status' => 'published']);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_developer_changes_are_recorded_as_developer(): void
    {
        $developer = Developer::factory()->create(['name' => 'Dev One']);
        Auth::guard('developer')->setUser($developer);

        Service::create(['name' => 'Spa', 'slug' => 'spa', 'status' => 'published']);

        $row = ActivityLog::first();
        $this->assertSame('developer', $row->actor_type);
        $this->assertSame('Dev One', $row->actor_name);

        $this->actingAs($developer, 'developer')->getJson('/admin/activity', self::JSON)->assertOk()->assertJsonCount(1, 'activity');
    }

    public function test_secrets_are_never_stored_and_long_text_is_shortened(): void
    {
        $target = $this->admin('admin', ['activity'], 'Target');
        $this->actAs($this->admin());

        $target->update(['password' => 'a-brand-new-secret', 'name' => 'Target Renamed', 'remember_token' => 'abc']);
        $this->pp->update(['description' => str_repeat('x', 500), 'amenities' => ['wifi', 'pool']]);

        $person = ActivityLog::where('subject_type', 'person')->where('action', 'updated')->first();
        $byField = collect($person->changes);
        $this->assertSame('(hidden)', $byField['password']['to']);
        $this->assertSame('(hidden)', $byField['password']['from']);
        $this->assertArrayNotHasKey('remember_token', $byField->all());
        $this->assertSame('Target Renamed', $byField['name']['to']);
        $this->assertStringNotContainsString('a-brand-new-secret', ActivityLog::all()->toJson());

        $hotel = collect(ActivityLog::where('subject_type', 'hotel')->first()->changes);
        $this->assertLessThanOrEqual(121, mb_strlen($hotel['description']['to']));
        $this->assertSame('(list)', $hotel['amenities']['to']);
    }

    public function test_a_manager_only_sees_entries_for_their_own_scope(): void
    {
        $editor = $this->admin('admin', ['activity'], 'Editor');
        $manager = $this->admin('manager');
        $global = $this->admin();
        $this->actAs($editor);   // everyone is created first: creating seats would be logged too
        $this->pp->update(['name' => 'PP renamed']);
        $this->kampot->update(['name' => 'Kampot renamed']);
        $this->ktLoc->update(['description' => 'About Kampot']);
        $this->ppLoc->update(['description' => 'About PP']);
        Service::create(['name' => 'Spa', 'slug' => 'spa', 'status' => 'published']);   // belongs to no hotel

        $labels = collect($this->log($manager)->json('activity'))->pluck('subject.label')->sort()->values()->all();
        $this->assertSame(['PP renamed', 'Phnom Penh'], $labels);

        $everything = collect($this->log($global)->json('activity'))->pluck('subject.label')->all();
        $this->assertCount(5, $everything);
        $this->assertContains('Spa', $everything);
        $this->assertContains('Kampot renamed', $everything);
    }

    public function test_filters_search_and_paging(): void
    {
        $a = $this->admin('admin', ['activity'], 'Anna');
        $b = $this->admin('admin', ['activity'], 'Bora');
        $viewer = $this->admin();

        $this->actAs($a);
        $this->pp->update(['name' => 'One']);
        $this->kampot->update(['name' => 'Two']);
        $this->actAs($b);
        Service::create(['name' => 'Spa', 'slug' => 'spa', 'status' => 'published']);
        $this->pp->update(['name' => 'Three']);

        $count = fn (string $qs) => $this->log($viewer, $qs)->json('meta.total');

        $this->assertSame(1, $count('?subjectType=service'));
        $this->assertSame(1, $count('?action=created&subjectType=service'));
        $this->assertSame(2, $count('?actorId='.$a->id.'&actorType=admin&subjectType=hotel'));
        $this->assertSame(2, $count('?hotelId='.$this->pp->id));
        $this->assertSame(1, $count('?locationId='.$this->ktLoc->id));
        $this->assertSame(1, $count('?q=bora&subjectType=hotel'));
        $this->assertSame(1, $count('?q=Spa'));
        $this->assertSame(0, $count('?from=2099-01-01'));
        $this->assertSame(3, $count('?subjectType=hotel&from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d')));

        $page = $this->log($viewer, '?subjectType=hotel&perPage=1&page=2');
        $page->assertJsonCount(1, 'activity')->assertJsonPath('meta.lastPage', 3);
        $this->assertContains('hotel', $page->json('subjectTypes'));
    }

    public function test_validation_and_feature_gate(): void
    {
        $viewer = $this->admin();
        $this->actingAs($viewer, 'admin')->getJson('/admin/activity?subjectType=banana', self::JSON)->assertStatus(422)->assertJsonValidationErrors('subjectType');
        $this->actingAs($viewer, 'admin')->getJson('/admin/activity?perPage=500', self::JSON)->assertStatus(422);
        $this->actingAs($viewer, 'admin')->getJson('/admin/activity?from=2026-12-02&to=2026-12-01', self::JSON)->assertStatus(422);

        $seat = $this->admin('admin', ['hotels']);
        $this->actingAs($seat, 'admin')->getJson('/admin/activity', self::JSON)->assertForbidden();
    }
}
