<?php

use App\Models\Feature;
use App\Models\Package;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client feedback (12 Nov): say "reservation", not "booking", in what people
     * read. Text only - route names, table names, feature keys and API field
     * names are unchanged. This brings already-seeded rows in line with the
     * seeders / notification service.
     */
    public function up(): void
    {
        Feature::where('key', 'bookings')->update(['label' => 'Reservations']);
        Feature::where('key', 'booking_public')->update(['label' => 'Public reservation engine']);
        Feature::where('key', 'pages')->update(['description' => 'Edit Privacy, Terms, Reservation terms and other site pages']);

        Role::where('name', 'guest')->update([
            'description' => 'Guest / customer — public site only; self-serve reservation; sees own stays. Not an admin seat.',
        ]);

        Package::where('description', 'like', '%bookings%')->get()->each(
            fn (Package $p) => $p->update(['description' => str_replace('bookings', 'reservations', $p->description)])
        );

        if (Schema::hasTable('staff_notifications')) {
            DB::table('staff_notifications')->where('title', 'New booking')->update(['title' => 'New reservation']);

            DB::table('staff_notifications')->where('title', 'like', 'Booking %')->get(['id', 'title'])->each(
                fn ($row) => DB::table('staff_notifications')->where('id', $row->id)
                    ->update(['title' => 'Reservation '.substr($row->title, strlen('Booking '))])
            );
        }
    }

    public function down(): void
    {
        // Wording only; nothing to restore.
    }
};
