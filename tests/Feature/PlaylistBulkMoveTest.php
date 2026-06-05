<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistBulkMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) { @unlink($f); }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) { @unlink($f); }
    }

    /**
     * CANADA group seeded as: CTV, CBC Toronto, Global, CBC Vancouver, CBC News.
     * A second group US (ABC, NBC) exists to prove groups are left alone.
     */
    private function playlist(User $u): Playlist
    {
        $p = Provider::create(['user_id' => $u->id, 'name' => 'Src', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        $rows = [
            ['CTV',           'CANADA'],
            ['CBC Toronto',   'CANADA'],
            ['Global',        'CANADA'],
            ['CBC Vancouver', 'CANADA'],
            ['CBC News',      'CANADA'],
            ['ABC',           'US'],
            ['NBC',           'US'],
        ];
        $i = 0;
        foreach ($rows as [$name, $group]) {
            $i++;
            $s->upsertChannel(['name' => $name, 'url' => "http://h/{$i}.ts", 'group' => $group, 'tvg_id' => "id{$i}", 'tvg_name' => $name, 'tvg_logo' => ''], 'v1');
        }
        $s->commit();
        $s->begin();
        $o = $s->nextGroupOrder();
        foreach (['CANADA', 'US'] as $g) { $s->upsertGroup($g, $o, 'v1'); $o += 10; }
        $s->commit();

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'bulkkey000001', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    /** @return array{ids:int[], names:string[]} ordered list + the three CBC ids */
    private function snapshot(Playlist $pl): array
    {
        $page = (new PlaylistStore($pl->id))->effectiveChannelPage(null, null, 'hide', 1, 100);
        $names = array_map(fn ($r) => $r['name'], $page['rows']);
        $cbc = [];
        foreach ($page['rows'] as $r) {
            if (str_starts_with($r['name'], 'CBC')) { $cbc[] = (int) $r['id']; }
        }

        return ['ids' => $cbc, 'names' => $names, 'rows' => $page['rows']];
    }

    public function test_bulk_move_to_top_keeps_relative_order_and_group(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        $snap = $this->snapshot($pl);
        // CBC ids in their seeded (relative) order: Toronto, Vancouver, News
        (new PlaylistStore($pl->id))->moveChannelsBulk($snap['ids'], 1);

        $after = $this->snapshot($pl);
        $this->assertSame(
            ['CBC Toronto', 'CBC Vancouver', 'CBC News', 'CTV', 'Global', 'ABC', 'NBC'],
            $after['names'],
            'CBC block should sit first, in original relative order, with the rest following'
        );
        // every CBC row still in CANADA
        foreach ($after['rows'] as $r) {
            if (str_starts_with($r['name'], 'CBC')) {
                $this->assertSame('CANADA', $r['group_title']);
            }
        }
    }

    public function test_bulk_move_to_middle_row_starts_block_at_that_row(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        $snap = $this->snapshot($pl);
        // Move the 3 CBC to start at row 3 → after CTV, Global, before US
        (new PlaylistStore($pl->id))->moveChannelsBulk($snap['ids'], 3);

        $after = $this->snapshot($pl);
        $this->assertSame(
            ['CTV', 'Global', 'CBC Toronto', 'CBC Vancouver', 'CBC News', 'ABC', 'NBC'],
            $after['names']
        );
    }

    public function test_bulk_move_endpoint_requires_owner_and_returns_count(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($owner);
        $ids = $this->snapshot($pl)['ids'];

        // a stranger cannot move
        $stranger = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($stranger)
            ->postJson(route('playlists.channels.move-bulk', $pl), ['ids' => $ids, 'row' => 1])
            ->assertForbidden();

        // owner can, and gets the moved count back
        $this->actingAs($owner)
            ->postJson(route('playlists.channels.move-bulk', $pl), ['ids' => $ids, 'row' => 1])
            ->assertOk()
            ->assertJson(['moved' => 3]);

        $this->assertSame('CBC Toronto', $this->snapshot($pl)['names'][0]);
    }

    public function test_bulk_move_across_groups_lands_at_row_and_keeps_each_group(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        // Select one CANADA channel + one US channel, move the pair to the very top.
        $page = (new PlaylistStore($pl->id))->effectiveChannelPage(null, null, 'hide', 1, 100);
        $byName = [];
        foreach ($page['rows'] as $r) { $byName[$r['name']] = (int) $r['id']; }
        $ids = [$byName['CBC Toronto'], $byName['ABC']];

        (new PlaylistStore($pl->id))->moveChannelsBulk($ids, 1);

        $after = $this->snapshot($pl);
        $this->assertSame(
            ['CBC Toronto', 'ABC', 'CTV', 'Global', 'CBC Vancouver', 'CBC News', 'NBC'],
            $after['names'],
            'a CANADA and a US channel can sit adjacent at the top — group is not a sort key'
        );
        $g = [];
        foreach ($after['rows'] as $r) { $g[$r['name']] = $r['group_title']; }
        $this->assertSame('CANADA', $g['CBC Toronto']);
        $this->assertSame('US', $g['ABC']);
    }

    public function test_single_move_across_groups_keeps_group(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);
        $page = (new PlaylistStore($pl->id))->effectiveChannelPage(null, null, 'hide', 1, 100);
        $nbc = null;
        foreach ($page['rows'] as $r) { if ($r['name'] === 'NBC') { $nbc = (int) $r['id']; } }

        (new PlaylistStore($pl->id))->moveChannelToRow($nbc, 1);

        $after = $this->snapshot($pl);
        $this->assertSame('NBC', $after['names'][0]);
        $g = [];
        foreach ($after['rows'] as $r) { $g[$r['name']] = $r['group_title']; }
        $this->assertSame('US', $g['NBC'], 'moving NBC to the top must not change its group');
    }
}
