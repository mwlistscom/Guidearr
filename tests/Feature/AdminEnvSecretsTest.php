<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The env editor must never put a secret's value into the page — not even into a masked
 * password input — and saving the form with a secret left blank must keep the stored value.
 */
class AdminEnvSecretsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $env;

    protected function setUp(): void
    {
        parent::setUp();

        // Point the app at a throwaway .env so the real one is never read or written.
        $this->dir = sys_get_temp_dir().'/guidearr-env-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
        $this->env = $this->app->environmentFilePath();
        $this->assertStringStartsWith($this->dir, $this->env);
        file_put_contents($this->env, implode("\n", [
            'APP_NAME=Guidearr',
            'APP_KEY=base64:c3VwZXItc2VjcmV0LWFwcC1rZXktdmFsdWUtMTIzNDU2',
            'APP_URL=https://guidearr.example.com',
            'DB_PASSWORD=db-pass-SENTINEL',
            'MAIL_PASSWORD=mail-pass-SENTINEL',
            'TURNSTILE_SECRET_KEY=turnstile-SENTINEL',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $f) {
            if (is_file($this->dir.'/'.$f)) {
                @unlink($this->dir.'/'.$f);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_secret_values_are_not_in_the_page(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.environment'))->assertOk()->getContent();

        foreach (['c3VwZXItc2VjcmV0LWFwcC1rZXktdmFsdWUtMTIzNDU2', 'db-pass-SENTINEL', 'mail-pass-SENTINEL', 'turnstile-SENTINEL'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        // Non-secret values are still shown and editable.
        $this->assertStringContainsString('https://guidearr.example.com', $html);
    }

    public function test_saving_with_secrets_left_blank_keeps_them(): void
    {
        $this->actingAs($this->admin())->put(route('admin.environment.update'), ['env' => [
            'APP_NAME' => 'Renamed',
            'DB_PASSWORD' => '',
            'MAIL_PASSWORD' => '',
            'TURNSTILE_SECRET_KEY' => '',
        ]])->assertRedirect();

        $saved = file_get_contents($this->env);
        $this->assertStringContainsString('APP_NAME=Renamed', $saved);
        $this->assertStringContainsString('DB_PASSWORD=db-pass-SENTINEL', $saved);
        $this->assertStringContainsString('MAIL_PASSWORD=mail-pass-SENTINEL', $saved);
        $this->assertStringContainsString('TURNSTILE_SECRET_KEY=turnstile-SENTINEL', $saved);
    }

    public function test_a_typed_secret_is_still_saved(): void
    {
        $this->actingAs($this->admin())->put(route('admin.environment.update'), ['env' => [
            'MAIL_PASSWORD' => 'a-new-mail-password',
        ]])->assertRedirect();

        $this->assertStringContainsString('MAIL_PASSWORD=a-new-mail-password', file_get_contents($this->env));
    }
}
