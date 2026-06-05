<?php

namespace Tests\Feature;

use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_synthesizes_programmes_from_embedded_event_names(): void
    {
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
        $this->assertSame(4, $res['examined'], 'four no-guide channels with names should be examined');
        $this->assertSame(2, $res['added'], 'two event channels should be enhanced');

        // ESPN+ programme: title cleaned, start = US Eastern epoch (seconds floored).
        $espn = $store->guideProgrammesFor('ESPNplus.8', 0);
        $this->assertCount(1, $espn);
        $this->assertSame('The Pat McAfee Show', $espn[0]['title']);
        $expected = (new \DateTime('2026-06-04 12:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertSame($expected, (int) $espn[0]['start']);
        $this->assertSame($expected + 120 * 60, (int) $espn[0]['stop']);

        // SN+ programme: underscores normalized, backtick→apostrophe path exercised elsewhere.
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
        $store = new ProviderStore(779002);
        $store->guideReloadBegin();
        $store->guideChannel('ESPNplus.8', 'US (ESPN+ 008) | The Pat McAfee Show Jun 04 12:00PM ET (2026-06-04 12:00:05)', '');
        $store->guideReloadCommit();

        $this->assertSame(1, $store->enhanceGuideFromChannelNames()['added']);
        // Second pass: the channel now has a programme, so nothing new is added.
        $this->assertSame(0, $store->enhanceGuideFromChannelNames()['added']);
        $this->assertCount(1, $store->guideProgrammesFor('ESPNplus.8', 0));
    }

    public function test_replaces_no_event_filler_with_real_event(): void
    {
        $store = new ProviderStore(779003);
        $store->guideReloadBegin();

        // All-filler channel ("No EVENT Today") with a real event embedded in its name.
        $store->guideChannel('ESPN+021.dko', 'US (ESPN+ 021) | Milwaukee Brewers vs. Colorado Rockies Jun 05 8:00PM ET (2026-06-05 20:00:05)', '');
        foreach ([0, 4, 8] as $h) {
            $store->guideProgramme([
                'tvg_id' => 'ESPN+021.dko',
                'start' => 4102444800 + $h * 3600, 'stop' => 4102444800 + ($h + 4) * 3600,
                'timeshift' => '+0000', 'title' => 'No EVENT Today', 'sub_title' => '',
                'desc' => '', 'category' => '', 'episode_num' => '', 'icon' => '', 'year' => '', 'rating' => '', 'info' => null,
            ]);
        }

        // Channel with a genuine programme (+ a filler row) — must be left completely alone.
        $store->guideChannel('ESPN+099.dko', 'US (ESPN+ 099) | Some Big Game (2026-06-05 18:00:00)', '');
        $store->guideProgramme([
            'tvg_id' => 'ESPN+099.dko', 'start' => 4102444800, 'stop' => 4102448400,
            'timeshift' => '+0000', 'title' => 'Actual Scheduled Show', 'sub_title' => '',
            'desc' => '', 'category' => '', 'episode_num' => '', 'icon' => '', 'year' => '', 'rating' => '', 'info' => null,
        ]);
        $store->guideProgramme([
            'tvg_id' => 'ESPN+099.dko', 'start' => 4102448400, 'stop' => 4102452000,
            'timeshift' => '+0000', 'title' => 'No EVENT Today', 'sub_title' => '',
            'desc' => '', 'category' => '', 'episode_num' => '', 'icon' => '', 'year' => '', 'rating' => '', 'info' => null,
        ]);
        $store->guideReloadCommit();

        $res = $store->enhanceGuideFromChannelNames();
        $this->assertSame(3, $res['cleared'], 'the three filler rows on the all-filler channel are removed');
        $this->assertSame(1, $res['added']);

        // All-filler channel: filler gone, real event in its place.
        $p = $store->guideProgrammesFor('ESPN+021.dko', 0);
        $this->assertCount(1, $p);
        $this->assertSame('Milwaukee Brewers vs. Colorado Rockies', $p[0]['title']);
        $expected = (new \DateTime('2026-06-05 20:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        $this->assertSame($expected, (int) $p[0]['start']);

        // Real-schedule channel: untouched (keeps both its real show and its own filler row).
        $titles = array_column($store->guideProgrammesFor('ESPN+099.dko', 0), 'title');
        $this->assertContains('Actual Scheduled Show', $titles);
        $this->assertContains('No EVENT Today', $titles, 'channel with a real schedule is left alone');
    }
}
