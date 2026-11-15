<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Http\Resources\PackageResource;
use App\Models\Admin;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Package;
use App\Services\AccessService;
use App\Services\SeatAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Org-admin people management (feature: users).
 * Assigns seats (packages) that grant manager / hotel_admin — not other
 * org-admin seats. Scope: many locations per manager; hotels capped by
 * package limits (hotels_per_location).
 *
 * store() quantity cap (2026-09-23): same pattern as
 * App\Http\Controllers\Admin\LocationController's `locations`
 * create-limit — an admin's packages can cap *how many people total*
 * they're allowed to add via People/Team, via
 * App\Services\AccessService::effectiveLimit() against the `managers`
 * resource key (feature `users`), counted from
 * `admins.created_by_admin_id` (set on every account this method
 * creates). Counts every person added here regardless of seat
 * (manager or hotel_admin) — it's a headcount cap on this admin's
 * People list, not a per-role cap. `effectiveLimit()` returning `null`
 * means unlimited, same as the locations cap.
 */
class TeamController extends Controller
{
    private const PACKAGE_EAGER_LOAD = ['packages.roles', 'packages.features', 'packages.permissions', 'packages.limits'];

    public function __construct(
        private readonly AccessService $access,
        private readonly SeatAssignmentService $assignments,
    ) {}

    public function seats(): JsonResponse
    {
        $packages = Package::query()
            ->with(['roles', 'features', 'permissions', 'limits'])
            ->whereHas('roles', fn ($q) => $q->whereIn('name', SeatAssignmentService::ASSIGNABLE_ROLES))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'))
            ->orderBy('name')
            ->get();

        return response()->json(['seats' => PackageResource::collection($packages)]);
    }

    public function index(): JsonResponse
    {
        $admins = Admin::with(self::PACKAGE_EAGER_LOAD)->orderBy('name')->get();

        return response()->json(['admins' => AdminResource::collection($admins)]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user('admin');
        $data = $this->validated($request, null, $actor);

        $this->assignments->assertWithinCreateLimit($actor);

        $hadExplicitPassword = filled($data['password'] ?? null);
        $data['password'] = Hash::make($data['password'] ?? Str::password(16));
        $packageSync = $data['package_sync'];
        unset($data['package_sync']);

        $admin = Admin::create([
            ...$data,
            'created_by_admin_id' => $actor->id,
        ]);
        $admin->packages()->sync($packageSync);

        if (! $hadExplicitPassword) {
            // No password was typed in for this person, so the random
            // one just hashed above is unknown to anyone and the
            // account would otherwise be unusable. Send a "set your
            // password" link through the same broker
            // Admin\Auth\PasswordResetLinkController already uses for
            // "forgot password" — this is the account's first, and
            // only, way to ever learn a working password.
            // (2026-09-23 fix — reported bug: no way to set a password
            // for a newly created Manager/Hotel-desk account.)
            Password::broker('admins')->sendResetLink(['email' => $admin->email]);
        }

        return response()->json(['admin' => new AdminResource($admin->load(self::PACKAGE_EAGER_LOAD))], 201);
    }

    public function update(Request $request, Admin $target): JsonResponse
    {
        $actor = $request->user('admin');
        $this->assertEditable($actor, $target);

        $data = $this->validated($request, $target, $actor);

        if (($data['status'] ?? null) === 'inactive' && (int) $actor->id === (int) $target->id) {
            throw new HttpException(400, 'You cannot deactivate your own account.');
        }

        if (array_key_exists('password', $data)) {
            $data['password'] = filled($data['password']) ? Hash::make($data['password']) : $target->password;
        }

        $packageSync = $data['package_sync'] ?? null;
        unset($data['package_sync']);

        $target->update($data);

        if ($packageSync !== null) {
            $target->packages()->sync($packageSync);
        }

        return response()->json(['admin' => new AdminResource($target->load(self::PACKAGE_EAGER_LOAD))]);
    }

    public function destroy(Request $request, Admin $target): JsonResponse
    {
        $actor = $request->user('admin');
        $this->assertEditable($actor, $target);

        if ((int) $actor->id === (int) $target->id) {
            throw new HttpException(400, 'You cannot remove your own account.');
        }

        $target->delete();

        return response()->json(['message' => 'Person removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Admin $target, Admin $actor): array
    {
        $data = $request->validate([
            'name' => [$target ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [
                $target ? 'sometimes' : 'required',
                'email',
                Rule::unique('admins', 'email')->ignore($target?->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'packages' => [$target ? 'sometimes' : 'required', 'array', 'min:1'],
            'packages.*.packageId' => ['required', 'integer'],
            'packages.*.locationIds' => ['array'],
            'packages.*.locationIds.*' => ['integer', Rule::exists(Location::class, 'id')],
            'packages.*.hotelIds' => ['array'],
            'packages.*.hotelIds.*' => ['integer', Rule::exists(Hotel::class, 'id')],
        ]);

        if (array_key_exists('packages', $data)) {
            $data['package_sync'] = $this->assignments->resolve($data['packages'], $actor);
            unset($data['packages']);
        }

        return $data;
    }

    private function assertEditable(Admin $actor, Admin $target): void
    {
        $roles = $this->access->effectiveRoles($target);
        if (in_array('admin', $roles, true) && (int) $actor->id !== (int) $target->id) {
            throw new HttpException(403, 'Org admins cannot edit other admin accounts.');
        }
    }
}
