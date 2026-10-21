<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The admin login must not be a way around two-factor.
 *
 * It used to check only the password and then log in on the same `web` guard as /login, so an
 * admin who had enabled 2FA was protected at /login but not at /{ADMIN_PATH}/login — a phished
 * or reused password alone gave a full admin session.
 */
class AdminTwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    }

    private function admin(bool $twoFactor): User
    {
        $factory = User::factory();
        $u = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    private function adminLogin(User $u)
    {
        return $this->post(route('admin.login.store'), ['email' => $u->email, 'password' => 'password']);
    }

    public function test_an_admin_with_two_factor_is_sent_to_the_challenge_not_logged_in(): void
    {
        $admin = $this->admin(twoFactor: true);

        $this->adminLogin($admin)->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($admin->id, session('login.id'));
    }

    public function test_passing_the_challenge_lands_on_the_admin_dashboard(): void
    {
        $admin = $this->admin(twoFactor: true);

        $this->adminLogin($admin);

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_code_does_not_log_the_admin_in(): void
    {
        $admin = $this->admin(twoFactor: true);

        $this->adminLogin($admin);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-real-code']);

        $this->assertGuest();
    }

    public function test_an_admin_without_two_factor_still_logs_straight_in(): void
    {
        $admin = $this->admin(twoFactor: false);

        $this->adminLogin($admin)->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }
}
