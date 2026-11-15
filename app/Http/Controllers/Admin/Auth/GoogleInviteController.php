<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use App\Models\AdminInvite;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Admin sign-in with Google, and the invite acceptance that rides on it.
 *
 * Same ticket handoff as the guest flow (Auth\GoogleController):
 * /admin/auth/google/redirect -> Google -> /admin/auth/google/callback
 * -> SPA with a one-time ticket -> POST /admin/auth/google/exchange sets the
 * admin session cookie.
 *
 *  - With an invite token the Google email must match the invite email; the
 *    account is created (random unusable password) and the invited seats applied.
 *  - Without one, only an existing admin (matched by google_id or email) may sign in.
 *
 * The OAuth client needs the admin callback registered as a second redirect
 * URI (GOOGLE_ADMIN_REDIRECT_URI).
 */
class GoogleInviteController extends Controller
{
    private const SESSION_KEY = 'admin_google_invite';

    /** Public: lets the accept page show who invited you and which email to use. */
    public function preview(string $token): JsonResponse
    {
        $invite = AdminInvite::findByToken($token);

        if (! $invite || ! $invite->isPending()) {
            return response()->json(['valid' => false, 'message' => 'This invite is invalid or has expired.'], 404);
        }

        $names = Package::whereIn('id', array_column($invite->packages, 'packageId'))->pluck('name');

        return response()->json([
            'valid' => true,
            'email' => $invite->email,
            'invitedBy' => $invite->inviter?->name,
            'roles' => $names->values(),
            'expiresAt' => $invite->expires_at->toIso8601String(),
        ]);
    }

    public function redirect(Request $request): mixed
    {
        $token = (string) $request->query('invite', '');

        if ($token !== '') {
            $invite = AdminInvite::findByToken($token);

            if (! $invite || ! $invite->isPending()) {
                return redirect($this->frontend().'/admin/sign-in?error=invite_invalid');
            }

            $request->session()->put(self::SESSION_KEY, $token);
        } else {
            $request->session()->forget(self::SESSION_KEY);
        }

        $this->useAdminRedirect();

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $signIn = $this->frontend().'/admin/sign-in';

        if ($request->missing('code')) {
            return redirect("{$signIn}?error=google_code");
        }

        $this->useAdminRedirect();

        try {
            $google = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect("{$signIn}?error=google_failed");
        }

        $email = strtolower(trim((string) $google->getEmail()));
        $verified = ($google->user['email_verified'] ?? true) !== false;

        if ($email === '' || ! $verified) {
            return redirect("{$signIn}?error=google_unverified");
        }

        $token = $request->session()->pull(self::SESSION_KEY);
        $admin = $token ? $this->acceptInvite((string) $token, $google, $email) : $this->existingAdmin($google, $email);

        if (is_string($admin)) {
            return redirect("{$signIn}?error={$admin}");
        }

        if (! $admin->isActive()) {
            return redirect("{$signIn}?error=inactive");
        }

        $ticket = Str::random(64);
        Cache::put($this->ticketKey($ticket), $admin->id, now()->addMinutes(2));

        return redirect($this->frontend()."/admin/auth/google/complete?ticket={$ticket}");
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'min:20', 'max:128']]);

        $adminId = Cache::pull($this->ticketKey($data['ticket']));

        if (! $adminId) {
            return response()->json(['statusCode' => 419, 'message' => 'Google sign-in expired. Please try again.', 'error' => 'Page Expired'], 419);
        }

        $admin = Admin::find($adminId);

        if (! $admin || ! $admin->isActive()) {
            return response()->json(['statusCode' => 403, 'message' => 'This account is not active.', 'error' => 'Forbidden'], 403);
        }

        Auth::guard('admin')->login($admin, remember: true);
        $request->session()->regenerate();

        return response()->json(['admin' => new AdminResource($admin->load(['packages.roles', 'packages.features', 'packages.permissions']))]);
    }

    /** @return Admin|string  the admin, or an error code for the sign-in page */
    private function acceptInvite(string $token, $google, string $email): Admin|string
    {
        $invite = AdminInvite::findByToken($token);

        if (! $invite || ! $invite->isPending()) {
            return 'invite_invalid';
        }

        if ($invite->email !== $email) {
            return 'invite_email_mismatch';
        }

        $admin = Admin::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $admin) {
            $admin = Admin::create([
                'name' => $invite->name ?: ($google->getName() ?: $email),
                'email' => $email,
                'google_id' => $google->getId(),
                'password' => Hash::make(Str::password(32)), // never used: Google-only
                'status' => 'active',
                'created_by_admin_id' => $invite->invited_by_admin_id,
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();
        } elseif (! $admin->google_id) {
            $admin->forceFill(['google_id' => $google->getId()])->save();
        }

        $sync = [];
        $existing = Package::whereIn('id', array_column($invite->packages, 'packageId'))->pluck('id')->all();
        foreach ($invite->packages as $entry) {
            if (in_array($entry['packageId'], $existing, true)) {
                $sync[$entry['packageId']] = [
                    'location_ids' => ($entry['locationIds'] ?? []) ?: null,
                    'hotel_ids' => ($entry['hotelIds'] ?? []) ?: null,
                ];
            }
        }
        $admin->packages()->syncWithoutDetaching($sync);

        $invite->update(['accepted_at' => now(), 'admin_id' => $admin->id]);

        return $admin;
    }

    /** @return Admin|string */
    private function existingAdmin($google, string $email): Admin|string
    {
        $admin = Admin::where('google_id', $google->getId())->first()
            ?? Admin::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $admin) {
            return 'no_invite';
        }

        if (! $admin->google_id) {
            $admin->forceFill(['google_id' => $google->getId()])->save();
        }

        return $admin;
    }

    private function useAdminRedirect(): void
    {
        if ($uri = config('services.google.admin_redirect')) {
            config(['services.google.redirect' => $uri]);
        }
    }

    private function frontend(): string
    {
        return rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:9400')), '/');
    }

    private function ticketKey(string $ticket): string
    {
        return "admin_google_login_ticket:{$ticket}";
    }
}
