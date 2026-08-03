<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies named rate limiters to the Fortify auth endpoints that ship without one.
 *
 * Fortify wires limiters only for login, two-factor and passkeys (see its
 * routes/routes.php) — registration, reset-link requests and reset submissions get
 * none, and it offers no config hook to add them. Rather than re-declaring those
 * routes ourselves (which would depend on this app's route file being registered
 * before the package's), this rides the `web` group and matches on route name, so
 * it holds regardless of provider boot order.
 *
 * Requests to anything else pass straight through — the only cost is a name lookup.
 */
class ThrottleAuthEndpoints
{
    /** Fortify route name => named limiter declared in FortifyServiceProvider. */
    private const LIMITERS = [
        'register.store' => 'register',
        'password.email' => 'password-email',
        'password.update' => 'password-update',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::LIMITERS[$request->route()?->getName()] ?? null;

        if ($limiter === null) {
            return $next($request);
        }

        // Exactly three arguments: ThrottleRequests only takes the named-limiter path
        // when func_num_args() === 3.
        return $this->throttle->handle($request, $next, $limiter);
    }
}
