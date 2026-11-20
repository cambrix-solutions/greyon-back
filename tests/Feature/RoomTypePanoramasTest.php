<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Developer;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use App\Models\RoomType;
use App\Models\RoomTypePanorama;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomTypePanoramasTest extends TestCase
{
    // The full migration set uses MySQL-only SQL and can't run on the phpunit
    // sqlite :memory: DB (see AdminUploadTest). Only the tables this feature
    // touches are created here.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000003_create_admins_table.php',
            '2026_09_15_000001_create_locations_table.php',
            '2026_09_30_000100_add_phone_and_email_to_locations_table.php',
            '2026_09_16_000002_add_created_by_admin_id_to_locations_table.php',
            '2026_09_15_000002_create_hotels_table.php',
            '2026_09_24_000100_add_map_embed_url_to_hotels_table.php',
            '2026_09_30_000200_add_property_type_and_star_rating_to_hotels_table.php',
            '2026_09_30_000400_add_area_to_hotels_table.php',
            '2026_09_30_000300_add_nearby_landmarks_to_hotels_table.php',
            '2026_09_15_000003_create_room_types_table.php',
            '2026_11_04_000100_add_panorama_path_to_room_types_table.php',
            '2026_11_20_000300_create_room_type_panoramas_table.php',
            '2026_09_15_000004_create_rate_plans_table.php',
            '2026_09_19_000001_create_ops_and_cms_tables.php',
            '2026_09_27_000200_add_location_id_to_news_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        Storage::fake('public');
    }

    private const JSON = ['Accept' => 'application/json'];

    private function dev(): Developer
    {
        return Developer::factory()->make(['id' => 1]);
    }

    /**
     * @param  list<list<string>>  $packages
     */
    private function admin(array $packages): Admin
    {
        $admin = Admin::factory()->make(['id' => 1]);

        return $admin->setRelation('packages', new EloquentCollection(array_map(
            fn (array $keys) => (new Package)->setRelation('features', new EloquentCollection(array_map(
                fn (string $key) => new Feature(['key' => $key]),
                $keys
            ))),
            $packages
        )));
    }

    private function hotel(): Hotel
    {
        $location = Location::create([
            'name' => 'Phnom Penh',
            'slug' => 'phnom-penh',
            'status' => 'published',
        ]);

        return Hotel::create([
            'location_id' => $location->id,
            'name' => 'Greyon PP',
            'slug' => 'greyon-pp',
            'status' => 'published',
        ]);
    }

    private ?Hotel $hotel = null;

    private function room(string $slug = 'deluxe'): RoomType
    {
        $this->hotel ??= $this->hotel();

        return RoomType::create([
            'hotel_id' => $this->hotel->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'published',
        ]);
    }

    /**
     * @return list<UploadedFile>
     */
    private function files(int $n): array
    {
        return array_map(
            fn (int $i) => UploadedFile::fake()->image("pano-{$i}.jpg", 2000, 1000),
            range(1, $n)
        );
    }

    /**
     * Uploads $n files and returns the ids of every photo the room now has, in order.
     *
     * @return list<int>
     */
    private function upload(RoomType $room, int $n): array
    {
        return array_column(
            $this->post("/admin/room-types/{$room->id}/panoramas", ['panoramas' => $this->files($n)], self::JSON)
                ->assertCreated()
                ->json('panoramas'),
            'id'
        );
    }

    public function test_guest_and_admin_without_rooms_permission_are_blocked(): void
    {
        $room = $this->room();

        $this->getJson("/admin/room-types/{$room->id}/panoramas")->assertUnauthorized();

        $this->actingAs($this->admin([['hotels']]), 'admin')
            ->post("/admin/room-types/{$room->id}/panoramas", ['panoramas' => $this->files(1)], self::JSON)
            ->assertForbidden();
    }

    public function test_upload_many_appends_in_order_published_by_default(): void
    {
        $room = $this->room();
        $this->actingAs($this->dev(), 'developer');

        $ids = $this->upload($room, 3);
        $this->assertCount(3, $ids);

        $list = $this->getJson("/admin/room-types/{$room->id}/panoramas")->assertOk();
        $list->assertJsonCount(3, 'panoramas');
        $this->assertSame([0, 1, 2], array_column($list->json('panoramas'), 'sortOrder'));
        $this->assertSame([true, true, true], array_column($list->json('panoramas'), 'isPublished'));

        foreach (RoomTypePanorama::all() as $panorama) {
            $this->assertStringStartsWith("uploads/rooms/{$room->id}/", $panorama->path);
            Storage::disk('public')->assertExists($panorama->path);
        }

        // A later upload goes to the end.
        $more = $this->upload($room, 1);
        $this->assertSame(3, RoomTypePanorama::findOrFail(end($more))->sort_order);
    }

    public function test_single_file_field_also_works_and_bad_files_are_rejected(): void
    {
        $room = $this->room();
        $this->actingAs($this->dev(), 'developer');

        $this->post("/admin/room-types/{$room->id}/panoramas", [
            'panorama' => UploadedFile::fake()->image('one.jpg', 100, 50),
        ], self::JSON)->assertCreated()->assertJsonCount(1, 'panoramas');

        $this->post("/admin/room-types/{$room->id}/panoramas", [], self::JSON)->assertStatus(422);

        $this->post("/admin/room-types/{$room->id}/panoramas", [
            'panoramas' => [UploadedFile::fake()->image('x.png', 10, 10)],
        ], self::JSON)->assertStatus(422);

        $this->post("/admin/room-types/{$room->id}/panoramas", [
            'panoramas' => [UploadedFile::fake()->image('big.jpg', 20, 10)->size(15361)],
        ], self::JSON)->assertStatus(422);

        $this->assertSame(1, RoomTypePanorama::count());
    }

    public function test_publish_toggle_and_legacy_url_follow_the_first_published(): void
    {
        $room = $this->room();
        $this->actingAs($this->dev(), 'developer');
        [$a, $b] = $this->upload($room, 2);

        $first = RoomTypePanorama::findOrFail($a);
        $second = RoomTypePanorama::findOrFail($b);
        $this->assertSame($first->path, $room->fresh()->panorama_path);

        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$a}", ['isPublished' => false])
            ->assertOk()
            ->assertJsonPath('panoramas.0.isPublished', false);
        $this->assertSame($second->path, $room->fresh()->panorama_path);

        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$b}", ['isPublished' => false])->assertOk();
        $this->assertNull($room->fresh()->panorama_path);

        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$a}", [])->assertStatus(422);
    }

    public function test_reorder_changes_the_order_and_validates_ids(): void
    {
        $room = $this->room();
        $other = $this->room('other');
        $this->actingAs($this->dev(), 'developer');
        [$a, $b, $c] = $this->upload($room, 3);
        [$foreign] = $this->upload($other, 1);

        $this->patchJson("/admin/room-types/{$room->id}/panoramas/reorder", ['ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('panoramas.0.id', $c)
            ->assertJsonPath('panoramas.1.id', $a)
            ->assertJsonPath('panoramas.2.id', $b);

        $this->assertSame(
            RoomTypePanorama::findOrFail($c)->path,
            $room->fresh()->panorama_path
        );

        // Ids left out keep their relative order after the listed ones.
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/reorder", ['ids' => [$b]])
            ->assertOk()
            ->assertJsonPath('panoramas.0.id', $b)
            ->assertJsonPath('panoramas.1.id', $c)
            ->assertJsonPath('panoramas.2.id', $a);

        $this->patchJson("/admin/room-types/{$room->id}/panoramas/reorder", ['ids' => [$a, $foreign]])
            ->assertStatus(422);
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/reorder", ['ids' => [$a, $a]])
            ->assertStatus(422);
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/reorder", [])
            ->assertStatus(422);
    }

    public function test_delete_removes_row_and_file_and_is_scoped_to_the_room(): void
    {
        $room = $this->room();
        $other = $this->room('other');
        $this->actingAs($this->dev(), 'developer');
        [$a, $b] = $this->upload($room, 2);
        [$foreign] = $this->upload($other, 1);

        $path = RoomTypePanorama::findOrFail($a)->path;

        $this->deleteJson("/admin/room-types/{$room->id}/panoramas/{$foreign}")->assertNotFound();
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$foreign}", ['isPublished' => false])->assertNotFound();

        $this->deleteJson("/admin/room-types/{$room->id}/panoramas/{$a}")
            ->assertOk()
            ->assertJsonCount(1, 'panoramas')
            ->assertJsonPath('panoramas.0.id', $b);

        $this->assertNull(RoomTypePanorama::find($a));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(RoomTypePanorama::findOrFail($b)->path, $room->fresh()->panorama_path);
    }

    public function test_public_catalog_returns_only_published_photos_in_admin_order(): void
    {
        $room = $this->room();
        $this->actingAs($this->dev(), 'developer');
        [$a, $b, $c] = $this->upload($room, 3);

        $this->patchJson("/admin/room-types/{$room->id}/panoramas/reorder", ['ids' => [$c, $b, $a]])->assertOk();
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$b}", ['isPublished' => false])->assertOk();

        $catalog = $this->getJson('/catalog')->assertOk();
        $catalog->assertJsonCount(2, 'roomTypes.0.panoramas');
        $this->assertSame([$c, $a], array_column($catalog->json('roomTypes.0.panoramas'), 'id'));
        $catalog->assertJsonPath('roomTypes.0.panoramas.0.url', RoomTypePanorama::urlFor(RoomTypePanorama::findOrFail($c)->path));
        $catalog->assertJsonPath('roomTypes.0.panoramaUrl', RoomTypePanorama::urlFor(RoomTypePanorama::findOrFail($c)->path));

        // The admin list still returns every photo, published or not.
        $this->getJson("/admin/room-types/{$room->id}")
            ->assertOk()
            ->assertJsonCount(3, 'roomType.panoramas');

        // Nothing published: the catalog has no photos and no legacy url.
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$a}", ['isPublished' => false])->assertOk();
        $this->patchJson("/admin/room-types/{$room->id}/panoramas/{$c}", ['isPublished' => false])->assertOk();

        $this->getJson('/catalog')->assertOk()
            ->assertJsonCount(0, 'roomTypes.0.panoramas')
            ->assertJsonPath('roomTypes.0.panoramaUrl', null);
    }

    public function test_legacy_single_field_replaces_all_photos(): void
    {
        $room = $this->room();
        $this->actingAs($this->dev(), 'developer');
        $ids = $this->upload($room, 2);
        $old = RoomTypePanorama::whereIn('id', $ids)->pluck('path')->all();

        $this->patch("/admin/room-types/{$room->id}", [
            'panorama' => UploadedFile::fake()->image('new.jpg', 800, 400),
        ], self::JSON)->assertOk();

        $this->assertSame(1, RoomTypePanorama::where('room_type_id', $room->id)->count());
        foreach ($old as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->patch("/admin/room-types/{$room->id}", ['remove_panorama' => 1], self::JSON)->assertOk();
        $this->assertSame(0, RoomTypePanorama::where('room_type_id', $room->id)->count());
        $this->assertNull($room->fresh()->panorama_path);
    }

    public function test_deleting_a_room_type_removes_its_photos(): void
    {
        $room = $this->room();
        $this->actingAs($this->dev(), 'developer');
        $this->upload($room, 2);
        $paths = RoomTypePanorama::pluck('path')->all();

        $this->deleteJson("/admin/room-types/{$room->id}")->assertOk();

        $this->assertSame(0, RoomTypePanorama::count());
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_migration_copies_each_existing_single_photo_into_the_table(): void
    {
        $with = $this->room('with');
        $with->update(['panorama_path' => 'uploads/rooms/1/legacy.jpg']);
        $linked = $this->room('linked');
        $linked->update(['panorama_path' => 'https://cdn.example.com/360.jpg']);
        $this->room('none');

        $migration = require database_path('migrations/2026_11_20_000300_create_room_type_panoramas_table.php');
        $migration->down();
        $migration->up();

        $rows = RoomTypePanorama::orderBy('room_type_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('uploads/rooms/1/legacy.jpg', $rows[0]->path);
        $this->assertSame('https://cdn.example.com/360.jpg', $rows[1]->path);
        $this->assertTrue($rows[0]->is_published);
        $this->assertSame(0, $rows[0]->sort_order);

        // The old column is kept.
        $this->assertSame('uploads/rooms/1/legacy.jpg', $with->fresh()->panorama_path);
    }
}
