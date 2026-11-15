<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `admin.active`. Cuts off an admin who was deactivated while they
 * were already signed in: login blocks inactive accounts, but an existing
 * session would otherwise keep working until it expired. Runs on every
 * admin-panel request, so the effect is immediate and works with any
 * session driver. Developers are not affected.
 */
class EnsureAdminActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin && ! $admin->isActive()) {
            Auth::guard('admin')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'This account is not active. Please contact the hotel.',
            ], 401);
        }

        return $next($request);
    }
}
