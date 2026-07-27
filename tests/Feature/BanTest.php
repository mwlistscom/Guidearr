<?php

namespace Tests\Feature;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_is_banned_is_case_and_whitespace_insensitive(): void
    {
        Ban::ban('Banned@Example.com');

        $this->assertTrue(Ban::isBanned('banned@example.com'));
        $this->assertTrue(Ban::isBanned('  BANNED@example.com '));
        $this->assertFalse(Ban::isBanned('other@example.com'));
    }

    public function test_banned_email_cannot_register(): void
    {
        Ban::ban('blocked@example.com', 'spam');

        $this->post(route('register.store'), [
            'name' => 'Blocked', 'email' => 'blocked@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_banned_email_blocks_admin_manual_create(): void
    {
        Ban::ban('blocked@example.com');

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'X', 'email' => 'blocked@example.com', 'role' => 'user',
                'password' => 'Sup3r-Secret-Pw!', 'password_confirmation' => 'Sup3r-Secret-Pw!',
            ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_banned_active_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'u@example.com', 'password' => Hash::make('secret-password'),
            'status' => 'active', 'email_verified_at' => now(),
        ]);
        Ban::ban($user->email);

        $this->post(route('login.store'), ['email' => 'u@example.com', 'password' => 'secret-password'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_toggle_syncs_the_ban_list_both_ways(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'member@example.com', 'status' => 'active']);

        // Ban via the toggle → status flips and the email is added to the ban list.
        $this->actingAs($admin)->patch(route('admin.users.toggle', $user));
        $this->assertSame('banned', $user->fresh()->status);
        $this->assertTrue(Ban::isBanned('member@example.com'));

        // Unban via the toggle → status flips back and the email leaves the ban list.
        $this->actingAs($admin)->patch(route('admin.users.toggle', $user));
        $this->assertSame('active', $user->fresh()->status);
        $this->assertFalse(Ban::isBanned('member@example.com'));
    }

    public function test_delete_with_ban_flag_persists_ban_after_account_is_gone(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'gone@example.com']);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $user), ['ban' => 1])
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertTrue(Ban::isBanned('gone@example.com'), 'ban survives the deleted account');
    }

    public function test_delete_without_ban_flag_does_not_ban(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'gone2@example.com']);

        $this->actingAs($admin)->delete(route('admin.users.destroy', $user));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertFalse(Ban::isBanned('gone2@example.com'));
    }

    public function test_ban_list_store_and_destroy_sync_matching_account(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['email' => 'sync@example.com', 'status' => 'active']);

        // Adding to the ban list disables a matching active account.
        $this->actingAs($admin)->post(route('admin.bans.store'), ['email' => 'sync@example.com', 'reason' => 'abuse'])
            ->assertRedirect(route('admin.bans'));
        $this->assertDatabaseHas('bans', ['email' => 'sync@example.com', 'reason' => 'abuse']);
        $this->assertSame('banned', $member->fresh()->status);

        // Removing the ban re-activates the matching banned account.
        $ban = Ban::where('email', 'sync@example.com')->first();
        $this->actingAs($admin)->delete(route('admin.bans.destroy', $ban))
            ->assertRedirect(route('admin.bans'));
        $this->assertFalse(Ban::isBanned('sync@example.com'));
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_cannot_ban_yourself_via_ban_list(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.bans.store'), ['email' => $admin->email])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Ban::isBanned($admin->email));
    }

    public function test_ban_list_page_renders(): void
    {
        Ban::ban('listed@example.com', 'testing');

        $this->actingAs($this->admin())->get(route('admin.bans'))
            ->assertOk()->assertSee('Ban list')->assertSee('listed@example.com');
    }
}
