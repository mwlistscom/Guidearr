<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use App\Services\ProviderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_guests_cannot_access_providers(): void
    {
        $this->get('/providers')->assertRedirect('/login');
    }

    public function test_user_can_create_a_manual_provider_and_see_it_in_the_grid_without_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/providers', [
            'name' => 'My Manual', 'type' => 'manual', 'myshift' => 2, 'enabled' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('providers', ['name' => 'My Manual', 'user_id' => $user->id, 'type' => 'manual']);

        $data = $this->actingAs($user)->getJson('/providers/data')->assertOk()->json();
        $this->assertCount(1, $data);
        $this->assertArrayNotHasKey('password', $data[0]); // grid never exposes secrets
        $this->assertSame(2, $data[0]['myshift']);
    }

    public function test_refresh_hour_and_minute_default_into_the_1_to_3_window(): void
    {
        $p = Provider::create(['user_id' => $this->user()->id, 'name' => 'X', 'type' => 'manual']);
        $this->assertTrue($p->refresh_hour >= 1 && $p->refresh_hour <= 3);
        $this->assertTrue($p->refresh_minute >= 0 && $p->refresh_minute <= 59);
    }

    public function test_explicit_refresh_hour_is_honored(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/providers', [
            'name' => 'Fixed', 'type' => 'manual', 'refresh_hour' => 17,
        ])->assertCreated();
        $this->assertSame(17, Provider::where('name', 'Fixed')->first()->refresh_hour);
    }

    public function test_auto_refresh_hour_on_edit_rerandomizes_into_window(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual', 'refresh_hour' => 17]);
        $this->actingAs($user)->putJson("/providers/{$p->id}", [
            'name' => 'X', 'type' => 'manual', 'refresh_hour' => null,
        ])->assertOk();
        $fresh = $p->fresh();
        $this->assertTrue($fresh->refresh_hour >= 1 && $fresh->refresh_hour <= 3);
    }

    public function test_password_is_encrypted_at_rest_but_readable_by_owner(): void
    {
        $p = Provider::create([
            'user_id' => $this->user()->id, 'name' => 'X', 'type' => 'manual', 'password' => 'sup3rsecret',
        ]);
        $raw = \DB::table('providers')->where('id', $p->id)->value('password');
        $this->assertNotSame('sup3rsecret', $raw);            // stored ciphertext
        $this->assertSame('sup3rsecret', $p->fresh()->password); // decrypts via cast
    }

    public function test_toggle_flips_enabled(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual', 'enabled' => false]);
        $this->actingAs($user)->postJson("/providers/{$p->id}/toggle")->assertOk()->assertJson(['enabled' => true]);
    }

    public function test_refresh_logs_a_manual_provider_and_logs_endpoint_returns_it(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual']);
        $this->actingAs($user)->postJson("/providers/{$p->id}/refresh")->assertOk()->assertJson(['status' => 'ok']);
        $logs = $this->actingAs($user)->getJson("/providers/{$p->id}/logs")->assertOk()->json();
        $this->assertCount(1, $logs);
        $this->assertSame('ok', $logs[0]['status']);
    }

    public function test_owner_only_access(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = Provider::create(['user_id' => $owner->id, 'name' => 'X', 'type' => 'manual']);

        $this->actingAs($other)->getJson("/providers/{$p->id}")->assertForbidden();
        $this->actingAs($other)->deleteJson("/providers/{$p->id}")->assertForbidden();
        $this->actingAs($owner)->getJson("/providers/{$p->id}")->assertOk()->assertJsonPath('name', 'X');
    }

    public function test_xtream_requires_username_and_password(): void
    {
        $this->actingAs($this->user())->postJson('/providers', [
            'name' => 'Bad Xtream', 'type' => 'xtream', 'url' => 'http://example.com:8080',
        ])->assertStatus(422);
    }

    public function test_inline_cell_edit_updates_allowed_fields_and_rejects_others(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'Old', 'type' => 'm3u', 'url' => 'http://a.example/x.m3u']);

        $this->actingAs($user)->patchJson("/providers/{$p->id}/cell", ['field' => 'name', 'value' => 'New'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('New', $p->fresh()->name);

        $this->actingAs($user)->patchJson("/providers/{$p->id}/cell", ['field' => 'url', 'value' => 'not a url'])
            ->assertStatus(422);
        $this->assertSame('http://a.example/x.m3u', $p->fresh()->url);

        $this->actingAs($user)->patchJson("/providers/{$p->id}/cell", ['field' => 'password', 'value' => 'x'])
            ->assertStatus(422);

        $this->actingAs($this->user())->patchJson("/providers/{$p->id}/cell", ['field' => 'name', 'value' => 'Hax'])
            ->assertForbidden();
    }

    public function test_validator_pure_logic(): void
    {
        $this->assertTrue(ProviderValidator::contentMatchesType("#EXTM3U\n#EXTINF:-1,Foo\nhttp://x", 'm3u'));
        $this->assertFalse(ProviderValidator::contentMatchesType("<html>nope</html>", 'm3u'));
        $this->assertTrue(ProviderValidator::contentMatchesType('<?xml version="1.0"?><tv generator="x"></tv>', 'xmltv'));
        $this->assertFalse(ProviderValidator::contentMatchesType('<?xml version="1.0"?><rss></rss>', 'xmltv'));

        $xtreamJson = '{"user_info":{"auth":1,"status":"Active"},"server_info":{"timezone":"America/New_York"}}';
        $parsed = ProviderValidator::parseXtream($xtreamJson);
        $this->assertTrue($parsed['ok']);
        $this->assertSame('America/New_York', $parsed['timeshift']);
        $this->assertFalse(ProviderValidator::parseXtream('{"user_info":{"auth":0}}')['ok']);
    }
}
