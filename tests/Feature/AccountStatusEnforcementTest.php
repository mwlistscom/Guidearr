<?php

namespace Tests\Feature;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

/**
 * Account status is enforced on every request, not only at password login.
 *
 * It used to be checked in one place — Fortify's password check — so a banned or disabled
 * user kept an existing session, came back through a remember-me cookie, or signed in with a
 * passkey; and with approval required, a pending account could reach the dashboard that way.
 */
class AccountStatusEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $status = 'active'): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['status' => $status])->save();

        return $u;
    }

    public function test_an_active_user_is_unaffected(): void
    {
        $u = $this->user();

        $this->actingAs($u)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($u);
    }

    public function test_a_banned_users_existing_session_is_ended(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get(route('dashboard'))->assertOk();

        $u->forceFill(['status' => 'banned'])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_email_ban_ends_the_session_even_with_an_active_status(): void
    {
        $u = $this->user();
        Ban::ban($u->email);

        $this->actingAs($u)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_logging_out_cycles_the_remember_token(): void
    {
        // What makes a remember-me cookie stop working once the account is banned.
        $u = $this->user('banned');
        $u->forceFill(['remember_token' => 'old-token'])->save();

        $this->actingAs($u)->get(route('dashboard'));

        $this->assertNotSame('old-token', $u->fresh()->remember_token);
    }

    public function test_a_json_request_from_a_banned_user_gets_403(): void
    {
        $this->actingAs($this->user('banned'))
            ->getJson(route('dashboard'))
            ->assertForbidden();
        $this->assertGuest();
    }

    public function test_a_pending_user_cannot_reach_the_dashboard(): void
    {
        $this->actingAs($this->user('pending'))
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('Your account is awaiting approval.'));
        $this->assertGuest();
    }

    public function test_a_pending_user_can_still_enter_their_email_code(): void
    {
        // Registration signs the new account in for exactly this step; it must keep working.
        $u = User::factory()->unverified()->create();
        $u->forceFill(['status' => 'pending'])->save();

        $this->actingAs($u)
            ->postJson(route('verification.code'), ['code' => '000000'])
            ->assertStatus(422);
        $this->assertAuthenticatedAs($u);
    }

    public function test_passkey_login_applies_the_same_rule(): void
    {
        $request = Request::create('/');

        foreach (['active' => true, 'banned' => false, 'pending' => false] as $status => $allowed) {
            $passkey = (new Passkey)->setRelation('user', $this->user($status));
            $this->assertSame($allowed, Passkeys::allowsLogin($request, $passkey), "status {$status}");
        }

        $emailBanned = $this->user();
        Ban::ban($emailBanned->email);
        $this->assertFalse(Passkeys::allowsLogin($request, (new Passkey)->setRelation('user', $emailBanned)));
    }
}
