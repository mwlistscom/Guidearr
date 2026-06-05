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
        ]);

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
