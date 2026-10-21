<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private function enableGoogle(): void
    {
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    }

    private function fakeGoogleUser(string $id, ?string $email, string $name = 'Ada Lovelace', ?string $avatar = 'https://img/a.png'): void
    {
        $u = Mockery::mock(SocialiteUser::class);
        $u->shouldReceive('getId')->andReturn($id);
        $u->shouldReceive('getEmail')->andReturn($email);
        $u->shouldReceive('getName')->andReturn($name);
        $u->shouldReceive('getAvatar')->andReturn($avatar);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_disabled_provider_is_404(): void
    {
        $this->get('/auth/google/redirect')->assertNotFound();
    }

    public function test_enabled_provider_redirect_route_works(): void
    {
        $this->enableGoogle();
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_callback_creates_and_signs_in_a_verified_user(): void
    {
        $this->enableGoogle();
        $this->fakeGoogleUser('G-1', 'ada@example.com', 'Ada L');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);           // provider-verified
        $this->assertNull($user->password);                       // social-only
        $this->assertSame('active', $user->status);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'G-1']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_links_to_an_existing_email_account(): void
    {
        $this->enableGoogle();
        $existing = User::factory()->create(['email' => 'bob@example.com']);
        $this->fakeGoogleUser('G-2', 'bob@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::where('email', 'bob@example.com')->count());  // no duplicate
        $this->assertDatabaseHas('social_accounts', ['user_id' => $existing->id, 'provider' => 'google', 'provider_id' => 'G-2']);
        $this->assertAuthenticatedAs($existing->fresh());
    }

    public function test_callback_reuses_an_already_linked_account(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G-3', 'avatar' => null]);
        $this->fakeGoogleUser('G-3', $user->email);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertSame(1, SocialAccount::where('provider', 'google')->where('provider_id', 'G-3')->count());
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_missing_email_is_rejected(): void
    {
        $this->enableGoogle();
        $this->fakeGoogleUser('G-4', null);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_pending_user_is_not_signed_in_when_approval_required(): void
    {
        config(['guidearr.registration_requires_approval' => true]);
        $this->enableGoogle();
        $this->fakeGoogleUser('G-5', 'carol@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame('pending', User::where('email', 'carol@example.com')->first()->status);
    }

    public function test_two_factor_account_is_blocked_from_social_login(): void
    {
        $this->enableGoogle();
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();
        $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G-6', 'avatar' => null]);
        $this->fakeGoogleUser('G-6', $user->email);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_page_shows_buttons_only_when_configured(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
        $this->enableGoogle();
        $this->get('/login')->assertOk()->assertSee('Continue with Google');
    }

    public function test_facebook_data_deletion_unlinks_and_confirms(): void
    {
        $secret = 'fbsecret';
        config(['services.facebook.client_id' => 'fid', 'services.facebook.client_secret' => $secret]);
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'facebook', 'provider_id' => 'FB-9', 'avatar' => null]);

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = $b64((string) json_encode(['user_id' => 'FB-9']));
        $sig = $b64(hash_hmac('sha256', $payload, $secret, true));

        $this->postJson('/data-deletion/facebook', ['signed_request' => "{$sig}.{$payload}"])
            ->assertOk()
            ->assertJsonStructure(['url', 'confirmation_code']);

        $this->assertSame(0, SocialAccount::where('provider', 'facebook')->where('provider_id', 'FB-9')->count());
    }

    public function test_facebook_data_deletion_rejects_a_bad_signature(): void
    {
        config(['services.facebook.client_secret' => 'fbsecret']);
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = $b64((string) json_encode(['user_id' => 'FB-9']));

        $this->postJson('/data-deletion/facebook', ['signed_request' => "wrongsig.{$payload}"])
            ->assertStatus(400);
    }

    public function test_facebook_data_deletion_get_returns_a_friendly_explainer(): void
    {
        $this->get('/data-deletion/facebook')
            ->assertOk()
            ->assertSee('callback', false)
            ->assertSee('/data-deletion', false);
    }

    /** A real Socialite user (not a mock) so the raw payload — email_verified — is present. */
    private function fakeGoogleRawUser(string $id, string $email, bool $emailVerified): void
    {
        $u = (new \Laravel\Socialite\Two\User)
            ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified])
            ->map(['id' => $id, 'email' => $email, 'name' => 'Owner', 'avatar' => null]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /**
     * Pre-account-takeover: someone registers the victim's address with a password they know,
     * can't verify it, and waits. When the real owner later uses "Continue with Google", the
     * account must become the owner's alone — the squatter's password, 2FA, passkeys, linked
     * providers and sessions all go.
     */
    public function test_social_sign_in_to_an_unverified_account_drops_the_registrants_credentials(): void
    {
        $this->enableGoogle();
        config(['session.driver' => 'database']);   // the shipped default; sessions have an owner column
        $squatted = User::factory()->withTwoFactor()->create(['email' => 'owner@example.com', 'email_verified_at' => null]);
        $squatted->socialAccounts()->create(['provider' => 'facebook', 'provider_id' => 'FB-squatter']);
        $squatted->passkeys()->create(['name' => 'squatter key', 'credential_id' => 'cred-1', 'credential' => []]);
        DB::table('sessions')->insert([
            'id' => 'squatter-session', 'user_id' => $squatted->id, 'ip_address' => '203.0.113.9',
            'user_agent' => 'x', 'payload' => base64_encode('x'), 'last_activity' => time(),
        ]);
        $this->fakeGoogleUser('G-owner', 'owner@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $user = $squatted->fresh();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertSame(['google'], $user->socialAccounts()->pluck('provider')->all());
        $this->assertDatabaseMissing('sessions', ['id' => 'squatter-session']);
        $this->assertNotSame($squatted->remember_token, $user->remember_token);

        // The squatter's password no longer signs in.
        Auth::logout();
        $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_social_sign_in_to_a_verified_account_keeps_its_credentials(): void
    {
        $this->enableGoogle();
        $existing = User::factory()->create(['email' => 'kept@example.com']);
        $hash = $existing->password;
        $this->fakeGoogleUser('G-kept', 'kept@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_an_address_google_has_not_verified_cannot_sign_in(): void
    {
        $this->enableGoogle();
        $existing = User::factory()->create(['email' => 'victim@example.com']);
        $this->fakeGoogleRawUser('G-unverified', 'victim@example.com', emailVerified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, $existing->socialAccounts()->count());
    }

    public function test_an_address_google_has_verified_signs_in(): void
    {
        $this->enableGoogle();
        $this->fakeGoogleRawUser('G-verified', 'fine@example.com', emailVerified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }
}
