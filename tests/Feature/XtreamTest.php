<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Services\ProviderStore;
use App\Services\XmltvParser;
use App\Services\XtreamImporter;
use Tests\TestCase;

class XtreamTest extends TestCase
{
    private function fixture(): string
    {
        $now = time();
        $fmt = fn ($t) => gmdate('YmdHis', $t) . ' +0000';
        $future = $now + 7200;
        $old = $now - 10 * 3600; // clearly older than now-6h

        $xml = '<?xml version="1.0" encoding="UTF-8"?><tv>'
            . '<channel id="CBC.ca"><display-name>CBC Toronto</display-name><icon src="http://x/cbc.png"/></channel>'
            . '<channel id="TVA.ca"><display-name>TVA</display-name></channel>'
            . '<programme start="' . $fmt($future) . '" stop="' . $fmt($future + 3600) . '" channel="CBC.ca">'
              . '<title>The News</title><sub-title>Episode 5</sub-title><desc>Nightly news.</desc>'
              . '<category>News</category><episode-num>S01E05</episode-num><icon src="http://x/news.png"/>'
              . '<date>2024</date><rating system="MPAA"><value>PG</value></rating></programme>'
            . '<programme start="' . $fmt($old) . '" stop="' . $fmt($old + 3600) . '" channel="TVA.ca"><title>Old</title></programme>'
            . '</tv>';

        $path = tempnam(sys_get_temp_dir(), 'xmltv') . '.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    public function test_parser_extracts_fields_and_honours_min_stop(): void
    {
        $path = $this->fixture();
        $channels = [];
        $programmes = [];
        $res = XmltvParser::stream(
            $path,
            function (array $c) use (&$channels) { $channels[] = $c; },
            function (array $p) use (&$programmes) { $programmes[] = $p; },
            time() - 6 * 3600,
        );
        @unlink($path);

        $this->assertSame(2, $res['channels']);
        $this->assertSame(1, $res['programmes']); // the -10h programme is dropped

        $prog = $programmes[0];
        $this->assertSame('CBC.ca', $prog['tvg_id']);
        $this->assertSame('The News', $prog['title']);
        $this->assertSame('Episode 5', $prog['sub_title']);
        $this->assertSame('News', $prog['category']);
        $this->assertSame('S01E05', $prog['episode_num']);
        $this->assertSame('2024', $prog['year']);
        $this->assertSame('PG', $prog['rating']);
        $this->assertSame('http://x/news.png', $prog['icon']);
        $this->assertGreaterThan(time(), $prog['stop']);
    }

    public function test_xmltv_timestamp_parsing(): void
    {
        $this->assertSame(1450490400, XmltvParser::ts('20151219020000 +0000'));
        // 04:00 at +0200 is the same instant as 02:00 UTC
        $this->assertSame(1450490400, XmltvParser::ts('20151219040000 +0200'));
        $this->assertSame(0, XmltvParser::ts(''));
    }

    public function test_guide_reload_swaps_atomically(): void
    {
        $pid = 99772;
        @unlink(ProviderStore::path($pid));
        $store = new ProviderStore($pid);
        $path = $this->fixture();

        $store->guideReloadBegin();
        XmltvParser::stream(
            $path,
            fn (array $c) => $store->guideChannel($c['tvg_id'], $c['display_name'], $c['icon']),
            fn (array $p) => $store->guideProgramme($p),
            time() - 6 * 3600,
        );
        $first = $store->guideReloadCommit();
        $this->assertSame(2, $first['guide_channels']);
        $this->assertSame(1, $first['programmes']);

        // reload with a smaller guide must REPLACE, not accumulate
        $store->guideReloadBegin();
        $store->guideChannel('ONLY.ca', 'Only', '');
        $second = $store->guideReloadCommit();
        $this->assertSame(1, $second['guide_channels']);
        $this->assertSame(0, $second['programmes']);

        @unlink($path);
        @unlink(ProviderStore::path($pid));
    }

    public function test_stream_mapping_and_base_url(): void
    {
        $p = new Provider(['url' => 'http://panel.example.com:8080/c', 'username' => 'u', 'password' => 'p']);
        $this->assertSame('http://panel.example.com:8080', XtreamImporter::baseUrl($p));

        $ch = XtreamImporter::mapStreamToChannel(
            ['name' => 'CBC HD', 'stream_id' => '123', 'category_id' => '5', 'stream_icon' => 'http://i/c.png', 'epg_channel_id' => 'CBC.ca', 'stream_type' => 'live'],
            ['5' => 'Canada'], 'http://panel.example.com:8080', 'u', 'p',
        );
        $this->assertSame('http://panel.example.com:8080/live/u/p/123.ts', $ch['url']);
        $this->assertSame('Canada', $ch['group']);
        $this->assertSame('CBC.ca', $ch['tvg_id']);

        // no category -> [Dummy]; missing epg id -> falls back to name
        $ch2 = XtreamImporter::mapStreamToChannel(['name' => 'NoCat', 'stream_id' => '9'], [], 'http://b', 'u', 'p');
        $this->assertSame('[Dummy]', $ch2['group']);
        $this->assertSame('NoCat', $ch2['tvg_id']);
    }
}
