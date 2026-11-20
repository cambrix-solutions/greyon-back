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
 * "Accountant"...) and chooses which features each one can use, and what it
 * can do in each (view, create, edit, delete).
 *
 * A custom role is a Package (`is_system = false`, `created_by_admin_id` set)
 * - the same "seat" the People page already assigns - so People, scope
 * checks and permission middleware need no changes. A role has no scope of
 * its own: it carries both base roles (`manager` and `hotel_admin`), so the
 * destinations and hotels picked when the person is added decide where they
 * work (at least one of the two is required, see SeatAssignmentService).
 *
 * Safety rules:
 *  - a role can only grant features the creating admin holds themselves
 *    (and never the developer-only `features` key), so roles cannot be used
 *    to escalate access;
 *  - per feature, a role can only grant the actions its creator holds on it
 *    (stored on `package_feature.actions`, enforced by the `permission`
 *    middleware). Sending plain `featureKeys` (older clients) grants every
 *    action the creator holds on those features;
 *  - fine-grained permissions follow the chosen features, limited to the
 *    permissions the creating admin holds (a withheld `locations_create`
 *    stays withheld);
 *  - system seats are listed but read-only here.
 */
class CustomRoleController extends Controller
{
    /** Base roles every custom role carries, so it can be scoped to destinations and/or hotels. */
    private const CARRIER_ROLES = ['manager', 'hotel_admin'];

    /** Never delegable from this page. */
    private const NEVER_GRANT = ['features'];

    public function __construct(private readonly AccessService $access) {}

    public function options(Request $request): JsonResponse
    {
        $own = $this->ownActions($request);

        $features = $this->grantableFeatures($request)
            ->map(fn (Feature $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'description' => $f->description,
                'actions' => $own[$f->key] ?? [],
            ])
            ->values();

        return response()->json([
            'actions' => AccessService::ACTIONS,
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
                            ->whereHas('roles', fn ($r) => $r->whereIn('name', self::CARRIER_ROLES))
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

        $this->syncParts($request, $package, $data['featureActions']);

        return response()->json(['role' => new PackageResource($this->load($package))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $package = $this->customOrFail($id);
        $data = $this->validated($request, $package);

        $package->update([
            'name' => $data['name'] ?? $package->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $package->description,
        ]);

        $this->syncParts(
            $request,
            $package,
            $data['featureActions'] ?? $this->currentActions($package),
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
            'featureKeys' => [$package ? 'sometimes' : 'required_without:features', 'array', 'min:1'],
            'featureKeys.*' => ['string', 'distinct'],
            'features' => ['sometimes', 'array', 'min:1'],
            'features.*.key' => ['required', 'string', 'distinct'],
            'features.*.actions' => ['required', 'array', 'min:1'],
            'features.*.actions.*' => ['string', Rule::in(AccessService::ACTIONS)],
        ]);

        $own = $this->ownActions($request);

        if (isset($data['features'])) {
            $data['featureActions'] = [];
            foreach ($data['features'] as $row) {
                $data['featureActions'][$row['key']] = AccessService::normaliseActions($row['actions']);
            }
            $data['featureKeys'] = array_keys($data['featureActions']);
        } elseif (isset($data['featureKeys'])) {
            $data['featureActions'] = [];
            foreach ($data['featureKeys'] as $key) {
                $data['featureActions'][$key] = $own[$key] ?? [];
            }
        }

        if (isset($data['featureKeys'])) {
            $allowed = $this->grantableFeatures($request)->pluck('key')->all();
            $denied = array_values(array_diff($data['featureKeys'], $allowed));

            if ($denied !== []) {
                throw ValidationException::withMessages([
                    'featureKeys' => 'You can only give a role features you have yourself. Not allowed: '.implode(', ', $denied).'.',
                ]);
            }

            foreach ($data['featureActions'] as $key => $actions) {
                $extra = array_values(array_diff($actions, $own[$key] ?? []));

                if ($extra !== []) {
                    throw ValidationException::withMessages([
                        'features' => "You can only give a role actions you have yourself. Not allowed on {$key}: ".implode(', ', $extra).'.',
                    ]);
                }
            }
        }

        return $data;
    }

    /**
     * Feature key => actions the logged-in admin holds.
     *
     * @return array<string, list<string>>
     */
    private function ownActions(Request $request): array
    {
        return $this->access->effectiveFeatureActions($request->user('admin'));
    }

    /**
     * Feature key => actions this role grants today.
     *
     * @return array<string, list<string>>
     */
    private function currentActions(Package $package): array
    {
        return $package->features()->get()
            ->mapWithKeys(fn (Feature $f) => [$f->key => AccessService::normaliseActions($f->pivot?->actions)])
            ->all();
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
     * @param  array<string, list<string>>  $featureActions  feature key => actions
     */
    private function syncParts(Request $request, Package $package, array $featureActions): void
    {
        $roleIds = Role::whereIn('name', self::CARRIER_ROLES)->pluck('id')->all();

        if (count($roleIds) !== count(self::CARRIER_ROLES)) {
            throw new HttpException(500, 'The "manager" and "hotel_admin" base roles are required. Run the seeders.');
        }

        $featureKeys = array_keys($featureActions);
        $ids = Feature::whereIn('key', $featureKeys)->pluck('id', 'key');

        $package->roles()->sync($roleIds);

        $package->features()->sync(
            collect($featureActions)
                ->filter(fn ($actions, $key) => isset($ids[$key]))
                ->mapWithKeys(fn ($actions, $key) => [$ids[$key] => ['actions' => json_encode(array_values($actions))]])
                ->all()
        );

        $ownPermissions = $this->access->effectivePermissionKeys($request->user('admin'));
        $package->permissions()->sync(
            Permission::query()
                ->whereHas('feature', fn ($q) => $q->whereIn('key', $featureKeys))
                ->whereIn('key', $ownPermissions)
                ->pluck('id')
        );
    }

    private function load(Package $package): Package
    {
        return $package->load(['roles', 'features', 'permissions'])->loadCount('admins');
    }
}
