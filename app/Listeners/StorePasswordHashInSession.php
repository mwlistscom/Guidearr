<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;

class StorePasswordHashInSession
{
    /**
     * Record the password a session signed in with, at the moment it signs in.
     *
     * AuthenticateSession only writes this on the first request that is ALREADY authenticated,
     * so a session that signed in and made no further request until after a password reset
     * would adopt the NEW password's fingerprint on its next visit — and survive the reset.
     * Writing it here closes that window. Same key and format as the middleware.
     *
     * Registered by event discovery (app/Listeners) — do not also Event::listen() it.
     */
    public function handle(Login $event): void
    {
        $guard = auth()->guard($event->guard);
        $password = $event->user->getAuthPassword();

        if (! $guard instanceof SessionGuard || ! $password || $event->guard !== auth()->getDefaultDriver()) {
            return;
        }

        $guard->getSession()->put('password_hash_'.$event->guard, $guard->hashPasswordForCookie($password));
    }
}
