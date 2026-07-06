<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificationCodeFlowTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedUserWithCode(string $code = '123456', int $expiresInMinutes = 15): User
    {
        $u = User::factory()->create(['email_verified_at' => null]);
        $u->forceFill([
            'status' => 'active',
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes($expiresInMinutes),
        ])->save();

        return $u;
    }

    public function test_register_page_renders_verification_modal(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('verifyModal', false)
            ->assertSee('Check your email', false)
            ->assertSee('spam', false);
    }

    public function test_registration_returns_json_and_emails_a_code(): void
    {
        Notification::fake();

        $response = $this->postJson(route('register.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password-123',
            'password_confirmation' => 'secret-password-123',
        ]);

        $response->assertCreated();

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailCode::class);
    }

    public function test_correct_code_verifies_and_points_to_login(): void
    {
        $user = $this->unverifiedUserWithCode('654321');

        $this->actingAs($user)
            ->postJson(route('verification.code'), ['code' => '654321'])
            ->assertOk()
            ->assertJson(['ok' => true, 'verified' => true, 'redirect' => route('login')]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_wrong_code_returns_422_json(): void
    {
        $user = $this->unverifiedUserWithCode('654321');

        $this->actingAs($user)
            ->postJson(route('verification.code'), ['code' => '000000'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->unverifiedUserWithCode('654321', -1); // already expired

        $this->actingAs($user)
            ->postJson(route('verification.code'), ['code' => '654321'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        // Code issued just now (expires in the full 15m) → still inside the 5m cooldown.
        $user = $this->unverifiedUserWithCode('654321', 15);

        $this->actingAs($user)
            ->postJson(route('verification.resend'))
            ->assertStatus(429)
            ->assertJson(['ok' => false])
            ->assertJsonStructure(['retry_after']);
    }

    public function test_resend_sends_new_code_after_cooldown(): void
    {
        Notification::fake();

        // expires in 9m → issued 6m ago → past the 5m cooldown.
        $user = $this->unverifiedUserWithCode('654321', 9);

        $this->actingAs($user)
            ->postJson(route('verification.resend'))
            ->assertOk()
            ->assertJson(['ok' => true]);

        Notification::assertSentTo($user, VerifyEmailCode::class);
        // A fresh 6-digit code replaced the old one.
        $this->assertNotSame('654321', $user->fresh()->verification_code);
    }

    public function test_resend_requires_authentication(): void
    {
        $this->post(route('verification.resend'))->assertRedirect(route('login'));
    }

    public function test_verify_page_renders_with_spam_hint(): void
    {
        $user = $this->unverifiedUserWithCode();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('spam', false);
    }
}
