<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AdminSync extends Command
{
    protected $signature = 'admin:sync {--reset : Re-apply the .env password to the existing admin (break-glass recovery)}';
    protected $description = 'Create or recover the bootstrap admin account from ADMIN_EMAIL / ADMIN_PASSWORD in .env';

    public function handle(): int
    {
        $email = config('guidearr.admin.email');
        $password = config('guidearr.admin.password');

        if (! $email || ! $password) {
            $this->error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env.');
            return self::FAILURE;
        }

        $admin   = User::where('email', $email)->first();
        $existed = (bool) $admin;

        if (! $admin) {
            // New bootstrap admin. forceFill bypasses mass-assignment guarding so
            // email_verified_at actually gets set (the model has no $fillable).
            $admin = new User();
            $admin->forceFill([
                'name'                 => 'Administrator',
                'email'                => $email,
                'password'             => $password, // 'hashed' cast hashes this once
                'must_change_password' => true,
            ]);
        } elseif ($this->option('reset')) {
            $admin->forceFill([
                'password'             => $password,
                'must_change_password' => true,
            ]);
        }

        // Every run guarantees the bootstrap admin is an active, email-verified
        // admin — so it never gets stopped by the 6-digit verification screen.
        $admin->forceFill([
            'is_admin' => true,
            'status'   => 'active',
        ]);
        if (is_null($admin->email_verified_at)) {
            $admin->forceFill(['email_verified_at' => now()]);
        }

        $admin->save();

        if (! $existed) {
            $this->info("Admin created for {$email} — verified and active. Password change required on first login.");
        } elseif ($this->option('reset')) {
            $this->info("Admin {$email} reset from .env — verified and active. Change the password on next login.");
        } else {
            $this->info("Admin {$email} ensured: email-verified, active, admin role. (Use --reset to re-apply the .env password.)");
        }

        return self::SUCCESS;
    }
}
