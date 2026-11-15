<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

/**
 * Feature catalog matching greyon SPA `src/data/seed-features.ts`
 * (parent modules only). Location sub-keys live in PermissionSeeder.
 */
class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $adminParents = [
            ['dashboard', 'Dashboard', 'Admin home / ops overview'],
            ['locations', 'Destinations', 'Destination CMS — list, detail hub (hotels & rooms under a city), publish'],
            ['hotels', 'Hotels', 'Hotel CMS (linked to locations via locationId)'],
            ['rooms', 'Room types', 'Rooms per hotel'],
            ['rates', 'Rates & availability', 'Inventory and nightly pricing'],
            ['bookings', 'Reservations', 'Reservation inbox / management'],
            ['news', 'News', 'News / blog CMS'],
            // Managers get this feature (scoped). Product 2026-09-22: keep Manager
            // access when Media moves to real object storage — not Admin-only.
            ['media', 'Media library', 'Asset library (Managers: location/hotel scoped)'],
            ['enquiries', 'Enquiries', 'Contact form inbox'],
            ['settings', 'SEO / Settings', 'Site defaults and SEO'],
            ['users', 'People', 'Create managers & hotel desks; assign seats (roles) with location/hotel scope'],
            ['features', 'Seat types', 'Developer: build seat types (packages) from roles → features → permissions'],
            // Appended, not inserted — keeps every existing feature's
            // sort_order unchanged. Client requirements doc (2026-10-04),
            // item 6: a selectable Services catalog for the hotel form,
            // replacing free-text input.
            ['services', 'Services', 'Selectable service catalog for hotels (replaces free-text service input)'],
            // Appended 2026-10-05. Client requirements doc item 5: a
            // selectable Products catalog for the hotel form (same
            // many-to-many pattern as Services).
            ['products', 'Products', 'Selectable product catalog for hotels'],
            // Appended 2026-10-22: home-page hero slider, its own seat
            // feature instead of riding on `settings`.
            ['hero_slides', 'Hero slides', 'Home page hero slider'],
            // Appended 2026-11-15: Access -> Roles (admin-created custom roles).
            ['roles', 'Roles', 'Create custom roles and choose which features each role can use'],
            // Appended 2026-11-15: Pages CMS (Privacy, Terms, Booking terms...).
            ['pages', 'Pages', 'Edit Privacy, Terms, Reservation terms and other site pages'],
            // Appended 2026-11-17: Guests and Reports pages (Phase 2, step 8).
            ['guests', 'Guests', 'Guest list, reservation history and staff notes'],
            ['reports', 'Reports', 'Reservation totals by destination, hotel and source, with CSV export'],
            // Appended 2026-11-17: Calendar page (month grid + bulk edit).
            ['calendar', 'Calendar', 'Month grid of units, reservations, stop-sell and prices, with bulk edit'],
            // Appended 2026-11-17: Offers / special rates.
            ['offers', 'Offers', 'Special rates and promo codes shown to guests and applied at quote time'],
            // Appended 2026-11-18: Activity log (who changed what).
            ['activity', 'Activity log', 'Who changed what and when, limited to the hotels the seat can see'],
        ];

        foreach ($adminParents as $i => [$key, $label, $description]) {
            Feature::updateOrCreate(
                ['key' => $key],
                [
                    'label' => $label,
                    'description' => $description,
                    'category' => 'admin',
                    'sort_order' => $i,
                ]
            );
        }

        $public = [
            ['booking_public', 'Public reservation engine', 'Guest-facing /booking flow'],
            ['news_public', 'Public news', 'Guest-facing news pages'],
            ['contact_public', 'Public contact form', 'Guest-facing /contact'],
            ['portfolios', 'Portfolio stubs', 'Coming-soon portfolio pages'],
        ];

        foreach ($public as $i => [$key, $label, $description]) {
            Feature::updateOrCreate(
                ['key' => $key],
                [
                    'label' => $label,
                    'description' => $description,
                    'category' => 'public',
                    'sort_order' => $i,
                ]
            );
        }
    }
}
