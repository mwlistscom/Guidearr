<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reordering while the editor's channel grid is FILTERED.
 *
 * The "#" column is the position within the filtered result set — search for "ESPN" and the
 * matches are numbered 1..N regardless of where they sit in the playlist. Sending that number to
 * the server as a global row is what used to fling a dragged channel to the top of the list. Moves
 * are therefore anchored on a neighbouring row id, and a typed "row #" is resolved against the same
 * filtered list the user read it off.
 */
class PlaylistFilteredMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Flat order: A1, ESPN 1, B1, B2, ESPN 2, C1, ESPN 3 — the ESPN rows deliberately scattered. */
    private function playlist(User $u): Playlist
    {
        $p = Provider::create(['user_id' => $u->id, 'name' => 'S', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        $names = ['A1', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1', 'ESPN 3'];
        foreach ($names as $i => $n) {
            $s->upsertChannel(['name' => $n, 'url' => 'http://h/'.($i + 1).'.ts', 'group' => 'SPORTS', 'tvg_id' => 'id'.($i + 1), 'tvg_name' => $n, 'tvg_logo' => ''], 'v1');
        }
        $s->commit();
        $s->begin();
        $s->upsertGroup('SPORTS', $s->nextGroupOrder(), 'v1');
        $s->commit();

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'fltmove00001', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    private function names(Playlist $pl, ?string $search = null): array
    {
        $page = (new PlaylistStore($pl->id))->effectiveChannelPage($search, null, 'hide', 1, 100);

        return array_map(fn ($r) => $r['name'], $page['rows']);
    }

    /** id of the channel with the given displayed name. */
    private function id(Playlist $pl, string $name): int
    {
        foreach ((new PlaylistStore($pl->id))->effectiveChannelPage(null, null, 'hide', 1, 100)['rows'] as $r) {
            if ($r['name'] === $name) {
                return (int) $r['id'];
            }
        }
        $this->fail("no channel named {$name}");
    }

    public function test_the_fixture_starts_in_the_expected_order(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $this->assertSame(['A1', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1', 'ESPN 3'], $this->names($pl));
        $this->assertSame(['ESPN 1', 'ESPN 2', 'ESPN 3'], $this->names($pl, 'ESPN'));
    }

    /**
     * The reported bug. Filtered to ESPN, drag ESPN 3 from #3 to between #1 and #2. It must land
     * directly after ESPN 1 in the FULL list — not at global row 2, which is where sending the
     * filtered row number put it.
     */
    public function test_dragging_between_two_filtered_rows_lands_between_them_globally(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $st = new PlaylistStore($pl->id);

        $this->assertTrue($st->moveChannelRelative($this->id($pl, 'ESPN 3'), $this->id($pl, 'ESPN 1'), null));

        $this->assertSame(['A1', 'ESPN 1', 'ESPN 3', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
        $this->assertSame(['ESPN 1', 'ESPN 3', 'ESPN 2'], $this->names($pl, 'ESPN'));
    }

    /**
     * Dropped at the top of a FILTERED list, a channel goes immediately before the first match —
     * not to global row 1, which is a different place entirely.
     */
    public function test_dropping_at_the_top_of_a_filtered_list_lands_before_the_first_match(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $st = new PlaylistStore($pl->id);

        $st->moveChannelRelative($this->id($pl, 'ESPN 3'), null, $this->id($pl, 'ESPN 1'));

        $this->assertSame(['A1', 'ESPN 3', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    /** Unfiltered, a drop at the very top really is global row 1. */
    public function test_dropping_before_the_first_row_of_the_unfiltered_list_is_global_row_one(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $st = new PlaylistStore($pl->id);

        $st->moveChannelRelative($this->id($pl, 'ESPN 3'), null, $this->id($pl, 'A1'));

        $this->assertSame(['ESPN 3', 'A1', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    /** A drop onto the last row appends; nothing else shifts. */
    public function test_dropping_after_the_last_row_appends(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $st = new PlaylistStore($pl->id);

        $st->moveChannelRelative($this->id($pl, 'A1'), $this->id($pl, 'ESPN 3'), null);

        $this->assertSame(['ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1', 'ESPN 3', 'A1'], $this->names($pl));
    }

    /** A stale grid pointing at a row that has since gone changes nothing. */
    public function test_a_missing_anchor_is_a_no_op(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $st = new PlaylistStore($pl->id);

        $this->assertFalse($st->moveChannelRelative($this->id($pl, 'ESPN 3'), 999999, null));
        $this->assertFalse($st->moveChannelRelative($this->id($pl, 'ESPN 3'), $this->id($pl, 'ESPN 3'), null));
        $this->assertSame(['A1', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1', 'ESPN 3'], $this->names($pl));
    }

    /**
     * Neighbours whose position_order has collapsed to the same value can't be split by a midpoint;
     * the move renumbers the sequence instead of silently landing on the wrong side.
     */
    public function test_a_degenerate_gap_falls_back_to_a_renumber(): void
    {
        $pl = $this->playlist(User::factory()->create(['email_verified_at' => now()]));
        $st = new PlaylistStore($pl->id);

        // Collapse ESPN 1 and B1 onto one position_order, then drop ESPN 3 between them.
        $ref = new \ReflectionProperty(PlaylistStore::class, 'db');
        $ref->setAccessible(true);
        $db = $ref->getValue($st);
        $db->prepare('UPDATE playlist_channels SET position_order = 20 WHERE id = ?')->execute([$this->id($pl, 'B1')]);

        $st->moveChannelRelative($this->id($pl, 'ESPN 3'), $this->id($pl, 'ESPN 1'), null);

        $this->assertSame(['A1', 'ESPN 1', 'ESPN 3', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    // ---- HTTP ----

    public function test_the_move_endpoint_accepts_a_drag_anchor(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        $this->actingAs($u)
            ->postJson("/playlists/{$pl->id}/channels/".$this->id($pl, 'ESPN 3').'/move', ['after_id' => $this->id($pl, 'ESPN 1')])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['A1', 'ESPN 1', 'ESPN 3', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    /** "Move to row 2" with the ESPN filter on means "2nd of the ESPN rows", not global row 2. */
    public function test_move_to_row_is_resolved_against_the_active_filter(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        $this->actingAs($u)
            ->postJson("/playlists/{$pl->id}/channels/".$this->id($pl, 'ESPN 3').'/move', ['row' => 2, 'search' => 'ESPN'])
            ->assertOk();

        $this->assertSame(['ESPN 1', 'ESPN 3', 'ESPN 2'], $this->names($pl, 'ESPN'));
        $this->assertSame(['A1', 'ESPN 1', 'ESPN 3', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    /** Row 1 under a filter means "ahead of the first match", still not the top of the playlist. */
    public function test_move_to_row_one_under_a_filter_goes_ahead_of_the_first_match(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        $this->actingAs($u)
            ->postJson("/playlists/{$pl->id}/channels/".$this->id($pl, 'ESPN 3').'/move', ['row' => 1, 'search' => 'ESPN'])
            ->assertOk();

        $this->assertSame(['A1', 'ESPN 3', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    /** With no filter the endpoint still means a global row — unchanged behaviour. */
    public function test_move_to_row_without_a_filter_is_still_a_global_row(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);

        $this->actingAs($u)
            ->postJson("/playlists/{$pl->id}/channels/".$this->id($pl, 'ESPN 3').'/move', ['row' => 2])
            ->assertOk();

        $this->assertSame(['A1', 'ESPN 3', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1'], $this->names($pl));
    }

    public function test_a_stranger_cannot_move_a_channel(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlist($u);
        $stranger = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->postJson("/playlists/{$pl->id}/channels/".$this->id($pl, 'ESPN 3').'/move', ['after_id' => $this->id($pl, 'ESPN 1')])
            ->assertForbidden();

        $this->assertSame(['A1', 'ESPN 1', 'B1', 'B2', 'ESPN 2', 'C1', 'ESPN 3'], $this->names($pl));
    }
}
