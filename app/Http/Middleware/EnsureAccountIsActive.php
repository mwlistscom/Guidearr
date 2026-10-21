<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-check account status on every request, not only at password login.
 *
 * Status and bans used to be enforced in exactly one place — Fortify's password check — so a
 * banned or disabled user kept the session they already had, came back through a remember-me
 * cookie, or signed in with a passkey, and none of those paths ever looked again. With
 * REGISTRATION_REQUIRES_APPROVAL on, a pending account could also reach the dashboard that way.
 *
 * A pending account is still allowed the email-verification routes: registration signs the
 * new user in so they can enter the emailed code, and that step must keep working.
 */
class EnsureAccountIsActive
{
    /** Routes a signed-in but still-pending account may use. */
    private const PENDING_ALLOWED = ['verification.*', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->canSignIn()) {
            return $next($request);
        }

        if ($user->status === 'pending' && ! $user->isBanned() && $request->routeIs(...self::PENDING_ALLOWED)) {
            return $next($request);
        }

        // logout() also cycles the remember token, so a remember-me cookie stops working too.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $user->status === 'pending' && ! $user->isBanned()
            ? __('Your account is awaiting approval.')
            : __('This account is not active.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
