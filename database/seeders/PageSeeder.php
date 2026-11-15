<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * The three legal pages, as drafts (the frontend keeps its static text until
 * the client publishes real content from the admin Pages screen).
 */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Page::SYSTEM_PAGES as $slug => $title) {
            Page::firstOrCreate(
                ['slug' => $slug],
                ['title' => $title, 'blocks' => [], 'status' => 'draft', 'is_system' => true]
            );
        }
    }
}
