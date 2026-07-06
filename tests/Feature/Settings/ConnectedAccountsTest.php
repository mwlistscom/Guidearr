<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ConnectedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function socialUser(): User
    {
        // A social-only account: no password.
        return User::factory()->create(['password' => null]);
    }

    private function mockGoogle(string $id, string $email): void
    {
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
        $u = Mockery::mock(SocialiteUser::class);
        $u->shouldReceive('getId')->andReturn($id);
        $u->shouldReceive('getEmail')->andReturn($email);
        $u->shouldReceive('getName')->andReturn('Ada');
        $u->shouldReceive('getAvatar')->andReturn(null);
        $p = Mockery::mock(Provider::class);
        $p->shouldReceive('redirectUrl')->andReturnSelf();
        $p->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('google')->andReturn($p);
    }

    public function test_page_renders_with_linked_accounts(): void
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G1', 'avatar' => null]);

        $this->actingAs($user)->get(route('connected-accounts.edit'))
            ->assertOk()
            ->assertSee('Google');
    }

    public function test_user_with_password_can_unlink(): void
    {
        $user = User::factory()->create();
        $acct = $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G1', 'avatar' => null]);

        $this->actingAs($user);
        Livewire::test('pages::settings.connected-accounts')->call('unlink', $acct->id);

        $this->assertDatabaseMissing('social_accounts', ['id' => $acct->id]);
    }

    public function test_cannot_unlink_the_only_login_method(): void
    {
        $user = $this->socialUser();
        $acct = $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G1', 'avatar' => null]);

        $this->actingAs($user);
        Livewire::test('pages::settings.connected-accounts')->call('unlink', $acct->id);

        $this->assertDatabaseHas('social_accounts', ['id' => $acct->id]); // refused
    }

    public function test_social_only_user_can_unlink_when_a_second_provider_remains(): void
    {
        $user = $this->socialUser();
        $g = $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G1', 'avatar' => null]);
        $user->socialAccounts()->create(['provider' => 'facebook', 'provider_id' => 'F1', 'avatar' => null]);

        $this->actingAs($user);
        Livewire::test('pages::settings.connected-accounts')->call('unlink', $g->id);

        $this->assertDatabaseMissing('social_accounts', ['id' => $g->id]);
    }

    public function test_social_only_user_can_set_a_password(): void
    {
        $user = $this->socialUser();
        $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G1', 'avatar' => null]);

        $this->actingAs($user);
        Livewire::test('pages::settings.connected-accounts')
            ->set('password', 'sup3r-secret-pw')
            ->set('password_confirmation', 'sup3r-secret-pw')
            ->call('setPassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('sup3r-secret-pw', $user->fresh()->password));
    }

    public function test_authenticated_callback_links_provider_to_current_user(): void
    {
        $user = User::factory()->create();
        $this->mockGoogle('G-NEW', 'someone@example.com');

        $this->actingAs($user)->get('/auth/google/callback')
            ->assertRedirect(route('connected-accounts.edit'));

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'G-NEW',
        ]);
    }

    public function test_cannot_link_a_provider_already_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $owner->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'G-TAKEN', 'avatar' => null]);
        $me = User::factory()->create();
        $this->mockGoogle('G-TAKEN', 'me@example.com');

        $this->actingAs($me)->get('/auth/google/callback')
            ->assertRedirect(route('connected-accounts.edit'))
            ->assertSessionHasErrors('provider');

        $this->assertSame(0, $me->socialAccounts()->count());
    }
}
