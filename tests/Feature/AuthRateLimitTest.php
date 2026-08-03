<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The public auth endpoints must cost an attacker something.
 *
 * Fortify ships a limiter for login only; registration, reset-link requests and
 * reset submissions arrive with none. These tests drive the real HTTP stack, so
 * they also prove the middleware is actually attached to Fortify's routes.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Limiter state lives in the cache and would otherwise leak between tests.
        Cache::flush();
    }

    public function test_registration_is_capped_per_minute(): void
    {
        $limit = config('guidearr.auth_limits.register_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/register', [])->assertStatus(302); // validation failure, not a lockout
        }

        $this->post('/register', [])->assertStatus(429);
    }

    public function test_reset_link_requests_are_capped_per_minute(): void
    {
        $limit = config('guidearr.auth_limits.password_email_per_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/forgot-password', ['email' => 'nobody'.$i.'@example.com'])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(429);
    }

    public function test_reset_submissions_are_capped_per_minute(): void
    {
        $limit = config('guidearr.auth_limits.password_update_per_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/reset-password', [])->assertStatus(302);
        }

        $this->post('/reset-password', [])->assertStatus(429);
    }

    public function test_one_host_cannot_spray_unlimited_distinct_accounts(): void
    {
        // The per-account key gives every new address its own bucket, so without a
        // per-IP limit a single host could try one password against every account
        // it can name, for free. This is the limit that stops that.
        $limit = config('guidearr.auth_limits.login_per_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->post('/login', ['email' => "victim{$i}@example.com", 'password' => 'wrong-password']);
        }

        // The named `login` limiter runs as throttle middleware, so exhausting it is a
        // 429 — distinct from Fortify's per-account lockout, which is a validation error.
        $this->post('/login', ['email' => 'one-more@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_a_single_account_still_locks_out_before_the_per_ip_cap(): void
    {
        // Guessing one person's password must not require exhausting the (looser)
        // per-IP budget first.
        $perAccount = config('guidearr.auth_limits.login_per_account');

        for ($i = 0; $i < $perAccount; $i++) {
            $this->post('/login', ['email' => 'victim@example.com', 'password' => "guess{$i}"]);
        }

        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'guess-again'])
            ->assertStatus(429);
    }

    public function test_a_normal_person_is_not_locked_out(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => bcrypt('correct-horse-battery'),
        ]);

        // A few fat-fingered attempts, then the right one — must still get through.
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user->fresh());
    }
}
