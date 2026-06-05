<?php

namespace Tests\Feature;

use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GuideEnhanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_synthesizes_programmes_from_embedded_event_names(): void
    {
        // Freeze "now" before the embedded events so they count as current/upcoming.
        Carbon::setTestNow(Carbon::parse('2026-06-04 06:00:00', 'America/New_York'));

        $store = new ProviderStore(779001);
        $store->guideReloadBegin();

        // Event channels with NO programmes — should be enhanced.
        $store->guideChannel('ESPNplus.8', 'US (ESPN+ 008) | The Pat McAfee Show Jun 04 12:00PM ET (2026-06-04 12:00:05)', '');
        $store->guideChannel('SNplus.13',  'CA (SN+ 013) | Vegas @ Carolina _ Game 2 (2026-06-04 19:30:00)', '');

        // Sentinel/idle slot (far-future date, empty title) — must be skipped.
        $store->guideChannel('SNplus.26',  'CA (SN+ 026) |  (2098-12-31 08:00:01)', '');

        // A channel with no embedded time — nothing to synthesize.
        $store->guideChannel('plain.us',   'Just A Channel', '');

        // A channel that already HAS a programme — must be left untouched.
        $store->guideChannel('cnn.us', 'CNN', '');
        $store->guideProgramme([
            'tvg_id' => 'cnn.us', 'start' => 4102444800, 'stop' => 4102448400,
            'timeshift' => '+0000', 'title' => 'Existing Show', 'sub_title' => '',
            'desc' => '', 'category' => '', 'episode_num' => '', 'icon' => '',
            'year' => '', 'rating' => '', 'info' => null,
        ]);
        $store->guideReloadCommit();

        $res = $store->enhanceGuideFromChannelNames();
        $this->assertSame(2, $res['examined'], 'two channels carry a parseable event');
        $this->assertSame(2, $res['added'], 'two event channels should be enhanced');

        // ESPN+ programme: title cleaned, start = US Eastern epoch (seconds floored).
        $espn = $store->guideProgrammesFor('ESPNplus.8', 0);
        $this->assertCount(1, $espn);
        $this->assertSame('The Pat McAfee Show', $espn[0]['title']);
        $expected = (new \DateTime('2026-06-04 12:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertSame($expected, (int) $espn[0]['start']);
        $this->assertSame($expected + 180 * 60, (int) $espn[0]['stop']);

        // SN+ programme: underscores normalized.
        $sn = $store->guideProgrammesFor('SNplus.13', 0);
        $this->assertCount(1, $sn);
        $this->assertSame('Vegas @ Carolina Game 2', $sn[0]['title']);

        // Sentinel and no-time channels stay empty.
        $this->assertCount(0, $store->guideProgrammesFor('SNplus.26', 0));
        $this->assertCount(0, $store->guideProgrammesFor('plain.us', 0));

        // Existing guide channel untouched (still exactly its one programme).
        $this->assertCount(1, $store->guideProgrammesFor('cnn.us', 0));
    }

    public function test_enhance_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04 06:00:00', 'America/New_York'));

        $store = new ProviderStore(779002);
        $store->guideReloadBegin();
        $store->guideChannel('ESPNplus.8', 'US (ESPN+ 008) | The Pat McAfee Show Jun 04 12:00PM ET (2026-06-04 12:00:05)', '');
        $store->guideReloadCommit();

        $this->assertSame(1, $store->enhanceGuideFromChannelNames()['added']);
        // Second pass: the channel now has a programme, so nothing new is added.
        $this->assertSame(0, $store->enhanceGuideFromChannelNames()['added']);
        $this->assertCount(1, $store->guideProgrammesFor('ESPNplus.8', 0));
    }

    public function test_replaces_filler_for_live_event_but_keeps_it_for_stale_event(): void
    {
        // "Now" is mid-afternoon Jun 5: the Brewers game (8PM) is upcoming; the Jun 4 game is over.
        Carbon::setTestNow(Carbon::parse('2026-06-05 14:00:00', 'America/New_York'));

        $store = new ProviderStore(779003);
        $store->guideReloadBegin();

        // LIVE/upcoming event, all filler -> filler replaced with the real event.
        $store->guideChannel('ESPN+021.dko', 'US (ESPN+ 021) | Milwaukee Brewers vs. Colorado Rockies Jun 05 8:00PM ET (2026-06-05 20:00:05)', '');
        // STALE event (yesterday), all filler -> filler KEPT so the channel stays visible.
        $store->guideChannel('ESPN+020.dko', 'US (ESPN+ 020) | Upper Valley Nighthawks vs. Sanford Mainers Jun 04 6:30PM ET (2026-06-04 18:30:05)', '');

        foreach (['ESPN+021.dko', 'ESPN+020.dko'] as $cid) {
            foreach ([0, 4, 8] as $h) {
                $store->guideProgramme([
                    'tvg_id' => $cid,
                    'start' => 4102444800 + $h * 3600, 'stop' => 4102444800 + ($h + 4) * 3600,
                    'timeshift' => '+0000', 'title' => 'No EVENT Today', 'sub_title' => '',
                    'desc' => '', 'category' => '', 'episode_num' => '', 'icon' => '', 'year' => '', 'rating' => '', 'info' => null,
                ]);
            }
        }
        $store->guideReloadCommit();

        $res = $store->enhanceGuideFromChannelNames();
        $this->assertSame(3, $res['cleared'], 'only the live channel loses its filler');
        $this->assertSame(1, $res['added']);

        // Live channel: filler gone, real event in place.
        $live = $store->guideProgrammesFor('ESPN+021.dko', 0);
        $this->assertCount(1, $live);
        $this->assertSame('Milwaukee Brewers vs. Colorado Rockies', $live[0]['title']);

        // Stale channel: untouched, still has its three filler rows (channel stays in the guide).
        $stale = $store->guideProgrammesFor('ESPN+020.dko', 0);
        $this->assertCount(3, $stale);
        $this->assertSame('No EVENT Today', $stale[0]['title']);
    }

    public function test_channel_name_overrides_stale_guide_xml_and_strips_trailing_id(): void
    {
        // "Now" mid-afternoon Jun 5: the channel-name event (8PM) is upcoming.
        Carbon::setTestNow(Carbon::parse('2026-06-05 14:00:00', 'America/New_York'));

        $store = new ProviderStore(779004);
        $store->guideReloadBegin();
        // Guide XML lags: stale Jun 4 event for this tvg_id, all filler.
        $store->guideChannel('ESPN+021.dko', 'US (ESPN+ 021) | Upper Valley Nighthawks vs. Sanford Mainers Jun 04 6:30PM ET (2026-06-04 18:30:05)', '');
        foreach ([0, 4, 8] as $h) {
            $store->guideProgramme([
                'tvg_id' => 'ESPN+021.dko',
                'start' => 4102444800 + $h * 3600, 'stop' => 4102444800 + ($h + 4) * 3600,
                'timeshift' => '+0000', 'title' => 'No EVENT Today', 'sub_title' => '',
                'desc' => '', 'category' => '', 'episode_num' => '', 'icon' => '', 'year' => '', 'rating' => '', 'info' => null,
            ]);
        }
        $store->guideReloadCommit();

        // Fresh live channel name (with trailing stream id) for the SAME tvg_id.
        $store->addChannel([
            'tvg_id' => 'ESPN+021.dko',
            'name' => 'US (ESPN+ 021) | Milwaukee Brewers vs. Colorado Rockies Jun 05 8:00PM ET (2026-06-05 20:00:05) 1395',
            'url' => 'http://example/espn21',
        ]);

        $res = $store->enhanceGuideFromChannelNames();
        $this->assertSame(1, $res['added'], 'fresh channel-name event should win over stale guide xml');
        $this->assertSame(3, $res['cleared']);

        $rows = $store->guideProgrammesFor('ESPN+021.dko', 0);
        $this->assertCount(1, $rows);
        // Title cleaned, trailing "1395" dropped.
        $this->assertSame('Milwaukee Brewers vs. Colorado Rockies', $rows[0]['title']);
        $expected = (new \DateTime('2026-06-05 20:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertSame($expected, (int) $rows[0]['start']);
    }
}
