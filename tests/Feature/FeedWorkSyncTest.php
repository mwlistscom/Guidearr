<?php

namespace Tests\Feature;

use App\Console\Commands\FeedWork;
use App\Models\FeedQueue;
use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FeedWork::syncPlaylists() — the post-refresh playlist sync that runs on EVERY provider refresh.
 *
 * The guard under test: reconcile is skipped when the provider store is empty. A refresh that
 * returned zero channels (mid-import, or a fetch that failed upstream) would otherwise look like
 * "every channel is gone" and blank every playlist attached to that provider.
 *
 * syncPlaylists() is private, but it touches no network — it only reads the provider store from
 * disk and writes to the playlist store — so reflection drives it directly and deterministically.
 */
class FeedWorkSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_merge(glob(storage_path('app/playlists/*.sqlite')) ?: [], glob(storage_path('app/feeds/*.sqlite')) ?: []) as $f) {
            @unlink($f);
        }
    }

    /** A provider with a populated store: 2 channels in US-ENT, 3 in CANADA. */
    private function providerWithStore(User $u): Provider
    {
        $p = Provider::create([
            'user_id' => $u->id, 'name' => 'Grey', 'type' => 'xtream',
            'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2,
        ]);

        $s = new ProviderStore($p->id);
        $s->begin();
        foreach ([['US A', 'US-ENT'], ['US B', 'US-ENT'], ['CA A', 'CANADA'], ['CA B', 'CANADA'], ['CA C', 'CANADA']] as $i => [$n, $g]) {
            $s->upsertChannel(['name' => $n, 'url' => "http://h/{$i}.ts", 'group' => $g], 'v1');
        }
        $s->commit();

        $s->begin();
        $o = $s->nextGroupOrder();
        foreach (['US-ENT', 'CANADA'] as $g) {
            $s->upsertGroup($g, $o, 'v1');
            $o += 10;
        }
        $s->commit();

        return $p;
    }

    /** A queued job for $provider, as the worker would have claimed it. */
    private function job(Provider $p): FeedQueue
    {
        return FeedQueue::enqueue($p);
    }

    private function syncPlaylists(FeedQueue $job, Provider $provider): void
    {
        $m = new ReflectionMethod(FeedWork::class, 'syncPlaylists');
        $m->setAccessible(true);
        $m->invoke(new FeedWork, $job, $provider);
    }

    /** Baseline: with a populated store, the refresh DOES reconcile — orphaned pointers are dropped. */
    public function test_sync_reconciles_when_provider_store_has_channels(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = $this->providerWithStore($u);
        $this->actingAs($u)->postJson('/playlists', ['name' => 'PL', 'providers' => [$p->id]])->assertOk();
        $pl = Playlist::first();
        $this->assertSame(5, (new PlaylistStore($pl->id))->counts()['channels']);

        // The provider dropped 2 channels (already swept by the >3-miss mark-sweep).
        $ps = new ProviderStore($p->id);
        $ids = $ps->existingIds(range(1, 9999));
        $ps->deleteChannel($ids[0]);
        $ps->deleteChannel($ids[1]);

        $this->syncPlaylists($this->job($p), $p->fresh());

        $this->assertSame(3, (new PlaylistStore($pl->id))->counts()['channels']);
        $this->assertSame(0, (new PlaylistStore($pl->id))->missingPointerCount($p->id, new ProviderStore($p->id)));
    }

    /**
     * THE GUARD: an empty provider store means "this refresh returned nothing", not "every channel
     * is gone". Reconcile must be skipped so the playlist survives the bad refresh intact.
     */
    public function test_sync_does_not_reconcile_when_provider_store_is_empty(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = $this->providerWithStore($u);
        $this->actingAs($u)->postJson('/playlists', ['name' => 'PL', 'providers' => [$p->id]])->assertOk();
        $pl = Playlist::first();
        $this->assertSame(5, (new PlaylistStore($pl->id))->counts()['channels']);

        // A refresh that returned zero channels — every pointer now looks orphaned.
        $ps = new ProviderStore($p->id);
        foreach ($ps->existingIds(range(1, 9999)) as $id) {
            $ps->deleteChannel($id);
        }
        $this->assertSame(0, ProviderStore::channelCountFor($p->id));

        $this->syncPlaylists($this->job($p), $p->fresh());

        // All 5 kept — NOT blanked.
        $this->assertSame(5, (new PlaylistStore($pl->id))->counts()['channels']);
    }

    /** A provider store that isn't on disk at all is skipped before any playlist is touched. */
    public function test_sync_returns_early_when_provider_store_missing(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = $this->providerWithStore($u);
        $this->actingAs($u)->postJson('/playlists', ['name' => 'PL', 'providers' => [$p->id]])->assertOk();
        $pl = Playlist::first();

        @unlink(ProviderStore::path($p->id));
        $this->assertFalse(ProviderStore::exists($p->id));

        $this->syncPlaylists($this->job($p), $p->fresh());

        $this->assertSame(5, (new PlaylistStore($pl->id))->counts()['channels']);
    }

    /** The sync is additive as well: new provider channels reach the playlist on refresh. */
    public function test_sync_adds_new_channels_and_logs_both_counts(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = $this->providerWithStore($u);
        $this->actingAs($u)->postJson('/playlists', ['name' => 'PL', 'providers' => [$p->id]])->assertOk();
        $pl = Playlist::first();

        // The provider gained one channel in a brand-new group, and dropped one existing channel.
        $ps = new ProviderStore($p->id);
        $ps->begin();
        $ps->upsertChannel(['name' => 'Sport 1', 'url' => 'http://h/99.ts', 'group' => 'SPORTS'], 'v2');
        $ps->commit();
        $ps->deleteChannel($ps->existingIds(range(1, 9999))[0]);

        $job = $this->job($p);
        $this->syncPlaylists($job, $p->fresh());

        // 5 seeded - 1 orphan removed + 1 new = 5
        $this->assertSame(5, (new PlaylistStore($pl->id))->counts()['channels']);
        $this->assertDatabaseHas('feed_logs', [
            'msgid' => $job->msgid,
            'message' => "Playlist #{$pl->id} 'PL': added 1 channel(s), 1 group(s), removed 1 missing channel(s).",
        ]);
    }
}
