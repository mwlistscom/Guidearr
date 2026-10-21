<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * A password change or reset must end the account's other sessions and remember-me cookies —
 * otherwise changing a leaked password leaves whoever had it signed in.
 */
class PasswordChangeEndsSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user, bool $remember = false)
    {
        return $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => $remember ? 'on' : null,
        ]);
    }

    /** Make the next request arrive fresh, as from another browser: no cached user in the guard. */
    private function forgetGuard(): void
    {
        Auth::forgetGuards();
    }

    public function test_a_password_change_signs_out_an_existing_session(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);

        $this->forgetGuard();
        $this->get(route('dashboard'))->assertOk();   // the session works…

        $user->forceFill(['password' => 'a-brand-new-password'])->save();   // …changed elsewhere

        $this->forgetGuard();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_remember_me_cookie_stops_working_after_a_password_change(): void
    {
        $user = User::factory()->create();
        $recaller = Auth::guard('web')->getRecallerName();
        $cookie = $this->signIn($user, remember: true)->getCookie($recaller)->getValue();

        // Control: in a fresh session the cookie alone signs the user back in.
        $this->flushSession();
        $this->forgetGuard();
        $this->withCookie($recaller, $cookie)->get(route('dashboard'))->assertOk();

        $user->forceFill(['password' => 'a-brand-new-password'])->save();

        $this->flushSession();
        $this->forgetGuard();
        $this->withCookie($recaller, $cookie)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_password_reset_signs_out_an_existing_session(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        // Deliberately NO request between signing in and the reset: the session must already
        // carry the old password's fingerprint from the login itself.

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->forgetGuard();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_session_that_changes_the_password_stays_signed_in(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->signIn($admin);

        $this->forgetGuard();
        $this->put(route('admin.password.update'), [
            'name' => $admin->name,
            'email' => $admin->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->forgetGuard();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_a_social_only_account_is_unaffected(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->get(route('dashboard'))->assertOk();
    }
}
