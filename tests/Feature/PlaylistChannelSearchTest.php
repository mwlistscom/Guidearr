<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistChannelSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) { @unlink($f); }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) { @unlink($f); }
    }

    private function playlistWithChannels(User $u): Playlist
    {
        $p = Provider::create(['user_id' => $u->id, 'name' => 'Src', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        // several CNN variants (mixed case) + a non-match
        $s->upsertChannel(['name' => 'CNN US', 'url' => 'http://h/1.ts', 'group' => 'NEWS', 'tvg_id' => 'cnn.us', 'tvg_name' => 'CNN', 'tvg_logo' => ''], 'v1');
        $s->upsertChannel(['name' => 'CNN International', 'url' => 'http://h/2.ts', 'group' => 'NEWS', 'tvg_id' => 'cnn.intl', 'tvg_name' => 'CNN Intl', 'tvg_logo' => ''], 'v1');
        $s->upsertChannel(['name' => 'cnn español', 'url' => 'http://h/3.ts', 'group' => 'NEWS', 'tvg_id' => 'cnn.es', 'tvg_name' => 'CNN ES', 'tvg_logo' => ''], 'v1');
        $s->upsertChannel(['name' => 'ESPN', 'url' => 'http://h/4.ts', 'group' => 'SPORTS', 'tvg_id' => 'espn', 'tvg_name' => 'ESPN', 'tvg_logo' => ''], 'v1');
        $s->commit();
        $s->begin();
        $o = $s->nextGroupOrder();
        foreach (['NEWS', 'SPORTS'] as $g) { $s->upsertGroup($g, $o, 'v1'); $o += 10; }
        $s->commit();

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'searchkey0001', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    public function test_search_matches_hydrated_provider_names_case_insensitively(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlistWithChannels($u);

        $json = $this->actingAs($u)
            ->getJson(route('playlists.channels', ['playlist' => $pl->id, 'search' => 'cnn']))
            ->assertOk()->json();

        // 3 CNN variants found, ESPN excluded — even though the names live in the provider store
        $this->assertSame(3, $json['total']);
        $names = array_column($json['data'], 'name');
        $this->assertContains('CNN US', $names);
        $this->assertContains('cnn español', $names);
        $this->assertNotContains('ESPN', $names);
    }

    public function test_uppercase_query_also_matches(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlistWithChannels($u);

        $json = $this->actingAs($u)
            ->getJson(route('playlists.channels', ['playlist' => $pl->id, 'search' => 'CNN']))
            ->assertOk()->json();

        $this->assertSame(3, $json['total']);
    }

    public function test_no_search_returns_all_channels(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->playlistWithChannels($u);

        $json = $this->actingAs($u)
            ->getJson(route('playlists.channels', ['playlist' => $pl->id]))
            ->assertOk()->json();

        $this->assertSame(4, $json['total']);
    }
}
