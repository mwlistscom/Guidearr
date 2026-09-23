<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a provider from the grid, and the warning shown before it.
 *
 * Playlist channels are only POINTERS into a provider store, so deleting a provider used to leave
 * its playlists as a wall of "(missing channel)" rows that served nothing — alive, but useless, and
 * nothing said so. A playlist whose only source is that provider now goes with it.
 *
 * The load-bearing half is the REFUSAL, exactly as in `maintenance:reap-stale`: a playlist another
 * provider still feeds must survive. Its ordering, renames and group flags are the user's work on
 * the OTHER providers' channels, and this delete has no business throwing that away. A test that
 * only proves the deletion would pass just as happily against code that deletes everything.
 */
class ProviderDeleteCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
    }

    private function provider(User $u, string $name = 'P'): Provider
    {
        return Provider::create([
            'user_id' => $u->id,
            'name' => $name.uniqid(),
            'type' => 'manual',
            'enabled' => true,
        ]);
    }

    private function playlist(User $u, array $providers = [], array $attrs = []): Playlist
    {
        $p = Playlist::create(array_merge(['user_id' => $u->id, 'name' => 'PL'.uniqid()], $attrs));

        // Store files outlive an individual test (storage is relocated per RUN, not per test), so
        // a reused playlist id can inherit another test's store and make "the file is gone" or
        // "the file is still here" mean nothing at all. Start from no store.
        $this->unlinkStore($p->id);

        foreach ($providers as $prov) {
            $p->providers()->attach($prov->id);
        }

        return $p;
    }

    private function unlinkStore(int $id): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file(PlaylistStore::path($id).$suffix)) {
                @unlink(PlaylistStore::path($id).$suffix);
            }
        }
    }

    // ---- the deletion ---------------------------------------------------------------------

    public function test_it_deletes_a_playlist_whose_only_source_is_this_provider(): void
    {
        $u = $this->user();
        $prov = $this->provider($u);
        $pl = $this->playlist($u, [$prov]);

        touch(PlaylistStore::path($pl->id));

        $this->actingAs($u)->deleteJson('/providers/'.$prov->id)->assertOk();

        $this->assertNull(Playlist::find($pl->id), 'a playlist with no other source must go too');
        $this->assertFileDoesNotExist(PlaylistStore::path($pl->id), 'its SQLite store must be unlinked');
    }

    public function test_it_keeps_a_playlist_another_provider_still_feeds(): void
    {
        $u = $this->user();
        $doomed = $this->provider($u, 'doomed');
        $survivor = $this->provider($u, 'survivor');
        $pl = $this->playlist($u, [$doomed, $survivor]);

        $this->actingAs($u)->deleteJson('/providers/'.$doomed->id)->assertOk();

        $this->assertNotNull(Playlist::find($pl->id), 'a playlist with another source must survive');
        $this->assertSame(
            [$survivor->id],
            Playlist::find($pl->id)->providerIds(),
            'it must keep the surviving provider and lose only the deleted one'
        );
    }

    public function test_it_leaves_no_dangling_pivot_row_or_guide_reference(): void
    {
        $u = $this->user();
        $doomed = $this->provider($u, 'doomed');
        $survivor = $this->provider($u, 'survivor');

        // attached AND the guide source, alongside another provider
        $this->playlist($u, [$doomed, $survivor], ['guide_provider_id' => $doomed->id]);
        // guide source only — holds no channel pointers into it at all
        $guideOnly = $this->playlist($u, [$survivor], ['guide_provider_id' => $doomed->id]);

        $this->actingAs($u)->deleteJson('/providers/'.$doomed->id)->assertOk();

        $this->assertSame(0, DB::table('playlist_providers')->where('provider_id', $doomed->id)->count());
        $this->assertSame(0, Playlist::where('guide_provider_id', $doomed->id)->count());
        $this->assertNotNull(Playlist::find($guideOnly->id), 'losing a guide source must not delete a playlist');
    }

    public function test_a_playlist_using_it_only_as_a_guide_source_is_never_deleted(): void
    {
        $u = $this->user();
        $prov = $this->provider($u);
        // no pivot attachment at all — the provider is purely its EPG
        $pl = $this->playlist($u, [], ['guide_provider_id' => $prov->id]);

        $this->actingAs($u)->deleteJson('/providers/'.$prov->id)->assertOk();

        $this->assertNotNull(Playlist::find($pl->id));
        $this->assertNull(Playlist::find($pl->id)->guide_provider_id);
    }

    public function test_it_never_touches_another_users_playlists(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $prov = $this->provider($mine);
        $myPl = $this->playlist($mine, [$prov]);
        $theirPl = $this->playlist($theirs, []);

        $this->actingAs($mine)->deleteJson('/providers/'.$prov->id)->assertOk();

        $this->assertNull(Playlist::find($myPl->id));
        $this->assertNotNull(Playlist::find($theirPl->id));
    }

    public function test_a_stranger_cannot_delete_or_preview(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $prov = $this->provider($owner);
        $pl = $this->playlist($owner, [$prov]);

        $this->actingAs($stranger)->getJson('/providers/'.$prov->id.'/delete-impact')->assertForbidden();
        $this->actingAs($stranger)->deleteJson('/providers/'.$prov->id)->assertForbidden();

        $this->assertNotNull(Playlist::find($pl->id));
        $this->assertNotNull(Provider::find($prov->id));
    }

    // ---- the warning ----------------------------------------------------------------------

    public function test_the_preview_splits_doomed_playlists_from_surviving_ones(): void
    {
        $u = $this->user();
        $doomed = $this->provider($u, 'doomed');
        $survivor = $this->provider($u, 'survivor');

        $goesToo = $this->playlist($u, [$doomed], ['name' => 'Only Source']);
        $survives = $this->playlist($u, [$doomed, $survivor], ['name' => 'Has Another']);

        $body = $this->actingAs($u)
            ->getJson('/providers/'.$doomed->id.'/delete-impact')
            ->assertOk()
            ->json();

        $this->assertSame([['id' => $goesToo->id, 'name' => 'Only Source']], $body['deleted']);
        $this->assertSame(
            [['id' => $survives->id, 'name' => 'Has Another', 'others' => 1, 'guide' => false]],
            $body['affected']
        );
    }

    public function test_the_preview_flags_a_lost_guide_source(): void
    {
        $u = $this->user();
        $doomed = $this->provider($u, 'doomed');
        $survivor = $this->provider($u, 'survivor');

        $this->playlist($u, [$doomed, $survivor], ['name' => 'Both', 'guide_provider_id' => $doomed->id]);
        $this->playlist($u, [$survivor], ['name' => 'Guide Only', 'guide_provider_id' => $doomed->id]);

        $body = $this->actingAs($u)
            ->getJson('/providers/'.$doomed->id.'/delete-impact')
            ->assertOk()
            ->json();

        $this->assertSame([], $body['deleted'], 'nothing here is left without a source');
        $this->assertSame([
            ['id' => Playlist::where('name', 'Both')->value('id'), 'name' => 'Both', 'others' => 1, 'guide' => true],
            ['id' => Playlist::where('name', 'Guide Only')->value('id'), 'name' => 'Guide Only', 'others' => 0, 'guide' => true],
        ], $body['affected']);
    }

    public function test_the_preview_is_empty_for_an_unused_provider(): void
    {
        $u = $this->user();
        $prov = $this->provider($u);

        $body = $this->actingAs($u)
            ->getJson('/providers/'.$prov->id.'/delete-impact')
            ->assertOk()
            ->json();

        $this->assertSame([], $body['deleted']);
        $this->assertSame([], $body['affected']);
    }

    public function test_the_preview_does_not_change_anything(): void
    {
        $u = $this->user();
        $prov = $this->provider($u);
        $pl = $this->playlist($u, [$prov]);

        $this->actingAs($u)->getJson('/providers/'.$prov->id.'/delete-impact')->assertOk();

        $this->assertNotNull(Provider::find($prov->id));
        $this->assertNotNull(Playlist::find($pl->id));
    }
}
