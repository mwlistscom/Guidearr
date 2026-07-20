<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTouchTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function provider(User $u, string $name = 'P'): Provider
    {
        return Provider::create([
            'user_id' => $u->id, 'name' => $name, 'type' => 'm3u',
            'url' => 'http://h/list.m3u', 'enabled' => true, 'refresh_hour' => 2,
            'last_touch_at' => now()->subDays(30),
        ]);
    }

    public function test_mark_touched_bumps_playlist_and_all_backing_providers(): void
    {
        $u = $this->owner();
        $content = $this->provider($u, 'content');
        $guide = $this->provider($u, 'guide');
        $pl = Playlist::create([
            'user_id' => $u->id, 'name' => 'PL', 'cipher' => Playlist::freshCipher(),
            'guide_provider_id' => $guide->id, 'last_touch_at' => now()->subDays(30),
        ]);
        $pl->providers()->attach($content->id);

        $pl->markTouched();

        $this->assertTrue($pl->fresh()->last_touch_at->greaterThan(now()->subMinute()));
        $this->assertTrue($content->fresh()->last_touch_at->greaterThan(now()->subMinute()), 'content provider warmed');
        $this->assertTrue($guide->fresh()->last_touch_at->greaterThan(now()->subMinute()), 'guide provider warmed');
    }

    public function test_editing_a_playlist_warms_its_provider(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u);
        $pl = Playlist::create([
            'user_id' => $u->id, 'name' => 'PL', 'cipher' => Playlist::freshCipher(),
            'last_touch_at' => now()->subDays(30),
        ]);
        $pl->providers()->attach($provider->id);

        // A mutating edit (POST reindex) warms the provider via the touch middleware.
        $this->actingAs($u)->post(route('playlists.reindex', $pl))->assertOk();
        $this->assertTrue($provider->fresh()->last_touch_at->greaterThan(now()->subMinute()), 'edit warmed it');
    }

    /** Viewing counts as activity too — a user who browses without editing must not go cold. */
    public function test_viewing_a_playlist_warms_its_provider(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u);
        $pl = Playlist::create([
            'user_id' => $u->id, 'name' => 'PL', 'cipher' => Playlist::freshCipher(),
            'last_touch_at' => now()->subDays(30),
        ]);
        $pl->providers()->attach($provider->id);

        $this->actingAs($u)->get(route('playlists.channels', $pl))->assertOk();

        $this->assertTrue($provider->fresh()->last_touch_at->greaterThan(now()->subMinute()), 'read warmed it');
    }

    /**
     * The case this whole mechanism exists for: a provider with NO playlist attached. Its only
     * activity signal is the dashboard, so opening the providers grid must warm it — otherwise
     * simply using the app wouldn't stop the reaper disabling it.
     */
    public function test_opening_the_providers_grid_warms_a_provider_with_no_playlist(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u);

        $this->actingAs($u)->get(route('providers.data'))->assertOk();

        $this->assertTrue($provider->fresh()->last_touch_at->greaterThan(now()->subMinute()));
    }

    public function test_grid_view_does_not_warm_another_users_provider(): void
    {
        $mine = $this->provider($this->owner());
        $theirs = $this->provider($this->owner(), 'other');

        $this->actingAs($mine->user)->get(route('providers.data'))->assertOk();

        $this->assertTrue($theirs->fresh()->last_touch_at->lessThan(now()->subDays(20)), 'other owner left cold');
    }

    /** A recently-touched row is skipped, so a reloading/polling grid isn't a write storm. */
    public function test_repeat_views_are_throttled(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u, 'warm');
        $provider->forceFill(['last_touch_at' => now()->subMinutes(5)])->save();
        $before = $provider->fresh()->last_touch_at;

        $this->actingAs($u)->get(route('providers.data'))->assertOk();

        $this->assertTrue($provider->fresh()->last_touch_at->equalTo($before), 'inside the throttle window');
    }

    /** Viewing a cold-reaped provider means it is wanted again → refresh resumes. */
    public function test_viewing_revives_a_cold_reaped_provider(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u);
        $provider->forceFill(['enabled' => false, 'last_status' => Provider::REAPED_STATUS])->save();

        $this->actingAs($u)->get(route('providers.data'))->assertOk();

        $this->assertTrue((bool) $provider->fresh()->enabled);
    }

    public function test_viewing_does_not_revive_a_failure_disabled_provider(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u);
        $provider->forceFill(['enabled' => false, 'last_status' => 'failed'])->save();

        $this->actingAs($u)->get(route('providers.data'))->assertOk();

        $this->assertFalse((bool) $provider->fresh()->enabled);
    }
}
