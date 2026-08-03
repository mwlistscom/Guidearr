<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Social sign-up is the one registration path with no CAPTCHA — a redirect back from
 * the provider has no form for a human to solve a challenge in. A per-address cap on
 * NEW account creation is what stops bulk auto-provisioning there.
 *
 * The same callback also signs EXISTING users in, so the cap must be charged on the
 * create branch only. These tests hold that line.
 */
class OAuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // limiter state would otherwise leak between tests
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    }

    /**
     * Queue the identities the provider will return, in order.
     *
     * Registered once per test: re-registering the facade expectation does not replace
     * the earlier one, so every callback would otherwise see the first identity.
     *
     * @param  list<array{0: string, 1: string}>  $identities  [providerId, email] pairs
     */
    private function queueGoogleIdentities(array $identities): void
    {
        $users = [];

        foreach ($identities as [$id, $email]) {
            $u = Mockery::mock(SocialiteUser::class);
            $u->shouldReceive('getId')->andReturn($id);
            $u->shouldReceive('getEmail')->andReturn($email);
            $u->shouldReceive('getName')->andReturn('Ada Lovelace');
            $u->shouldReceive('getAvatar')->andReturn(null);
            $users[] = $u;
        }

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn(...$users);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function cap(): int
    {
        return (int) config('guidearr.auth_limits.oauth_new_accounts_per_ip');
    }

    public function test_new_account_provisioning_is_capped_per_address(): void
    {
        $max = $this->cap();

        $identities = [];
        for ($i = 0; $i < $max; $i++) {
            $identities[] = ["G-{$i}", "new{$i}@example.com"];
        }
        $identities[] = ['G-over', 'over-the-cap@example.com'];

        $this->queueGoogleIdentities($identities);

        for ($i = 0; $i < $max; $i++) {
            $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
            $this->post('/logout');
        }

        $this->assertSame($max, User::count());

        // One more new identity from the same address is refused — and creates nothing.
        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertSame($max, User::count(), 'no account may be created past the cap');
        $this->assertGuest();
        $this->assertNull(User::where('email', 'over-the-cap@example.com')->first());
    }

    public function test_existing_users_can_still_sign_in_after_the_cap_is_reached(): void
    {
        $max = $this->cap();

        // An established user, already linked — signing in must never be charged.
        $user = User::factory()->create(['email' => 'regular@example.com', 'email_verified_at' => now()]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'G-regular']);

        $identities = [];
        for ($i = 0; $i < $max; $i++) {
            $identities[] = ["G-{$i}", "new{$i}@example.com"];
        }
        $identities[] = ['G-regular', 'regular@example.com'];

        $this->queueGoogleIdentities($identities);

        for ($i = 0; $i < $max; $i++) {
            $this->get('/auth/google/callback');
            $this->post('/logout');
        }

        // The established user still gets in. This is the case that would turn a
        // provisioning cap into an outage for everyone behind one office NAT.
        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_repeated_sign_ins_are_never_charged_against_provisioning(): void
    {
        $max = $this->cap();
        $repeats = $max + 5;

        $user = User::factory()->create(['email' => 'loop@example.com', 'email_verified_at' => now()]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'G-loop']);

        $identities = array_fill(0, $repeats, ['G-loop', 'loop@example.com']);
        $identities[] = ['G-fresh', 'fresh@example.com'];

        $this->queueGoogleIdentities($identities);

        // Sign in far more times than the provisioning cap would allow.
        for ($i = 0; $i < $repeats; $i++) {
            $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
            $this->post('/logout');
        }

        // The budget is untouched, so a genuine new sign-up still succeeds afterwards.
        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
        $this->assertNotNull(User::where('email', 'fresh@example.com')->first());
    }

    public function test_callback_is_throttled_against_hammering(): void
    {
        $max = (int) config('guidearr.auth_limits.oauth_callback_per_ip');

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        for ($i = 0; $i < $max; $i++) {
            $this->get('/auth/google/redirect')->assertStatus(302);
        }

        $this->get('/auth/google/redirect')->assertStatus(429);
    }
}
