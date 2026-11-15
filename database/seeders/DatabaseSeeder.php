<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Copy committed seed photos onto the public disk first.
        $this->call(SeedAssetsSeeder::class);

        $this->call(DeveloperSeeder::class);
        $this->call(RoleSeeder::class);
        $this->call(FeatureSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(PackageSeeder::class);
        $this->call(LocationSeeder::class);
        $this->call(ServiceSeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(HotelSeeder::class);
        $this->call(RoomTypeSeeder::class);
        $this->call(RatePlanSeeder::class);

        // Real client property (2026-09-29) — see GreyonShvSeeder's docblock.
        $this->call(GreyonShvSeeder::class);

        // Real client property (2026-10-06) — see GreyonPhnomPenhSeeder's docblock.
        $this->call(GreyonPhnomPenhSeeder::class);

        $this->call(AdminSeeder::class);
        $this->call(MediaSeeder::class);
        $this->call(NewsSeeder::class);
        $this->call(HeroSlideSeeder::class);
        $this->call(PageSeeder::class);
        $this->call(EnquirySeeder::class);

        // A guest customer you can log in with straight away.
        User::updateOrCreate(
            ['email' => 'guest@greyon.test'],
            [
                'name' => 'Test Guest',
                'role' => 'guest',
                'password' => 'password',
            ]
        );

        // A hotel manager you can log in with straight away.
        User::updateOrCreate(
            ['email' => 'manager@greyon.test'],
            [
                'name' => 'Test Manager',
                'role' => 'manager',
                'password' => 'password',
            ]
        );

        // Bookings after users exist so guest@greyon.test can own seed stays.
        $this->call(BookingSeeder::class);
    }
}
