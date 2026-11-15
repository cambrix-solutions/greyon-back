<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pages CMS - perm `pages`. Site-wide content, so only a global seat (or a
 * developer) can write; the legal pages are `is_system` (cannot be deleted,
 * slug is fixed).
 *
 * Content is a list of typed blocks - never raw HTML:
 *   heading   {type, level: 2|3, text}
 *   paragraph {type, text}
 *   list      {type, style: bullet|number, items: [text]}
 *   image     {type, url, alt?, caption?}
 *   callout   {type, text}
 *   divider   {type}
 */
class PageController extends Controller
{
    use AuthorizesAdminPanel;

    private const BLOCK_TYPES = ['heading', 'paragraph', 'list', 'image', 'callout', 'divider'];

    public function __construct(private readonly AccessService $access) {}

    public function index(): JsonResponse
    {
        $pages = Page::query()->orderByDesc('is_system')->orderBy('title')->get();

        return response()->json(['pages' => PageResource::collection($pages)]);
    }

    public function show(Page $page): JsonResponse
    {
        return response()->json(['page' => new PageResource($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertGlobalSeat($request);
        $data = $this->validated($request, null);

        $page = Page::create([
            ...$data,
            'is_system' => false,
            'published_at' => ($data['status'] ?? 'draft') === 'published' ? now() : null,
            'updated_by_admin_id' => $this->actingAdmin($request)?->id,
        ]);

        return response()->json(['page' => new PageResource($page)], 201);
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        $this->assertGlobalSeat($request);
        $data = $this->validated($request, $page);

        if (isset($data['slug']) && $page->is_system && $data['slug'] !== $page->slug) {
            throw new HttpException(400, 'The address of a built-in page cannot change.');
        }

        if (($data['status'] ?? $page->status) === 'published' && $page->published_at === null) {
            $data['published_at'] = now();
        }

        $page->update([...$data, 'updated_by_admin_id' => $this->actingAdmin($request)?->id ?? $page->updated_by_admin_id]);

        return response()->json(['page' => new PageResource($page->refresh())]);
    }

    public function destroy(Request $request, Page $page): JsonResponse
    {
        $this->assertGlobalSeat($request);

        if ($page->is_system) {
            throw new HttpException(400, 'Built-in pages cannot be deleted. Set it to draft instead.');
        }

        $page->delete();

        return response()->json(['message' => 'Page deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Page $page): array
    {
        $sometimes = $page ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => [$sometimes, 'string', 'max:255'],
            'slug' => [$sometimes, 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'blocks' => [$sometimes, 'array', 'max:300'],
            'blocks.*.type' => ['required', Rule::in(self::BLOCK_TYPES)],
            'blocks.*.text' => ['required_if:blocks.*.type,heading,paragraph,callout', 'nullable', 'string', 'max:10000'],
            'blocks.*.level' => ['required_if:blocks.*.type,heading', 'nullable', Rule::in([2, 3])],
            'blocks.*.style' => ['required_if:blocks.*.type,list', 'nullable', Rule::in(['bullet', 'number'])],
            'blocks.*.items' => ['required_if:blocks.*.type,list', 'nullable', 'array', 'max:100'],
            'blocks.*.items.*' => ['string', 'max:2000'],
            'blocks.*.url' => ['required_if:blocks.*.type,image', 'nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\/|uploads\/)/i'],
            'blocks.*.alt' => ['nullable', 'string', 'max:255'],
            'blocks.*.caption' => ['nullable', 'string', 'max:500'],
            'seoTitle' => ['nullable', 'string', 'max:255'],
            'seoDescription' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ], [
            'blocks.*.url.regex' => 'Image address must start with http(s)://, / or uploads/.',
        ]);

        if (array_key_exists('blocks', $data)) {
            $data['blocks'] = array_map(fn (array $b) => $this->cleanBlock($b), $data['blocks']);
        }

        foreach (['seoTitle' => 'seo_title', 'seoDescription' => 'seo_description'] as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $data[$snake] = $data[$camel];
                unset($data[$camel]);
            }
        }

        return $data;
    }

    /**
     * Keep only the keys each block type defines - nothing else gets stored.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function cleanBlock(array $b): array
    {
        return match ($b['type']) {
            'heading' => ['type' => 'heading', 'level' => (int) $b['level'], 'text' => $b['text']],
            'paragraph' => ['type' => 'paragraph', 'text' => $b['text']],
            'callout' => ['type' => 'callout', 'text' => $b['text']],
            'list' => ['type' => 'list', 'style' => $b['style'], 'items' => array_values($b['items'])],
            'image' => ['type' => 'image', 'url' => $b['url'], 'alt' => $b['alt'] ?? null, 'caption' => $b['caption'] ?? null],
            default => ['type' => 'divider'],
        };
    }
}
