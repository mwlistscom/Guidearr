<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\PurgeJob;
use App\Models\User;
use App\Services\PlaylistStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting an account removes its playlists by DB cascade, which fires no model events — so
 * Playlist::deleting never unlinked the store files. The purge queue has to carry them.
 */
class UserDeletePurgesPlaylistsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_user_removes_their_playlist_store_files(): void
    {
        $user = User::factory()->create();
        $keep = User::factory()->create();
        $mine = Playlist::create(['user_id' => $user->id, 'name' => 'Mine']);
        $theirs = Playlist::create(['user_id' => $keep->id, 'name' => 'Theirs']);

        foreach ([$mine, $theirs] as $pl) {
            @mkdir(dirname(PlaylistStore::path($pl->id)), 0777, true);
            foreach (['', '-wal', '-shm'] as $suffix) {
                file_put_contents(PlaylistStore::path($pl->id).$suffix, 'x');
            }
        }

        $uid = $user->id;
        $user->delete();

        $this->assertDatabaseMissing('playlists', ['id' => $mine->id]);
        $paths = collect(PurgeJob::where('user_id', $uid)->firstOrFail()->payload)->pluck('path');
        $this->assertContains(PlaylistStore::path($mine->id), $paths);

        $this->artisan('feed:purge')->assertSuccessful();

        foreach (['', '-wal', '-shm'] as $suffix) {
            $this->assertFileDoesNotExist(PlaylistStore::path($mine->id).$suffix);
            $this->assertFileExists(PlaylistStore::path($theirs->id).$suffix);   // someone else's
            @unlink(PlaylistStore::path($theirs->id).$suffix);
        }
    }
}
