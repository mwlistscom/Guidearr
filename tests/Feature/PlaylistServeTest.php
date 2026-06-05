<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistServeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) { @unlink($f); }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) { @unlink($f); }
        @unlink(storage_path('app/settings/app.json'));
    }

    private function buildPlaylist(User $u, ?int $guideId = null): Playlist
    {
        $p = Provider::create(['user_id' => $u->id, 'name' => 'Src', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        $s->upsertChannel(['name' => 'CNN US', 'url' => 'http://h/cnn.ts', 'group' => 'NEWS', 'tvg_id' => 'cnn.us', 'tvg_name' => 'CNN', 'tvg_logo' => 'http://l/cnn.png'], 'v1');
        $s->upsertChannel(['name' => 'ESPN US', 'url' => 'http://h/espn.ts', 'group' => 'SPORTS', 'tvg_id' => 'espn.us', 'tvg_name' => 'ESPN', 'tvg_logo' => 'http://l/espn.png'], 'v1');
        $s->commit();
        $s->begin();
        $o = $s->nextGroupOrder();
        foreach (['NEWS', 'SPORTS'] as $g) { $s->upsertGroup($g, $o, 'v1'); $o += 10; }
        $s->commit();

        $pl = Playlist::create([
            'user_id' => $u->id, 'name' => 'PL', 'cipher' => 'serveKey1234',
            'channel_start' => 100, 'enabled' => true, 'guide_provider_id' => $guideId,
        ]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        return $pl;
    }

    public function test_m3u_serves_channels_with_numbering(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $this->buildPlaylist($u);

        $body = $this->get('/m3u?key=serveKey1234')->assertOk()->streamedContent();
        $this->assertStringContainsString('#EXTM3U', $body);
        $this->assertStringContainsString('tvg-chno="100"', $body);
        $this->assertStringContainsString('tvg-id="cnn.us"', $body);
        $this->assertStringContainsString('http://h/cnn.ts', $body);
    }

    public function test_strm_serves_json(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $this->buildPlaylist($u);

        $body = $this->get('/strm?key=serveKey1234')->assertOk()->streamedContent();
        $arr = json_decode($body, true);
        $this->assertIsArray($arr);
        $this->assertSame('http://h/cnn.ts', $arr[0]['url']);
        $this->assertSame('NEWS', $arr[0]['group_title']);
    }

    public function test_epg_serves_guide_for_playlist_channels(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $gp = Provider::create(['user_id' => $u->id, 'name' => 'EPG', 'type' => 'xmltv', 'url' => 'http://h/epg.xml', 'enabled' => true, 'refresh_hour' => 2]);
        $gs = new ProviderStore($gp->id);
        $gs->guideReloadBegin();
        $gs->guideChannel('cnn.us', 'CNN', 'http://l/cnn.png');
        $gs->guideProgramme(['tvg_id' => 'cnn.us', 'start' => 4102444800, 'stop' => 4102448400, 'timeshift' => '+0000', 'title' => 'Future News', 'sub_title' => '', 'desc' => 'desc', 'category' => 'News', 'episode_num' => '', 'icon' => '', 'year' => '', 'rating' => '', 'info' => null]);
        $gs->guideReloadCommit();

        $this->buildPlaylist($u, $gp->id);

        $body = $this->get('/epg?key=serveKey1234')->assertOk()->streamedContent();
        $this->assertStringContainsString('<channel id="cnn.us">', $body);
        $this->assertStringContainsString('Future News', $body);
        $this->assertStringContainsString('<programme', $body);
    }

    public function test_bad_key_returns_empty(): void
    {
        $this->assertStringNotContainsString("#EXTINF", $this->get("/m3u?key=nope")->getContent());
        $this->assertSame("[]", $this->get("/strm?key=doesnotexist")->getContent());
    }

    public function test_ip_lock_denies_other_ips(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $pl = $this->buildPlaylist($u);
        $pl->forceFill(['iplock' => '203.0.113.7'])->save(); // not the test client IP (127.0.0.1)

        $body = $this->get("/m3u?key=serveKey1234")->getContent();
        $this->assertStringContainsString("Access Denied", $body);
    }
    public function test_rate_limit_honours_configured_max(): void
    {
        \App\Support\Settings::set('serve_max_ips', 1);
        $u = User::factory()->create(['email_verified_at' => now()]);
        $this->buildPlaylist($u);

        // first IP ok
        $this->get('/m3u?key=serveKey1234')->assertOk();
        // second distinct IP pushes over the cap of 1 -> throttled
        $body = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->get('/m3u?key=serveKey1234')->getContent();
        $this->assertStringContainsString('Too Many Devices', $body);

        \App\Support\Settings::set('serve_max_ips', 10);
    }
}
