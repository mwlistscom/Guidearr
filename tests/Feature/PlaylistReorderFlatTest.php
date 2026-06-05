<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistReorderFlatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) { @unlink($f); }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) { @unlink($f); }
    }

    private function playlist(User $u): Playlist
    {
        $p = Provider::create(['user_id' => $u->id, 'name' => 'S', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        $rows = [['C1', 'CANADA'], ['C2', 'CANADA'], ['C3', 'CANADA'], ['A1', 'US-A'], ['A2', 'US-A'], ['B1', 'US-B'], ['B2', 'US-B'], ['D1', 'US-C'], ['D2', 'US-C']];
        $i = 0;
        foreach ($rows as [$n, $g]) { $i++; $s->upsertChannel(['name' => $n, 'url' => "http://h/{$i}.ts", 'group' => $g, 'tvg_id' => "id{$i}", 'tvg_name' => $n, 'tvg_logo' => ''], 'v1'); }
        $s->commit();
        $s->begin();
        $o = $s->nextGroupOrder();
        foreach (['CANADA', 'US-A', 'US-B', 'US-C'] as $g) { $s->upsertGroup($g, $o, 'v1'); $o += 10; }
        $s->commit();

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'reflat000001', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    private function names(Playlist $pl): array
    {
        $page = (new PlaylistStore($pl->id))->effectiveChannelPage(null, null, 'hide', 1, 100);

        return array_map(fn ($r) => $r['name'], $page['rows']);
    }

    public function test_rerunning_reorder_flat_does_not_clobber_a_manual_order(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);
        $st = new PlaylistStore($pl->id);

        // A fresh seed under the flat model is already flat.
        $this->assertFalse($st->needsFlatten());

        // Manually move a US-C channel to the very top — a cross-group arrangement.
        $page = $st->effectiveChannelPage(null, null, 'hide', 1, 100);
        $d1 = null;
        foreach ($page['rows'] as $r) { if ($r['name'] === 'D1') { $d1 = (int) $r['id']; } }
        $st->moveChannelToRow($d1, 1);
        $manual = $this->names($pl);
        $this->assertSame('D1', $manual[0]);

        // Re-running the migration must leave the manual order untouched (this was the bug).
        $this->artisan('playlists:reorder-flat')->assertSuccessful();
        $this->assertSame($manual, $this->names($pl), 're-running reorder-flat must not re-group a flat playlist');
    }

    public function test_legacy_per_group_numbering_is_detected_and_flattened_once(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);
        $st = new PlaylistStore($pl->id);

        // Simulate the old per-group numbering directly in the playlist store: each group restarts at 10.
        $pdo = new \PDO('sqlite:' . PlaylistStore::path($pl->id));
        $pdo->exec('PRAGMA foreign_keys=ON');
        $byGroup = $pdo->query('SELECT id, group_title FROM playlist_channels WHERE deleted = 0 ORDER BY group_title, id')->fetchAll(\PDO::FETCH_ASSOC);
        $pos = []; $upd = $pdo->prepare('UPDATE playlist_channels SET position_order = ? WHERE id = ?');
        foreach ($byGroup as $r) {
            $g = $r['group_title'];
            $pos[$g] = ($pos[$g] ?? 0) + 10;
            $upd->execute([$pos[$g], $r['id']]);
        }

        $this->assertTrue($st->needsFlatten(), 'per-group numbering collides → needs flattening');

        $this->artisan('playlists:reorder-flat')->assertSuccessful();

        $this->assertFalse($st->needsFlatten());
        // Flattened in group-then-position order.
        $this->assertSame(['C1', 'C2', 'C3', 'A1', 'A2', 'B1', 'B2', 'D1', 'D2'], $this->names($pl));
    }
}
