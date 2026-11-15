<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Models\Feature;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Access -> Roles. An org admin builds their own roles ("Front desk",
 * "Accountant"...) and chooses which features each one can use.
 *
 * A custom role is a Package (`is_system = false`, `created_by_admin_id` set)
 * - the same "seat" the People page already assigns - so People, scope
 * checks and permission middleware need no changes. The scope kind is carried
 * by an existing role: `location` -> `manager`, `hotel` -> `hotel_admin`.
 *
 * Safety rules:
 *  - a role can only grant features the creating admin holds themselves
 *    (and never the developer-only `features` key), so roles cannot be used
 *    to escalate access;
 *  - fine-grained permissions follow the chosen features, limited to the
 *    permissions the creating admin holds (a withheld `locations_create`
 *    stays withheld);
 *  - system seats are listed but read-only here.
 */
class CustomRoleController extends Controller
{
    /** scope kind -> the existing role that carries it. */
    private const CARRIER_ROLES = ['location' => 'manager', 'hotel' => 'hotel_admin'];

    /** Never delegable from this page. */
    private const NEVER_GRANT = ['features'];

    public function __construct(private readonly AccessService $access) {}

    public function options(Request $request): JsonResponse
    {
        $features = $this->grantableFeatures($request)
            ->map(fn (Feature $f) => ['key' => $f->key, 'label' => $f->label, 'description' => $f->description])
            ->values();

        return response()->json([
            'scopes' => [
                ['key' => 'location', 'label' => 'Specific destinations', 'description' => 'The person works in the destinations you pick when you add them.'],
                ['key' => 'hotel', 'label' => 'Specific hotels', 'description' => 'The person works only in the hotels you pick when you add them.'],
            ],
            'features' => $features,
        ]);
    }

    public function index(): JsonResponse
    {
        $packages = Package::query()
            ->with(['roles', 'features', 'permissions'])
            ->withCount('admins')
            ->where(function ($q) {
                $q->where(fn ($c) => $c->where('is_system', false)->whereNotNull('created_by_admin_id'))
                    ->orWhere(function ($s) {
                        $s->where('is_system', true)
                            ->whereHas('roles', fn ($r) => $r->whereIn('name', array_values(self::CARRIER_ROLES)))
                            ->whereDoesntHave('roles', fn ($r) => $r->where('name', 'admin'));
                    });
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json(['roles' => PackageResource::collection($packages)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $package = Package::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'created_by_admin_id' => $request->user('admin')->id,
        ]);

        $this->syncParts($request, $package, $data['scope'], $data['featureKeys']);

        return response()->json(['role' => new PackageResource($this->load($package))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $package = $this->customOrFail($id);
        $data = $this->validated($request, $package);

        if (isset($data['scope']) && $data['scope'] !== $this->scopeOf($package) && $package->admins()->exists()) {
            throw ValidationException::withMessages([
                'scope' => 'This role is already assigned to people, so its scope cannot change. Create a new role instead.',
            ]);
        }

        $package->update([
            'name' => $data['name'] ?? $package->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $package->description,
        ]);

        $this->syncParts(
            $request,
            $package,
            $data['scope'] ?? $this->scopeOf($package),
            $data['featureKeys'] ?? $package->features()->pluck('key')->all(),
            syncRole: isset($data['scope']),
        );

        return response()->json(['role' => new PackageResource($this->load($package))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $package = $this->customOrFail($id);

        if ($package->admins()->exists()) {
            throw new HttpException(400, 'This role is assigned to people. Reassign them first.');
        }

        $package->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /** Out-of-scope or system rows are 404, not 403 (same rule as the rest of the panel). */
    private function customOrFail(int $id): Package
    {
        $package = Package::where('id', $id)
            ->where('is_system', false)
            ->whereNotNull('created_by_admin_id')
            ->first();

        if (! $package) {
            throw new HttpException(404, 'Role not found.');
        }

        return $package;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Package $package): array
    {
        $data = $request->validate([
            'name' => [$package ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('packages', 'name')->ignore($package?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'scope' => [$package ? 'sometimes' : 'required', Rule::in(array_keys(self::CARRIER_ROLES))],
            'featureKeys' => [$package ? 'sometimes' : 'required', 'array', 'min:1'],
            'featureKeys.*' => ['string', 'distinct'],
        ]);

        if (isset($data['featureKeys'])) {
            $allowed = $this->grantableFeatures($request)->pluck('key')->all();
            $denied = array_values(array_diff($data['featureKeys'], $allowed));

            if ($denied !== []) {
                throw ValidationException::withMessages([
                    'featureKeys' => 'You can only give a role features you have yourself. Not allowed: '.implode(', ', $denied).'.',
                ]);
            }
        }

        return $data;
    }

    /**
     * Admin-panel features the logged-in admin holds, minus the never-delegated ones.
     *
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    private function grantableFeatures(Request $request)
    {
        $own = $this->access->effectiveFeatureKeys($request->user('admin'));

        return Feature::query()
            ->where('category', 'admin')
            ->whereIn('key', $own)
            ->whereNotIn('key', self::NEVER_GRANT)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  list<string>  $featureKeys
     */
    private function syncParts(Request $request, Package $package, string $scope, array $featureKeys, bool $syncRole = true): void
    {
        if ($syncRole) {
            $roleId = Role::where('name', self::CARRIER_ROLES[$scope])->value('id');

            if (! $roleId) {
                throw new HttpException(500, 'The "'.self::CARRIER_ROLES[$scope].'" base role is missing. Run the seeders.');
            }

            $package->roles()->sync([$roleId]);
        }

        $package->features()->sync(Feature::whereIn('key', $featureKeys)->pluck('id'));

        $ownPermissions = $this->access->effectivePermissionKeys($request->user('admin'));
        $package->permissions()->sync(
            Permission::query()
                ->whereHas('feature', fn ($q) => $q->whereIn('key', $featureKeys))
                ->whereIn('key', $ownPermissions)
                ->pluck('id')
        );
    }

    private function scopeOf(Package $package): ?string
    {
        $roleScope = $package->roles()->value('scope');

        return in_array($roleScope, array_keys(self::CARRIER_ROLES), true) ? $roleScope : null;
    }

    private function load(Package $package): Package
    {
        return $package->load(['roles', 'features', 'permissions'])->loadCount('admins');
    }
}
