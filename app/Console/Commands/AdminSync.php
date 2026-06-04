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

        $admin = User::where('email', $email)->first();

        if (! $admin) {
            User::create([
                'name' => 'Administrator',
                'email' => $email,
                'password' => $password, // hashed by the model cast
                'email_verified_at' => now(),
            ])->forceFill([
                'is_admin' => true,
                'status' => 'active',
                'must_change_password' => true,
            ])->save();

            $this->info("Admin created for {$email}. You'll be required to change the password on first login.");
            return self::SUCCESS;
        }

        if ($this->option('reset')) {
            $admin->forceFill([
                'password' => bcrypt($password),
                'is_admin' => true,
                'status' => 'active',
                'must_change_password' => true,
            ])->save();
            $this->info("Admin {$email} reset from .env. Change the password on next login.");
            return self::SUCCESS;
        }

        $this->info("Admin {$email} already exists. Use --reset to recover the password from .env.");
        return self::SUCCESS;
    }
}
