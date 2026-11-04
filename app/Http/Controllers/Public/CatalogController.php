<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\HotelResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\NewsResource;
use App\Http\Resources\RatePlanResource;
use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\News;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-shot published catalog for the Quasar SPA hydrate.
 *
 * Image columns are returned so the public site shows the same photos
 * as admin. Inline `data:` uploads are kept only up to the admin drop
 * limit; anything larger is dropped so one blob cannot exhaust memory.
 */
class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            $locations = Location::query()
                ->select([
                    'id', 'name', 'slug', 'description',
                    'highlights', 'phone', 'email', 'status', 'seo_title', 'seo_description',
                    'created_by_admin_id',
                ])
                ->selectRaw($this->boundedImageSql('hero_image'))
                ->selectRaw($this->boundedJsonImagesSql('gallery'))
                ->withCount([
                    'hotels' => fn ($query) => $query->where('status', 'published'),
                ])
                ->where('status', 'published')
                ->orderBy('name')
                ->get()
                ->each(fn (Location $location) => $this->scrubPublicMedia($location, ['hero_image'], ['gallery']));

            $hotels = Hotel::query()
                ->select([
                    'id', 'location_id', 'name', 'slug', 'short_description',
                    'description', 'address', 'area', 'property_type', 'star_rating',
                    'lat', 'lng', 'map_embed_url', 'phone', 'email',
                    'amenities', 'policies', 'nearby_landmarks',
                    'check_in_time', 'check_out_time', 'featured', 'status', 'seo_title',
                    'seo_description',
                ])
                ->selectRaw($this->boundedImageSql('hero_image'))
                ->selectRaw($this->boundedJsonImagesSql('gallery'))
                ->with('location:id,name,slug')
                ->where('status', 'published')
                ->whereHas('location', fn ($query) => $query->where('status', 'published'))
                ->orderBy('name')
                ->get()
                ->each(fn (Hotel $hotel) => $this->scrubPublicMedia($hotel, ['hero_image'], ['gallery']));

            $hotelIds = $hotels->pluck('id');

            $roomTypes = RoomType::query()
                ->select([
                    'id', 'hotel_id', 'name', 'slug', 'description',
                    'bed_type', 'room_size', 'max_adults', 'max_children',
                    'max_guests', 'amenities', 'base_inventory', 'status',
                    'panorama_path',
                ])
                ->selectRaw($this->boundedJsonImagesSql('images'))
                ->where('status', 'published')
                ->whereIn('hotel_id', $hotelIds)
                ->orderBy('name')
                ->get()
                ->each(fn (RoomType $roomType) => $this->scrubPublicMedia($roomType, [], ['images']));

            $roomTypeIds = $roomTypes->pluck('id');

            $ratePlans = RatePlan::query()
                ->where('status', 'published')
                ->whereIn('room_type_id', $roomTypeIds)
                ->orderBy('name')
                ->get();

            $news = News::query()
                ->with('location')
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get()
                ->each(fn (News $article) => $this->scrubPublicMedia($article, ['cover_image'], []));

            return response()->json([
                'locations' => LocationResource::collection($locations)->resolve(),
                'hotels' => HotelResource::collection($hotels)->resolve(),
                'roomTypes' => RoomTypeResource::collection($roomTypes)->resolve(),
                'ratePlans' => RatePlanResource::collection($ratePlans)->resolve(),
                'news' => NewsResource::collection($news)->resolve(),
            ]);
        } catch (Throwable $e) {
            Log::error('public.catalog failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  list<string>  $stringAttrs
     * @param  list<string>  $arrayAttrs
     */
    private function scrubPublicMedia(object $model, array $stringAttrs, array $arrayAttrs): void
    {
        foreach ($stringAttrs as $attr) {
            $value = $model->{$attr} ?? null;
            if (! is_string($value) || $this->isPublicImageUrl($value)) {
                continue;
            }
            $model->setAttribute($attr, null);
        }

        foreach ($arrayAttrs as $attr) {
            $values = $model->{$attr} ?? [];
            if (! is_array($values)) {
                $model->setAttribute($attr, []);
                continue;
            }
            $model->setAttribute(
                $attr,
                array_values(array_filter($values, fn ($src) => is_string($src) && $this->isPublicImageUrl($src)))
            );
        }
    }

    /**
     * Keep a single image string unless it is larger than an admin file drop.
     */
    private function boundedImageSql(string $column): string
    {
        $column = $this->imageColumn($column);

        return "CASE WHEN {$column} IS NULL OR LENGTH({$column}) > 3500000 THEN NULL ELSE {$column} END as {$column}";
    }

    /**
     * Keep a JSON image list unless the whole column is too large to hydrate.
     */
    private function boundedJsonImagesSql(string $column): string
    {
        $column = $this->imageColumn($column);

        return "CASE WHEN {$column} IS NULL OR LENGTH({$column}) > 12000000 THEN JSON_ARRAY() ELSE {$column} END as {$column}";
    }

    private function imageColumn(string $column): string
    {
        if (! preg_match('/^[a-z_]+$/', $column)) {
            throw new \InvalidArgumentException("Unexpected image column [{$column}].");
        }

        return $column;
    }

    private function isPublicImageUrl(string $src): bool
    {
        if ($src === '') {
            return false;
        }

        if (str_starts_with($src, 'data:image/')) {
            return strlen($src) <= 3_500_000;
        }

        if (str_starts_with($src, 'data:')) {
            return false;
        }

        return strlen($src) <= 2048;
    }
}
