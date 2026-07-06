<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistGroupMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) { @unlink($f); }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) { @unlink($f); }
    }

    /** Seeds flat: C1,C2,C3,A1,A2,B1,B2,D1,D2 ; pane: CANADA,US-A,US-B,US-C */
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

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'grpmove00001', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    private function names(Playlist $pl): array
    {
        $page = (new PlaylistStore($pl->id))->effectiveChannelPage(null, null, 'hide', 1, 100);

        return array_map(fn ($r) => $r['name'], $page['rows']);
    }

    public function test_group_move_relocates_the_whole_group_block(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);
        $st = new PlaylistStore($pl->id);

        // Seeded in group-pane order.
        $this->assertSame(['C1', 'C2', 'C3', 'A1', 'A2', 'B1', 'B2', 'D1', 'D2'], $this->names($pl));

        // Move the CANADA group to position 3 (before US-C). The ENTIRE CANADA block moves as one,
        // since channel output order follows the group pane order.
        $canada = collect($st->groups())->firstWhere('group_title', 'CANADA');
        $st->moveGroupToRow((int) $canada['id'], 3);

        $this->assertSame(
            ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'C3', 'D1', 'D2'],
            $this->names($pl),
            'all of CANADA (C1,C2,C3) relocates together, contiguously, before US-C'
        );

        // Pane order reflects the move: CANADA now sits 3rd.
        $pane = array_map(fn ($g) => $g['group_title'], $st->groups());
        $this->assertSame(['US-A', 'US-B', 'CANADA', 'US-C'], $pane);
    }
}
