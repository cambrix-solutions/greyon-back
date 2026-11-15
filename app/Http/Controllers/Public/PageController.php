<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Page;
use Illuminate\Http\JsonResponse;

/**
 * Published CMS pages for the marketing site. A draft is a 404, so the
 * frontend can fall back to its built-in text until a page is published.
 */
class PageController extends Controller
{
    public function index(): JsonResponse
    {
        $pages = Page::query()->where('status', 'published')->orderBy('title')->get(['slug', 'title']);

        return response()->json(['pages' => $pages->map(fn (Page $p) => ['slug' => $p->slug, 'title' => $p->title])->values()]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = Page::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json(['page' => new PageResource($page)]);
    }
}
