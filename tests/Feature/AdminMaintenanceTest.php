<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) { @unlink($f); }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) { @unlink($f); }
        @unlink(storage_path('app/settings/app.json'));
    }

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    private function servablePlaylist(User $u, string $cipher): Playlist
    {
        $p = Provider::create(['user_id' => $u->id, 'name' => 'Src', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        $s->upsertChannel(['name' => 'CNN', 'url' => 'http://h/cnn.ts', 'group' => 'NEWS', 'tvg_id' => 'cnn', 'tvg_name' => 'CNN', 'tvg_logo' => ''], 'v1');
        $s->commit();
        $s->begin();
        $s->upsertGroup('NEWS', $s->nextGroupOrder(), 'v1');
        $s->commit();

        $pl = Playlist::create([
            'user_id' => $u->id, 'name' => 'PL', 'cipher' => $cipher,
            'channel_start' => 100, 'enabled' => true,
        ]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    public function test_serving_touches_playlist_and_backing_provider(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->servablePlaylist($u, 'touchKey0001');
        $provider = $pl->providers()->first();

        $this->assertNull($pl->fresh()->last_touch_at);
        $this->assertNull($provider->fresh()->last_touch_at);

        $this->get('/m3u?key=touchKey0001')->assertOk()->streamedContent();

        $this->assertNotNull($pl->fresh()->last_touch_at, 'playlist should be touched');
        $this->assertNotNull($provider->fresh()->last_touch_at, 'backing provider should be touched');
    }

    public function test_status_page_shows_system_stats(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()->assertSee('System')->assertSee('Disk')->assertSee('Data stores');
    }

    public function test_maintenance_lists_stale_and_prunes_selected(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $stalePl = $this->servablePlaylist($u, 'staleKey00001');
        $stalePl->forceFill(['last_touch_at' => now()->subDays(90)])->saveQuietly();
        $freshPl = $this->servablePlaylist($u, 'freshKey00001');
        $freshPl->forceFill(['last_touch_at' => now()])->saveQuietly();

        // 30-day window: only the 90-day-old one is stale.
        $this->actingAs($this->admin())->get(route('admin.maintenance', ['days' => 30]))
            ->assertOk()->assertSee('PL');

        $storePath = PlaylistStore::path($stalePl->id);
        $this->assertFileExists($storePath);

        $this->actingAs($this->admin())
            ->post(route('admin.maintenance.prune'), ['ids' => [$stalePl->id]])
            ->assertRedirect(route('admin.maintenance'));

        $this->assertNull(Playlist::find($stalePl->id), 'stale playlist deleted');
        $this->assertNotNull(Playlist::find($freshPl->id), 'fresh playlist kept');
        $this->assertFileDoesNotExist($storePath, 'store file unlinked on delete');
    }

    public function test_prune_requires_ids(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.maintenance.prune'), [])
            ->assertSessionHasErrors('ids');
    }
}
