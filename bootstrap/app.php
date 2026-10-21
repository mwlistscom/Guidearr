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

        // Only X-Forwarded-Proto is honoured, so a TLS-terminating proxy in front still reads as
        // https. The other three are deliberately NOT trusted:
        //
        //  - X-Forwarded-For: the bundled nginx has already resolved the client address (its
        //    real_ip block, against the trusted list in docker/real-ip.conf) and hands PHP the
        //    result as REMOTE_ADDR. "Trusting" the header again here trusted the VISITOR: with
        //    '*', Laravel treats REMOTE_ADDR as the proxy, so anyone could pick their own
        //    $request->ip() — defeating every IP rate limit and the playlist IP lock.
        //  - X-Forwarded-Host / -Port: links are built from APP_URL instead (AppServiceProvider),
        //    because a client-chosen host ended up in password-reset emails — a reset link that
        //    delivers the token to whoever sent the header.
        $proxies = trim((string) env('TRUSTED_PROXIES', '*'));
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
            headers: Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
