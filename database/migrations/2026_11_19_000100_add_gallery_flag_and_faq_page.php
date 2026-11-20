<?php

use App\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the built-in FAQ page as a draft (staff fill it in from the Pages
     * screen). The file name is kept so databases that already ran it stay
     * consistent; on those, an unused `media_items.in_gallery` column may
     * remain and is harmless.
     */
    public function up(): void
    {
        if (Schema::hasTable('pages')) {
            Page::firstOrCreate(
                ['slug' => 'faq'],
                ['title' => Page::SYSTEM_PAGES['faq'], 'blocks' => [], 'status' => 'draft', 'is_system' => true]
            );
        }
    }

    public function down(): void
    {
        Page::where('slug', 'faq')->delete();
    }
};
