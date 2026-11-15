<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminInvite;
use App\Models\Package;
use App\Services\SeatAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * People -> "Invite by Google". An org admin invites someone by email and
 * picks their seats (roles + location/hotel scope) up front. The invitee signs
 * in with any Google account whose email matches; the account is created then,
 * with no password (see Admin\Auth\GoogleInviteController).
 *
 * The seat rules are exactly People/Team's (App\Services\SeatAssignmentService):
 * same assignable seats, same scope requirements, same limits.
 */
class InviteController extends Controller
{
    public function __construct(private readonly SeatAssignmentService $seats) {}

    public function index(): JsonResponse
    {
        $invites = AdminInvite::with('inviter')->latest()->limit(200)->get();

        return response()->json(['invites' => $invites->map(fn (AdminInvite $i) => $this->present($i))]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user('admin');
        $data = $this->validated($request);

        if (Admin::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->exists()) {
            throw ValidationException::withMessages(['email' => 'This person already has an account. Edit them in People instead.']);
        }

        $this->seats->assertWithinCreateLimit($actor);
        $this->seats->resolve($data['packages'], $actor); // validates seats, scope and limits; throws on a bad entry

        // One live invite per email: a new one replaces any pending one.
        AdminInvite::pending()->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])
            ->update(['revoked_at' => now()]);

        [$plain, $hash] = AdminInvite::newToken();

        $invite = AdminInvite::create([
            'email' => strtolower($data['email']),
            'name' => $data['name'] ?? null,
            'token_hash' => $hash,
            'packages' => $this->normalise($data['packages']),
            'invited_by_admin_id' => $actor->id,
            'expires_at' => now()->addDays(AdminInvite::TTL_DAYS),
        ]);

        return response()->json($this->withLink($invite, $plain, $actor), 201);
    }

    /** New link + new expiry; the old link stops working. */
    public function resend(Request $request, int $id): JsonResponse
    {
        $invite = $this->findOpen($id);
        [$plain, $hash] = AdminInvite::newToken();

        $invite->update(['token_hash' => $hash, 'expires_at' => now()->addDays(AdminInvite::TTL_DAYS), 'revoked_at' => null]);

        return response()->json($this->withLink($invite, $plain, $request->user('admin')));
    }

    public function destroy(int $id): JsonResponse
    {
        $invite = $this->findOpen($id);
        $invite->update(['revoked_at' => now()]);

        return response()->json(['invite' => $this->present($invite->fresh('inviter'))]);
    }

    private function findOpen(int $id): AdminInvite
    {
        $invite = AdminInvite::find($id);

        if (! $invite) {
            throw new HttpException(404, 'Invite not found.');
        }

        if ($invite->accepted_at !== null) {
            throw new HttpException(400, 'This invite was already accepted.');
        }

        return $invite;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'packages' => ['required', 'array', 'min:1'],
            'packages.*.packageId' => ['required', 'integer'],
            'packages.*.locationIds' => ['array'],
            'packages.*.locationIds.*' => ['integer'],
            'packages.*.hotelIds' => ['array'],
            'packages.*.hotelIds.*' => ['integer'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{packageId: int, locationIds: list<int>, hotelIds: list<int>}>
     */
    private function normalise(array $entries): array
    {
        return array_map(fn (array $e) => [
            'packageId' => (int) $e['packageId'],
            'locationIds' => array_values(array_unique(array_map('intval', $e['locationIds'] ?? []))),
            'hotelIds' => array_values(array_unique(array_map('intval', $e['hotelIds'] ?? []))),
        ], $entries);
    }

    /**
     * Create/resend response: the invite plus the one-time link (the token is
     * not stored, so this is the only time it can be shown) and whether the
     * email went out. The link lets the admin copy it when mail is not set up.
     *
     * @return array<string, mixed>
     */
    private function withLink(AdminInvite $invite, string $plain, Admin $actor): array
    {
        $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:9400')), '/');
        $url = "{$frontend}/admin/accept-invite?token={$plain}";

        $emailSent = true;
        try {
            $name = e($actor->name);
            $link = e($url);
            Mail::send([], [], function ($message) use ($invite, $name, $link) {
                $message->to($invite->email)
                    ->subject("You're invited to the Greyon admin panel")
                    ->html("<p>{$name} invited you to the Greyon admin panel.</p>"
                        .'<p>Open this link and sign in with Google using this email address:</p>'
                        ."<p><a href=\"{$link}\">{$link}</a></p>"
                        .'<p>The link works once and expires in '.AdminInvite::TTL_DAYS.' days.</p>');
            });
        } catch (\Throwable $e) {
            report($e);
            $emailSent = false;
        }

        return [
            'invite' => $this->present($invite->loadMissing('inviter')),
            'inviteUrl' => $url,
            'emailSent' => $emailSent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AdminInvite $invite): array
    {
        $names = Package::whereIn('id', array_column($invite->packages, 'packageId'))->pluck('name', 'id');

        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'name' => $invite->name,
            'status' => $invite->status(),
            'expiresAt' => $invite->expires_at?->toIso8601String(),
            'acceptedAt' => $invite->accepted_at?->toIso8601String(),
            'invitedBy' => $invite->inviter?->name,
            'packages' => collect($invite->packages)->map(fn ($p) => [
                'packageId' => $p['packageId'],
                'name' => $names[$p['packageId']] ?? null,
                'locationIds' => $p['locationIds'] ?? [],
                'hotelIds' => $p['hotelIds'] ?? [],
            ])->values(),
        ];
    }
}
