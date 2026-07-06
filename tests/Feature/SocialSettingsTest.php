<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use App\Support\SocialConfig;
use App\Support\SocialLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(storage_path('app/settings/app.json'));
    }

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_secret_is_stored_encrypted_and_round_trips(): void
    {
        SocialConfig::save('google', ['enabled' => true, 'client_id' => 'goog-id-1', 'client_secret' => 'goog-key-1', 'redirect' => '']);

        $raw = Settings::get('social')['google']['client_secret'];
        $this->assertNotSame('goog-key-1', $raw);                          // stored ciphertext, not plaintext
        $this->assertSame('goog-key-1', SocialConfig::provider('google')['client_secret']); // decrypts back
    }

    public function test_blank_secret_keeps_the_existing_one(): void
    {
        SocialConfig::save('google', ['enabled' => true, 'client_id' => 'goog-id-1', 'client_secret' => 'goog-key-1']);
        SocialConfig::save('google', ['enabled' => true, 'client_id' => 'goog-id-2', 'client_secret' => '']); // blank

        $this->assertSame('goog-id-2', SocialConfig::provider('google')['client_id']);
        $this->assertSame('goog-key-1', SocialConfig::provider('google')['client_secret']);
    }

    public function test_enabled_requires_toggle_and_both_keys(): void
    {
        SocialConfig::save('google', ['enabled' => false, 'client_id' => 'goog-id-1', 'client_secret' => 'goog-key-1']);
        $this->assertFalse(SocialConfig::enabled('google'));                // toggled off

        SocialConfig::save('google', ['enabled' => true, 'client_id' => 'goog-id-1', 'client_secret' => 'goog-key-1']);
        $this->assertTrue(SocialConfig::enabled('google'));
    }

    public function test_hydrate_feeds_config_only_for_enabled_providers_and_lights_up_the_button(): void
    {
        SocialConfig::save('google', ['enabled' => true, 'client_id' => 'goog-id-1', 'client_secret' => 'goog-key-1', 'redirect' => 'https://h/auth/google/callback']);
        SocialConfig::save('facebook', ['enabled' => false, 'client_id' => 'fb-id-1', 'client_secret' => 'fb-key-1']);

        SocialConfig::hydrateServices();

        $this->assertSame('goog-id-1', config('services.google.client_id'));
        $this->assertSame('goog-key-1', config('services.google.client_secret'));
        $this->assertTrue(SocialLogin::enabled('google'));
        $this->assertFalse(SocialLogin::enabled('facebook'));               // disabled -> not hydrated

        $this->get('/login')->assertOk()->assertSee('Continue with Google')->assertDontSee('Continue with Facebook');
    }

    public function test_admin_page_renders_with_callback_url(): void
    {
        $this->actingAs($this->admin())->get(route('admin.social'))
            ->assertOk()
            ->assertSee('Enable Google sign-in')
            ->assertSee('/auth/google/callback')
            ->assertSee('/data-deletion/facebook')
            ->assertSee('Data Deletion Instructions URL'); // both Meta options shown
    }

    public function test_page_requires_admin(): void
    {
        $this->get(route('admin.social'))->assertRedirect();
    }

    public function test_admin_can_save_and_enable_a_provider(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.social.update'), [
                'google' => ['enabled' => '1', 'client_id' => 'goog-id-1', 'client_secret' => 'goog-key-1', 'redirect' => ''],
                'facebook' => ['client_id' => '', 'client_secret' => ''],
            ])
            ->assertRedirect(route('admin.social'));

        $this->assertTrue(SocialConfig::enabled('google'));
    }

    public function test_enabling_without_keys_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.social.update'), ['google' => ['enabled' => '1', 'client_id' => '', 'client_secret' => '']])
            ->assertSessionHasErrors('google');

        $this->assertFalse(SocialConfig::enabled('google'));
    }
}
