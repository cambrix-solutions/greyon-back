<?php

use App\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gallery: a media item shows on the public gallery unless staff switch
     * `in_gallery` off. FAQ: a built-in page, created as a draft so the
     * frontend keeps its own text until the client publishes.
     */
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->boolean('in_gallery')->default(true)->after('alt');
        });

        if (Schema::hasTable('pages')) {
            Page::firstOrCreate(
                ['slug' => 'faq'],
                ['title' => Page::SYSTEM_PAGES['faq'], 'blocks' => [], 'status' => 'draft', 'is_system' => true]
            );
        }
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('in_gallery');
        });
    }
};
