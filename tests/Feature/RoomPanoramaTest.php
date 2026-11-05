<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Developer;
use App\Models\Feature;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomPanoramaTest extends TestCase
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

    private function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);
        if (! str_starts_with($url, 'http')) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return $url;
    }

    public function test_guest_cannot_upload_a_panorama(): void
    {
        $this->post('/admin/room-types', [
            'panorama' => UploadedFile::fake()->image('pano.jpg', 2000, 1000),
        ], self::JSON)->assertUnauthorized();
    }

    public function test_admin_without_rooms_permission_is_forbidden(): void
    {
        $this->actingAs($this->admin([['hotels']]), 'admin')
            ->post('/admin/room-types', [
                'hotelId' => 1,
                'name' => 'Deluxe',
                'slug' => 'deluxe',
                'panorama' => UploadedFile::fake()->image('pano.jpg'),
            ], self::JSON)
            ->assertForbidden();
    }

    public function test_create_stores_panorama_and_public_list_and_detail_expose_url(): void
    {
        $hotel = $this->hotel();
        $this->actingAs($this->dev(), 'developer');

        $created = $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Deluxe King',
            'slug' => 'deluxe-king',
            'status' => 'published',
            'panorama' => UploadedFile::fake()->image('pano.jpg', 2000, 1000),
        ], self::JSON)->assertCreated();

        $id = $created->json('roomType.id');
        $path = RoomType::findOrFail($id)->panorama_path;

        $this->assertIsString($path);
        $this->assertStringStartsWith("uploads/rooms/{$id}/", $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('public')->assertExists($path);

        $url = $this->publicUrl($path);
        $this->assertStringStartsWith('http', $url);
        $created->assertJsonPath('roomType.panoramaUrl', $url);

        $this->getJson('/admin/room-types')
            ->assertOk()
            ->assertJsonPath('roomTypes.0.panoramaUrl', $url);

        $this->getJson("/admin/room-types/{$id}")
            ->assertOk()
            ->assertJsonPath('roomType.panoramaUrl', $url);

        $catalog = $this->getJson('/catalog')->assertOk();
        $catalog->assertJsonPath('roomTypes.0.id', $id);
        $catalog->assertJsonPath('roomTypes.0.panoramaUrl', $url);

        RoomType::create([
            'hotel_id' => $hotel->id,
            'name' => 'Linked',
            'slug' => 'linked',
            'status' => 'published',
            'panorama_path' => 'https://cdn.example.com/360.jpg',
        ]);
        RoomType::create([
            'hotel_id' => $hotel->id,
            'name' => 'Plain',
            'slug' => 'plain',
            'status' => 'published',
        ]);

        $bySlug = collect($this->getJson('/catalog')->json('roomTypes'))->keyBy('slug');
        $this->assertSame('https://cdn.example.com/360.jpg', $bySlug['linked']['panoramaUrl']);
        $this->assertNull($bySlug['plain']['panoramaUrl']);
    }

    public function test_create_without_panorama_is_null_and_other_updates_keep_it(): void
    {
        $hotel = $this->hotel();
        $this->actingAs($this->dev(), 'developer');

        $created = $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Standard Twin',
            'slug' => 'standard-twin',
            'status' => 'published',
        ], self::JSON)->assertCreated()
            ->assertJsonPath('roomType.panoramaUrl', null);

        $id = $created->json('roomType.id');

        $this->patchJson("/admin/room-types/{$id}", ['name' => 'Standard Twin Room'])
            ->assertOk()
            ->assertJsonPath('roomType.name', 'Standard Twin Room')
            ->assertJsonPath('roomType.panoramaUrl', null);

        $withFile = $this->patch("/admin/room-types/{$id}", [
            'panorama' => UploadedFile::fake()->image('wide.jpeg', 800, 600),
        ], self::JSON)->assertOk();

        $path = RoomType::findOrFail($id)->panorama_path;
        $this->assertStringEndsWith('.jpg', $path);
        $withFile->assertJsonPath('roomType.panoramaUrl', $this->publicUrl($path));

        $this->patchJson("/admin/room-types/{$id}", ['description' => 'Quiet twin.'])
            ->assertOk()
            ->assertJsonPath('roomType.panoramaUrl', $this->publicUrl($path));
        Storage::disk('public')->assertExists($path);
    }

    public function test_replace_webp_deletes_old_file_and_upload_wins_over_remove(): void
    {
        $hotel = $this->hotel();
        $this->actingAs($this->dev(), 'developer');

        $id = $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Suite',
            'slug' => 'suite',
            'status' => 'published',
            'panorama' => UploadedFile::fake()->image('first.jpg', 2000, 1000),
        ], self::JSON)->assertCreated()->json('roomType.id');

        $old = RoomType::findOrFail($id)->panorama_path;

        $replaced = $this->patch("/admin/room-types/{$id}", [
            'panorama' => UploadedFile::fake()->image('second.webp', 2048, 1024),
            'remove_panorama' => 1,
        ], self::JSON)->assertOk();

        $path = RoomType::findOrFail($id)->panorama_path;
        $this->assertNotSame($old, $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($path);
        $replaced->assertJsonPath('roomType.panoramaUrl', $this->publicUrl($path));
        $this->getJson('/catalog')->assertJsonPath('roomTypes.0.panoramaUrl', $this->publicUrl($path));
    }

    public function test_remove_panorama_clears_url_and_deletes_file(): void
    {
        $hotel = $this->hotel();
        $this->actingAs($this->dev(), 'developer');

        $id = $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Garden',
            'slug' => 'garden',
            'status' => 'published',
            'panorama' => UploadedFile::fake()->image('garden.jpg', 1000, 500),
        ], self::JSON)->json('roomType.id');

        $path = RoomType::findOrFail($id)->panorama_path;

        $this->patchJson("/admin/room-types/{$id}", ['remove_panorama' => 1])
            ->assertOk()
            ->assertJsonPath('roomType.panoramaUrl', null);

        $this->assertNull(RoomType::findOrFail($id)->panorama_path);
        Storage::disk('public')->assertMissing($path);
        $this->getJson('/catalog')->assertJsonPath('roomTypes.0.panoramaUrl', null);
        $this->getJson("/admin/room-types/{$id}")->assertJsonPath('roomType.panoramaUrl', null);

        $this->deleteJson("/admin/room-types/{$id}")->assertOk();
        $this->assertNull(RoomType::find($id));
    }

    public function test_rejects_png_non_image_and_files_over_15mb(): void
    {
        $hotel = $this->hotel();
        $this->actingAs($this->dev(), 'developer');

        $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Bad',
            'slug' => 'bad',
            'panorama' => UploadedFile::fake()->image('pano.png', 20, 10),
        ], self::JSON)->assertStatus(422)->assertJsonStructure(['errors' => ['panorama']]);

        $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Bad',
            'slug' => 'bad',
            'panorama' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ], self::JSON)->assertStatus(422);

        $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Bad',
            'slug' => 'bad',
            'panorama' => UploadedFile::fake()->image('big.jpg', 20, 10)->size(15361),
        ], self::JSON)->assertStatus(422);

        $this->assertSame(0, RoomType::count());

        $id = $this->post('/admin/room-types', [
            'hotelId' => $hotel->id,
            'name' => 'Kept',
            'slug' => 'kept',
            'status' => 'published',
            'panorama' => UploadedFile::fake()->image('ok.jpg', 100, 80),
        ], self::JSON)->assertCreated()->json('roomType.id');

        $path = RoomType::findOrFail($id)->panorama_path;

        $this->patch("/admin/room-types/{$id}", [
            'panorama' => UploadedFile::fake()->image('nope.png'),
        ], self::JSON)->assertStatus(422);

        $this->assertSame($path, RoomType::findOrFail($id)->panorama_path);
        Storage::disk('public')->assertExists($path);
    }
}
