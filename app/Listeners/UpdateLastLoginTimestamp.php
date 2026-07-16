<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class UpdateLastLoginTimestamp
{
    /**
     * Stamp the user's last_login_at on every successful login. saveQuietly so the write
     * doesn't fire model events (and doesn't bump updated_at into looking like a profile edit).
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        }
    }
}
