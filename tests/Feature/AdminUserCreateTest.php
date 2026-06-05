<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserCreateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_admin_can_manually_create_a_verified_active_user(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Manual Person', 'email' => 'manual@example.com', 'role' => 'user',
                'password' => 'Sup3r-Secret-Pw!', 'password_confirmation' => 'Sup3r-Secret-Pw!',
            ])
            ->assertRedirect(route('admin.users'));

        $u = User::where('email', 'manual@example.com')->first();
        $this->assertNotNull($u);
        $this->assertNotNull($u->email_verified_at); // no mail server needed
        $this->assertSame('active', $u->status);
        $this->assertFalse((bool) $u->is_admin);
    }

    public function test_can_create_an_admin_role_user(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Boss', 'email' => 'boss@example.com', 'role' => 'admin',
                'password' => 'Another-Strong-1!', 'password_confirmation' => 'Another-Strong-1!',
            ])->assertRedirect(route('admin.users'));

        $this->assertTrue((bool) User::where('email', 'boss@example.com')->first()->is_admin);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Dup', 'email' => 'taken@example.com', 'role' => 'user',
                'password' => 'Strong-Pass-9!', 'password_confirmation' => 'Strong-Pass-9!',
            ])->assertSessionHasErrors('email');
    }

    public function test_password_confirmation_required(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'NoConfirm', 'email' => 'nc@example.com', 'role' => 'user',
                'password' => 'Strong-Pass-9!', 'password_confirmation' => 'different',
            ])->assertSessionHasErrors('password');
    }

    public function test_non_admin_cannot_reach_create(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['is_admin' => false, 'status' => 'active'])->save();

        $this->actingAs($user)->get(route('admin.users.create'))->assertRedirect(route('admin.login'));
    }
}
