<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistEditTouchTest extends TestCase
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

    public function test_editing_a_playlist_warms_its_provider_but_reading_does_not(): void
    {
        $u = $this->owner();
        $provider = $this->provider($u);
        $pl = Playlist::create([
            'user_id' => $u->id, 'name' => 'PL', 'cipher' => Playlist::freshCipher(),
            'last_touch_at' => now()->subDays(30),
        ]);
        $pl->providers()->attach($provider->id);

        // A GET read must NOT warm the provider (safe method → middleware no-ops).
        $this->actingAs($u)->get(route('playlists.channels', $pl))->assertOk();
        $this->assertTrue($provider->fresh()->last_touch_at->lessThan(now()->subDays(20)), 'read left it cold');

        // A mutating edit (POST reindex) warms the provider via the touch middleware.
        $this->actingAs($u)->post(route('playlists.reindex', $pl))->assertOk();
        $this->assertTrue($provider->fresh()->last_touch_at->greaterThan(now()->subMinute()), 'edit warmed it');
    }
}
