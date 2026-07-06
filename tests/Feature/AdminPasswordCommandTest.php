<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_admin_when_none_exists(): void
    {
        $this->artisan('admin:password')
            ->expectsQuestion('Admin email', 'boss@example.com')
            ->expectsQuestion('New password', 'Abcdefgh1234')
            ->expectsQuestion('Confirm password', 'Abcdefgh1234')
            ->assertSuccessful();

        $u = User::where('email', 'boss@example.com')->first();
        $this->assertNotNull($u);
        $this->assertTrue((bool) $u->is_admin);
        $this->assertSame('active', $u->status);
        $this->assertNotNull($u->email_verified_at);
        $this->assertTrue(Hash::check('Abcdefgh1234', $u->password));
    }

    public function test_resets_and_reactivates_an_existing_admin(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->forceFill(['is_admin' => true, 'status' => 'banned'])->save();

        $this->artisan('admin:password')
            ->expectsQuestion('Admin email', 'admin@example.com')
            ->expectsQuestion('New password', 'Abcdefgh1234')
            ->expectsQuestion('Confirm password', 'Abcdefgh1234')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertSame('active', $admin->status);          // re-activated
        $this->assertTrue(Hash::check('Abcdefgh1234', $admin->password));
        $this->assertSame(1, User::where('email', 'admin@example.com')->count()); // no duplicate
    }

    public function test_mismatched_passwords_fail(): void
    {
        $this->artisan('admin:password')
            ->expectsQuestion('Admin email', 'x@example.com')
            ->expectsQuestion('New password', 'Abcdefgh1234')
            ->expectsQuestion('Confirm password', 'Different1234')
            ->assertFailed();

        $this->assertSame(0, User::where('email', 'x@example.com')->count());
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->artisan('admin:password')
            ->expectsQuestion('Admin email', 'x@example.com')
            ->expectsQuestion('New password', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertFailed();

        $this->assertSame(0, User::where('email', 'x@example.com')->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->artisan('admin:password')
            ->expectsQuestion('Admin email', 'not-an-email')
            ->assertFailed();
    }
}
