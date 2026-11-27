<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Admin;
use App\Models\Location;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec section 8 "Locations" — perm `locations`
 * (`permission:locations` on every route in routes/admin.php).
 *
 * Scope (spec section 5), entirely schema-driven via
 * App\Models\Role::scope (see AccessService — no role names
 * hard-coded here): a location-scoped role only sees locations in its
 * `locationIds`; a hotel-scoped role only sees a location if one of
 * its hotels is in it. "Resource exists but out of scope → 404
 * preferred" — show()/update()/destroy() 404 rather than 403 for a
 * location an admin can't reach, so as not to leak that it exists.
 *
 * store() authorization (2026-09-15 policy): a developer can always
 * create (bypasses this controller's `permission:` gate entirely). An
 * admin can create iff their effective packages grant the `locations`
 * feature — already enforced by the route's `permission:locations`
 * middleware before this method runs, so store() adds no further
 * scope/global check on top of it. This replaces an earlier
 * global-seats-only restriction on create — the user judged that too
 * restrictive once an admin's package already grants the feature.
 *
 * destroy() is still restricted to global seats — tearing down a whole
 * destination wasn't part of this change; only create moved to
 * feature-only.
 *
 * update() field-level gating (2026-09-15, reworked 2026-09-16): the
 * seeded `permissions` catalog (App\Models\Permission) lists 5 entries
 * under the `locations` feature — `locations_list`, `locations_managers`,
 * `locations_hotels`, `locations_publish`, `locations_seo` — matching
 * the developer package-builder UI (toggle the parent `locations`
 * feature, then fine-tune which of its permissions a package actually
 * grants). Originally (Round 8) these were child *features* strung
 * together via `features.parent_key`; the doc maker's "package ->
 * permissions, feature -> permissions" sample replaced that with a
 * real `permissions` table + `package_permission` — see
 * App\Services\AccessService::hasPermission()/effectivePermissionKeys().
 * Behavior for admins is unchanged, only the storage moved. Three of
 * the 5 gate specific fields on update(), on top of the parent
 * `locations` feature gate (still required via route middleware just
 * to reach this controller at all):
 *   - name/slug/description/phone/email/heroImage/gallery/highlights/amenities →
 *     `locations_list` ("view and edit destination content")
 *   - status → `locations_publish` ("publish / archive")
 *   - seoTitle/seoDescription → `locations_seo` ("SEO fields")
 * `locations_hotels` (Hotel's own `locationId` linking — a structural
 * FK relationship, not an action on Location itself) and
 * `locations_managers` (assigning a manager to a destination) are
 * intentionally **not** wired to anything here: the former has no
 * corresponding Location-side action to gate, and the latter stays a
 * developer-only capability (`routes/developer.php`) — the user
 * explicitly chose not to open a new "admin assigns other admins"
 * capability for now (Round 8). Both remain real, developer-CRUD-able,
 * package-assignable permission keys (so they show and toggle
 * correctly in the package builder); they just don't unlock anything
 * yet. index/show/destroy are unaffected by any of this — only
 * update()'s field set is gated per-field, so an admin missing one
 * permission can still touch every other field in the same request.
 *
 * store() quantity cap (2026-09-16): on top of the feature gate above,
 * an admin's packages can also cap *how many* locations they're
 * allowed to create in total — App\Services\AccessService::effectiveLimit()
 * against the `locations` resource key (App\Models\PackageLimit). Not
 * a scope restriction (an admin can still create outside their own
 * scope, per the 2026-09-15 policy above) — this is purely "how many,"
 * counted from `locations.created_by_admin_id`, which this method sets
 * on every location it creates. Round 12 (2026-09-22) capped every
 * seeded package by default — see `database/seeders/PackageSeeder.php`'s
 * docblock: the sole `Admin` package is capped at 3, and all 3
 * `Manager · <City>` packages are capped at 0 (can't add locations at
 * all — "no adding properties"). `effectiveLimit()` returns `null` for
 * "no cap," so a developer building a brand-new, genuinely uncapped
 * package can still get one by simply omitting a `locations` limit row
 * — the common no-cap case costs one cheap collection scan and no
 * query.
 */
class LocationController extends Controller
{
    use AuthorizesAdminPanel;

    public function __construct(private readonly AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $locations = Location::withCount('hotels')
            ->get()
            ->filter(fn (Location $location) => $this->adminCanAccessLocation($request, $location->id))
            ->values();

        return response()->json(['locations' => LocationResource::collection($locations)]);
    }

    public function show(Request $request, Location $location): JsonResponse
    {
        $this->authorizeScope($request, $location);

        return response()->json(['location' => new LocationResource($location->loadCount('hotels'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->actingAdmin($request);
        if ($admin) {
            if (! $this->access->hasPermission($admin, 'locations_create')) {
                throw new HttpException(403, 'Missing permission: locations_create');
            }
            $this->assertWithinCreateLimit($admin);
        }

        $location = Location::create([
            ...$this->validated($request),
            'created_by_admin_id' => $admin?->id,
        ]);

        // Re-fetch so DB column defaults not present in the request
        // (e.g. status) are reflected in the response — Eloquent's
        // create() doesn't otherwise pick those up on the in-memory
        // instance.
        return response()->json(['location' => new LocationResource($location->refresh())], 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $this->authorizeScope($request, $location);

        $data = $this->validated($request, $location);

        $admin = $this->actingAdmin($request);
        if ($admin) {
            $this->authorizeFields($admin, $data);
        }

        $location->update($data);

        return response()->json(['location' => new LocationResource($location)]);
    }

    public function destroy(Request $request, Location $location): JsonResponse
    {
        $this->assertGlobalSeat($request);

        if ($location->hotels()->exists()) {
            throw new HttpException(400, 'Cannot delete a location that still has hotels under it.');
        }

        $location->delete();

        return response()->json(['message' => 'Location deleted.']);
    }

    private function authorizeScope(Request $request, Location $location): void
    {
        if (! $this->adminCanAccessLocation($request, $location->id)) {
            throw new HttpException(404, 'Not found.');
        }
    }

    /**
     * The `locations` package-limit check (see class docblock) — how
     * many *this admin* has created so far, not a global or per-package
     * count. `effectiveLimit()` returning `null` means unlimited.
     */
    private function assertWithinCreateLimit(Admin $admin): void
    {
        $limit = $this->access->effectiveLimit($admin, 'locations', 'locations');

        if ($limit === null) {
            return;
        }

        $used = Location::where('created_by_admin_id', $admin->id)->count();

        if ($used >= $limit) {
            throw new HttpException(403, "Location limit reached ({$used}/{$limit}) for your package(s).");
        }
    }

    /**
     * Fine-grained permission field gating for update() — see class
     * docblock. Checked before anything is saved, so a mixed request
     * (some allowed fields, some not) fails atomically rather than
     * partially applying.
     *
     * @param  array<string, mixed>  $data  Already snake_cased by validated().
     */
    private function authorizeFields(Admin $admin, array $data): void
    {
        $contentFields = ['name', 'slug', 'description', 'phone', 'email', 'hero_image', 'gallery', 'highlights', 'amenities', 'landmarks'];

        if (array_intersect($contentFields, array_keys($data)) !== [] && ! $this->access->hasPermission($admin, 'locations_list')) {
            throw new HttpException(403, 'Missing permission: locations_list');
        }

        if (array_key_exists('status', $data) && ! $this->access->hasPermission($admin, 'locations_publish')) {
            throw new HttpException(403, 'Missing permission: locations_publish');
        }

        if (
            (array_key_exists('seo_title', $data) || array_key_exists('seo_description', $data))
            && ! $this->access->hasPermission($admin, 'locations_seo')
        ) {
            throw new HttpException(403, 'Missing permission: locations_seo');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Location $location = null): array
    {
        $data = $request->validate([
            'name' => [$location ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [
                $location ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('locations', 'slug')->ignore($location?->id),
            ],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'heroImage' => ['nullable', 'string', 'max:2048'],
            'gallery' => ['array'],
            'gallery.*' => ['string'],
            'highlights' => ['array'],
            'highlights.*' => ['string'],
            'amenities' => ['array'],
            'amenities.*' => ['string'],
            'landmarks' => ['array', 'max:50'],
            'landmarks.*.place' => ['required', 'string', 'max:255'],
            'landmarks.*.distance' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'seoTitle' => ['nullable', 'string', 'max:255'],
            'seoDescription' => ['nullable', 'string'],
        ]);

        return $this->mapCamel($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapCamel(array $data): array
    {
        $map = [
            'heroImage' => 'hero_image',
            'seoTitle' => 'seo_title',
            'seoDescription' => 'seo_description',
        ];

        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $data[$snake] = $data[$camel];
                unset($data[$camel]);
            }
        }

        return $data;
    }
}
