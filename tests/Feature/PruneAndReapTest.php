<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two destructive maintenance tasks: `users:prune-idle` and `maintenance:reap-stale`.
 *
 * Both delete rows and their SQLite stores, and `reap-stale` runs unattended every week — so what
 * matters is not that they delete, but everything they must refuse to delete. These tests are
 * mostly about the refusals.
 *
 * The one that would be quietest if it broke: `reap-stale` must never delete a provider a
 * surviving playlist still points at. Playlist channels are pointers into a provider store, so
 * removing the provider turns a working playlist into "(missing channel)" rows that serve nothing
 * — and the playlist's own activity would not have flagged anything wrong.
 */
class PruneAndReapTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'is_admin' => false,
            'email_verified_at' => now(),
            'created_at' => now()->subDays(90),
        ], $attrs));
    }

    private function provider(User $u, array $attrs = []): Provider
    {
        // created_at is not fillable, so it has to be forced after the fact — passing it to
        // create() is silently dropped and every row lands "now", which quietly makes an
        // age-based test assert nothing at all.
        $createdAt = $attrs['created_at'] ?? now()->subDays(90);
        unset($attrs['created_at']);

        $p = Provider::create(array_merge([
            'user_id' => $u->id,
            'name' => 'P'.uniqid(),
            'type' => 'manual',
            'enabled' => true,
            'last_touch_at' => now()->subDays(90),
        ], $attrs));

        $p->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $p;
    }

    private function playlist(User $u, array $attrs = []): Playlist
    {
        $createdAt = $attrs['created_at'] ?? now()->subDays(90);
        unset($attrs['created_at']);

        $p = Playlist::create(array_merge([
            'user_id' => $u->id,
            'name' => 'PL'.uniqid(),
            'enabled' => true,
            'last_touch_at' => now()->subDays(90),
        ], $attrs));

        $p->forceFill(['created_at' => $createdAt])->saveQuietly();

        // Store files outlive an individual test (storage is relocated per RUN, not per test), so
        // a reused playlist id can inherit another test's channels and make an "empty" playlist
        // look populated. Start every playlist from no store at all.
        $path = PlaylistStore::path($p->id);
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) {
                @unlink($path.$suffix);
            }
        }

        return $p;
    }

    // ---- users:prune-idle ---------------------------------------------------------------

    public function test_it_deletes_an_account_that_never_set_anything_up(): void
    {
        $u = $this->user();

        $this->artisan('users:prune-idle')->assertSuccessful();

        $this->assertNull(User::find($u->id));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $u = $this->user();

        $this->artisan('users:prune-idle --dry-run')->assertSuccessful();

        $this->assertNotNull(User::find($u->id), 'a dry run must not delete');
    }

    public function test_an_admin_is_never_deleted(): void
    {
        $admin = $this->user(['is_admin' => true]);

        $this->artisan('users:prune-idle')->assertSuccessful();

        $this->assertNotNull(User::find($admin->id), 'admins are protected whatever their state');
    }

    public function test_any_provider_protects_the_account(): void
    {
        $u = $this->user();
        $this->provider($u);

        $this->artisan('users:prune-idle')->assertSuccessful();

        $this->assertNotNull(User::find($u->id), 'a provider means the account is in use');
    }

    public function test_a_recent_signup_is_left_alone(): void
    {
        $fresh = $this->user(['created_at' => now()->subDays(3)]);

        $this->artisan('users:prune-idle')->assertSuccessful();

        $this->assertNotNull(User::find($fresh->id), 'inside the grace period');
    }

    public function test_an_empty_playlist_does_not_protect_but_a_populated_one_does(): void
    {
        $empty = $this->user();
        $this->playlist($empty);

        $stocked = $this->user();
        $pl = $this->playlist($stocked);
        $store = new PlaylistStore($pl->id);
        $store->addManualChannel(['name' => 'Ch1', 'url' => 'http://example.com/1.ts', 'group' => 'G']);

        $this->artisan('users:prune-idle')->assertSuccessful();

        $this->assertNull(User::find($empty->id), 'an empty playlist is not "set up"');
        $this->assertNotNull(User::find($stocked->id), 'a playlist with channels protects the account');
    }

    // ---- maintenance:reap-stale ---------------------------------------------------------

    public function test_it_deletes_a_playlist_and_provider_nothing_has_touched(): void
    {
        $u = $this->user();
        $pl = $this->playlist($u);
        $pr = $this->provider($u);

        $this->artisan('maintenance:reap-stale')->assertSuccessful();

        $this->assertNull(Playlist::find($pl->id));
        $this->assertNull(Provider::find($pr->id));
    }

    public function test_reap_dry_run_changes_nothing(): void
    {
        $u = $this->user();
        $pl = $this->playlist($u);
        $pr = $this->provider($u);

        $this->artisan('maintenance:reap-stale --dry-run')->assertSuccessful();

        $this->assertNotNull(Playlist::find($pl->id));
        $this->assertNotNull(Provider::find($pr->id));
    }

    public function test_recent_access_protects_both(): void
    {
        $u = $this->user();
        $pl = $this->playlist($u, ['last_touch_at' => now()->subDay()]);
        $pr = $this->provider($u, ['last_touch_at' => now()->subDay()]);

        $this->artisan('maintenance:reap-stale')->assertSuccessful();

        $this->assertNotNull(Playlist::find($pl->id));
        $this->assertNotNull(Provider::find($pr->id));
    }

    public function test_a_provider_attached_to_a_surviving_playlist_is_never_deleted(): void
    {
        // The quiet failure this guards: the provider is stale by its own timestamp, but a
        // playlist someone still uses points at it. Deleting it would leave that playlist full of
        // "(missing channel)" rows serving nothing.
        $u = $this->user();
        $stale = $this->provider($u);                                   // stale provider
        $live = $this->playlist($u, ['last_touch_at' => now()]);        // but an active playlist
        $live->providers()->attach($stale->id);

        $this->artisan('maintenance:reap-stale')->assertSuccessful();

        $this->assertNotNull(Playlist::find($live->id));
        $this->assertNotNull(
            Provider::find($stale->id),
            'deleting a provider a live playlist points at would orphan its channels',
        );
    }

    public function test_a_provider_used_only_as_a_guide_source_is_also_protected(): void
    {
        $u = $this->user();
        $guide = $this->provider($u);
        $live = $this->playlist($u, ['last_touch_at' => now(), 'guide_provider_id' => $guide->id]);

        $this->artisan('maintenance:reap-stale')->assertSuccessful();

        $this->assertNotNull(Provider::find($guide->id), 'guide_provider_id is an attachment too');
        $this->assertNotNull(Playlist::find($live->id));
    }

    public function test_a_provider_is_collected_once_its_last_playlist_goes_in_the_same_run(): void
    {
        // Playlists are deleted first precisely so this does not need a second week.
        $u = $this->user();
        $pr = $this->provider($u);
        $pl = $this->playlist($u);           // also stale
        $pl->providers()->attach($pr->id);

        $this->artisan('maintenance:reap-stale')->assertSuccessful();

        $this->assertNull(Playlist::find($pl->id));
        $this->assertNull(Provider::find($pr->id), 'its only holder went in this run');
    }

    public function test_the_dry_run_preview_discounts_playlists_it_would_also_delete(): void
    {
        // Found by mutation testing: without this, a dry run reports the provider as "keeping —
        // still attached", because the holding playlist is still in the database at the time of
        // the check. The preview would then under-report what a real run deletes, which is the
        // one thing a preview of a destructive command has to get right.
        $u = $this->user();
        $pr = $this->provider($u);
        $pl = $this->playlist($u);            // stale too, so it would go in the same run
        $pl->providers()->attach($pr->id);

        $this->artisan('maintenance:reap-stale --dry-run')
            ->expectsOutputToContain("would delete provider {$pr->id}")
            ->assertSuccessful();

        // …and still actually deletes nothing.
        $this->assertNotNull(Provider::find($pr->id));
        $this->assertNotNull(Playlist::find($pl->id));
    }

    public function test_a_newly_created_playlist_is_not_reaped_before_anyone_can_use_it(): void
    {
        $u = $this->user();
        $fresh = $this->playlist($u, ['created_at' => now(), 'last_touch_at' => null]);

        $this->artisan('maintenance:reap-stale')->assertSuccessful();

        $this->assertNotNull(Playlist::find($fresh->id), 'never touched, but younger than the window');
    }
}
