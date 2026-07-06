<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminEnvMailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_environment_page_shows_test_email_button(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.environment'))
            ->assertOk()
            ->assertSee('Send test email', false)
            ->assertSee('mailTestModal', false);
    }

    public function test_test_mail_sends_and_reports_success(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->postJson(route('admin.environment.test-mail'), [
                'to' => 'someone@example.com',
                'mail' => [
                    'MAIL_MAILER' => 'smtp',
                    'MAIL_HOST' => 'mail.example.com',
                    'MAIL_PORT' => '465',
                    'MAIL_FROM_ADDRESS' => 'noreply@example.com',
                ],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_test_mail_requires_valid_recipient(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->postJson(route('admin.environment.test-mail'), ['to' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_test_mail_reports_transport_failure(): void
    {
        // No Mail::fake() — an unreachable SMTP host should surface as a JSON error,
        // not a 500, so the dialog can show the admin what went wrong.
        $this->actingAs($this->admin())
            ->postJson(route('admin.environment.test-mail'), [
                'to' => 'someone@example.com',
                'mail' => [
                    'MAIL_MAILER' => 'smtp',
                    'MAIL_HOST' => '127.0.0.1',
                    'MAIL_PORT' => '2',
                ],
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_test_mail_requires_admin(): void
    {
        $this->post(route('admin.environment.test-mail'), ['to' => 'someone@example.com'])
            ->assertRedirect(route('admin.login'));
    }
}
