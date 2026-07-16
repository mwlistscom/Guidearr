<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProvidersReapColdTest extends TestCase
{
    use RefreshDatabase;

    private function provider(array $attrs = []): Provider
    {
        $u = User::factory()->create();

        return Provider::create(array_merge([
            'user_id' => $u->id, 'name' => 'P', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => true, 'refresh_hour' => 2, 'last_touch_at' => now()->subDays(30),
        ], $attrs));
    }

    public function test_disables_a_cold_provider_but_keeps_its_data(): void
    {
        $p = $this->provider(['last_touch_at' => now()->subDays(30)]);

        Artisan::call('providers:reap-cold');

        $p->refresh();
        $this->assertFalse((bool) $p->enabled);
        $this->assertSame(Provider::REAPED_STATUS, $p->last_status);
        $this->assertDatabaseHas('providers', ['id' => $p->id]); // row kept, not deleted
    }

    public function test_keeps_a_warm_provider_enabled(): void
    {
        $p = $this->provider(['last_touch_at' => now()->subDay()]);

        Artisan::call('providers:reap-cold');

        $this->assertTrue((bool) $p->fresh()->enabled);
    }

    public function test_null_last_touch_counts_as_cold(): void
    {
        $p = $this->provider(['last_touch_at' => null]);

        Artisan::call('providers:reap-cold');

        $this->assertFalse((bool) $p->fresh()->enabled);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $p = $this->provider(['last_touch_at' => now()->subDays(30)]);

        Artisan::call('providers:reap-cold', ['--dry-run' => true]);

        $this->assertTrue((bool) $p->fresh()->enabled);
    }

    public function test_does_not_touch_a_provider_disabled_for_other_reasons(): void
    {
        // A provider disabled by fetch failures (last_status 'failed') that is also stale must be
        // left exactly as-is — the reaper only looks at enabled providers.
        $p = $this->provider(['enabled' => false, 'last_status' => 'failed', 'last_touch_at' => now()->subDays(30)]);

        Artisan::call('providers:reap-cold');

        $this->assertSame('failed', $p->fresh()->last_status); // untouched, not relabelled 'cold'
    }

    public function test_reaped_provider_is_revived_when_its_playlist_is_accessed(): void
    {
        $u = User::factory()->create();
        $p = Provider::create([
            'user_id' => $u->id, 'name' => 'P', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => false, 'last_status' => Provider::REAPED_STATUS, 'last_touch_at' => now()->subDays(30),
        ]);
        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => Playlist::freshCipher()]);
        $pl->providers()->attach($p->id);

        $pl->markTouched(); // simulates a serve or edit

        $this->assertTrue((bool) $p->fresh()->enabled, 'cold-reaped provider revived on access');
    }

    public function test_failure_disabled_provider_is_not_revived_on_access(): void
    {
        $u = User::factory()->create();
        $p = Provider::create([
            'user_id' => $u->id, 'name' => 'P', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => false, 'last_status' => 'failed', 'last_touch_at' => now()->subDays(30),
        ]);
        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => Playlist::freshCipher()]);
        $pl->providers()->attach($p->id);

        $pl->markTouched();

        $this->assertFalse((bool) $p->fresh()->enabled, 'a fetch-failure disable must NOT resurrect');
    }
}
