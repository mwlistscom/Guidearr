<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Interactive break-glass recovery for the admin account: prompts for an email and a new
 * password (hidden — nothing lands on the command line or in shell history), then creates the
 * admin if none exists (e.g. it was deleted) or resets the password of the matching account.
 * Replaces the old ADMIN_EMAIL / ADMIN_PASSWORD .env-based bootstrap.
 */
class AdminPassword extends Command
{
    protected $signature = 'admin:password';

    protected $description = 'Create or reset the admin account interactively (prompts for email + password)';

    public function handle(): int
    {
        // Offer the sole existing admin's address as the default, if there is exactly one.
        $admins = User::where('is_admin', true)->orderBy('id')->get();
        $default = $admins->count() === 1 ? $admins->first()->email : null;

        $email = trim((string) $this->ask('Admin email', $default));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password');
        $confirm = (string) $this->secret('Confirm password');
        if ($password !== $confirm) {
            $this->error('The passwords did not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()]]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $creating = ! $user;

        if ($creating) {
            $user = new User;
            $user->forceFill(['name' => 'Administrator', 'email' => $email]);
        }

        $user->forceFill([
            'password' => $password,            // the 'hashed' cast hashes it on save
            'is_admin' => true,
            'status' => 'active',
            'must_change_password' => false,    // the operator just chose it themselves
        ]);
        if (is_null($user->email_verified_at)) {
            $user->forceFill(['email_verified_at' => now()]);
        }
        $user->save();

        $this->info($creating
            ? "Admin created for {$email} — active and verified. You can sign in now."
            : "Password reset for {$email} — the account is now an active admin.");

        return self::SUCCESS;
    }
}
