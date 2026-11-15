<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Hierarchy:
 *   Dev (separate guard) → Admin (global) → Manager (locations)
 *                                        → Hotel admin (hotels)
 *   Guest / customer is self-serve on the public site (own bookings).
 *
 * `is_global` / `scope` drive App\Services\AccessService.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'description' => 'Org admin — all locations; creates managers & hotel desks; assigns location/hotel scope. Created by Developer.',
                'is_global' => true,
                'scope' => 'none',
            ],
            [
                'name' => 'manager',
                'description' => 'Runs one or many destinations (locationIds). Created by Admin. Does not create guest accounts.',
                'is_global' => false,
                'scope' => 'location',
            ],
            [
                'name' => 'hotel_admin',
                'description' => 'Front desk for assigned hotels (hotelIds). Created by Admin.',
                'is_global' => false,
                'scope' => 'hotel',
            ],
            [
                'name' => 'customer',
                'description' => 'Guest / customer — public site only; self-serve reservation; sees own stays. Not an admin seat.',
                'is_global' => false,
                'scope' => 'none',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                [
                    'description' => $role['description'],
                    'is_global' => $role['is_global'],
                    'scope' => $role['scope'],
                ]
            );
        }
    }
}
