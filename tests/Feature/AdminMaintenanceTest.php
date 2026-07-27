<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use App\Support\MaintenanceLog;
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

    public function test_maintenance_page_shows_task_controls(): void
    {
        $this->actingAs($this->admin())->get(route('admin.maintenance'))
            ->assertOk()
            ->assertSee('Maintenance tasks')
            ->assertSee('Health check')
            ->assertSee('Run now');
    }

    public function test_run_returns_a_token_for_a_valid_task(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.maintenance.run'), ['task' => 'reclaim'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'token', 'label']);
    }

    public function test_run_rejects_unknown_task(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.maintenance.run'), ['task' => 'rm-rf-everything'])
            ->assertStatus(422);
    }

    public function test_run_allows_destructive_task_as_dry_run(): void
    {
        // Destructive tasks ARE runnable, but the popup runs them --dry-run first (preview).
        $this->actingAs($this->admin())
            ->postJson(route('admin.maintenance.run'), ['task' => 'prune-unverified', 'dry' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'dry' => true])
            ->assertJsonStructure(['ok', 'token', 'label', 'dry']);
    }

    public function test_dry_run_command_marks_the_log_and_makes_no_changes(): void
    {
        // A recently-created unverified user must NOT be deleted by a dry run of prune-unverified.
        $victim = User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subDays(30)]);

        $this->artisan('maintenance:run', ['task' => 'prune-unverified', '--token' => 'dry1', '--dry' => true])
            ->assertExitCode(0);

        $this->assertNotNull($victim->fresh(), 'dry run must not delete anyone');
        $this->assertStringContainsString('DRY RUN', MaintenanceLog::read());
    }

    public function test_output_endpoint_tails_a_run_by_token(): void
    {
        MaintenanceLog::write('=== BEGIN tok9 — Health check ===');
        MaintenanceLog::write('hello from the task');
        MaintenanceLog::write('=== END tok9 — exit=0 ===');

        $this->actingAs($this->admin())
            ->getJson(route('admin.maintenance.output', ['token' => 'tok9']))
            ->assertOk()
            ->assertJson(['started' => true, 'done' => true])
            ->assertSee('hello from the task');
    }

    public function test_maintenance_run_command_logs_begin_and_end(): void
    {
        $this->artisan('maintenance:run', ['task' => 'reclaim', '--token' => 'cmd1'])
            ->assertExitCode(0);

        $log = MaintenanceLog::read();
        $this->assertStringContainsString('BEGIN cmd1', $log);
        $this->assertStringContainsString('END cmd1', $log);
    }
}
