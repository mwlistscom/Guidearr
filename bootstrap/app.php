<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'admin.password' => \App\Http\Middleware\EnsureAdminPasswordChanged::class,
            'activity.touch' => \App\Http\Middleware\TouchUserActivity::class,
        ]);

        // Rate-limit the Fortify auth endpoints that ship without a limiter (registration,
        // reset-link requests, reset submissions). Fortify covers only login/2FA/passkeys
        // and exposes no hook for the rest, so this rides the group and matches by route name.
        $middleware->appendToGroup('web', \App\Http\Middleware\ThrottleAuthEndpoints::class);

        // Facebook posts its data-deletion signed_request server-to-server (no session/CSRF token);
        // the request is authenticated by the signed_request HMAC instead.
        $middleware->validateCsrfTokens(except: ['data-deletion/facebook']);

        // Honor X-Forwarded-* from a TLS-terminating reverse proxy (HAProxy/Traefik/Caddy/nginx).
        // When the headers are absent (direct access) the real connection is used, so the app
        // works the same way whether it's reached directly on :7979 or via a proxy — no switch.
        // Default trusts all proxies (the app is only reachable through the proxy/internal net);
        // set TRUSTED_PROXIES to a CSV of IPs/CIDRs to tighten.
        $proxies = trim((string) env('TRUSTED_PROXIES', '*'));
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
