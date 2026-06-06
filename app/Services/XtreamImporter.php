<?php

namespace App\Services;

use App\Models\Provider;

/**
 * Xtream Codes importer. Sequential + memory-flat, mirroring the rockmym3u
 * reference: get_live_categories -> groups, get_live_streams -> channels
 * (versioned mark-sweep), then xmltv.php streamed to disk and parsed into the
 * per-provider guide tables via an atomic reload. The XMLTV temp file is
 * downloaded fresh each run and deleted after parsing.
 */
class XtreamImporter
{
    private const SIZE_CAP = 300 * 1024 * 1024; // 300 MB hard cap, matching the reference

    /** scheme://host:port derived from the provider's panel URL. */
    public static function baseUrl(Provider $provider): string
    {
        $u = parse_url((string) $provider->url);
        $scheme = $u['scheme'] ?? 'http';
        $host   = $u['host'] ?? trim((string) $provider->url);
        $port   = isset($u['port']) ? ':' . $u['port'] : '';

        return rtrim("{$scheme}://{$host}{$port}", '/');
    }

    /** Map one get_live_streams entry to a channels-table row (existing m3u-shaped columns). */
    public static function mapStreamToChannel(array $s, array $catNameById, string $base, string $user, string $pass): array
    {
        $sid   = (string) ($s['stream_id'] ?? '');
        $catId = (string) ($s['category_id'] ?? '');
        $group = $catNameById[$catId] ?? '[Dummy]';
        if ($group === '') {
            $group = '[Dummy]';
        }
        $name = substr((string) ($s['name'] ?? 'dummy'), 0, 255);
        $epg  = (string) ($s['epg_channel_id'] ?? '');
        if ($epg === '' || strtolower($epg) === 'dummy') {
            $epg = $name;
        }

        return [
            'name'     => $name,
            'tvg_name' => $name,
            'tvg_id'   => substr($epg, 0, 125),
            'tvg_logo' => (string) ($s['stream_icon'] ?? ''),
            'group'    => $group,
            'type'     => (string) ($s['stream_type'] ?? 'Live'),
            'url'      => "{$base}/live/" . rawurlencode($user) . '/' . rawurlencode($pass) . "/{$sid}.ts",
            'ext'      => 'ts',
        ];
    }

    /** @return array{channels:int,groups:int,guide_channels:int,programmes:int,removed:int} */
    public function import(Provider $provider, string $version, callable $log): array
    {
        $base = self::baseUrl($provider);
        $user = (string) $provider->username;
        $pass = (string) $provider->password;
        if ($user === '' || $pass === '') {
            throw new \RuntimeException('Xtream provider needs a username and password.');
        }
        $api = "{$base}/player_api.php?username=" . rawurlencode($user) . '&password=' . rawurlencode($pass);

        // 1) Categories -> groups
        $log('Fetching live categories…');
        $cats = $this->fetchJson($api . '&action=get_live_categories');
        if (! is_array($cats)) {
            throw new \RuntimeException("This doesn't look like an Xtream API (bad categories response).");
        }
        $catNameById = [];
        foreach ($cats as $c) {
            if (isset($c['category_id'])) {
                $catNameById[(string) $c['category_id']] = (string) ($c['category_name'] ?? 'Unknown');
            }
        }

        $store = new ProviderStore($provider->id);
        $store->begin();
        $order = $store->nextGroupOrder();
        foreach ($catNameById as $title) {
            $store->upsertGroup($title !== '' ? $title : 'Unknown', $order, $version);
            $order += 10;
        }
        $store->upsertGroup('[Dummy]', $order, $version);
        $store->commit();

        // 2) Streams -> channels
        $log('Fetching live streams…');
        $streams = $this->fetchJson($api . '&action=get_live_streams');
        if (! is_array($streams) || count($streams) < 1) {
            throw new \RuntimeException("This doesn't look like an Xtream API (bad streams response).");
        }

        $n = 0;
        $store->begin();
        foreach ($streams as $s) {
            if (! is_array($s)) {
                continue;
            }
            $store->upsertChannel(self::mapStreamToChannel($s, $catNameById, $base, $user, $pass), $version);
            if (++$n % 2000 === 0) {
                $store->commit();
                usleep(50000);
                $store->begin();
            }
        }
        $store->commit();

        $removed = $store->sweep($version);
        $counts  = $store->counts();
        $log("Streams: {$n} processed (removed {$removed}); store holds {$counts['channels']} channels in {$counts['groups']} groups.");

        // 3) XMLTV guide -> guide tables (atomic reload)
        $guide = ['guide_channels' => 0, 'programmes' => 0, 'enhanced' => 0];
        $log('Downloading XMLTV guide (this can take a while)…');
        $xmltvUrl = "{$base}/xmltv.php?username=" . rawurlencode($user) . '&password=' . rawurlencode($pass);
        $path     = ProviderStore::xmltvPath($provider->id);
        @unlink($path);

        $bytes = 0;
        try {
            $bytes = $this->downloadXmltv($xmltvUrl, $path);
        } catch (\Throwable $e) {
            $log('Guide download skipped: ' . $e->getMessage());
        }

        if ($bytes > 0 && is_file($path)) {
            $minStop = now()->timestamp - 6 * 3600; // keep programmes ending within the last 6h or later
            $store->guideReloadBegin();
            XmltvParser::stream(
                $path,
                fn (array $c) => $store->guideChannel($c['tvg_id'], $c['display_name'], $c['icon']),
                fn (array $p) => $store->guideProgramme($p),
                $minStop,
            );
            $guide = $store->guideReloadCommit();
            @unlink($path);
            if ($provider->enhance_guide) {
                $enh = $store->enhanceGuideFromChannelNames();
                $guide['enhanced'] = $enh['added'];
                if ($enh['added'] > 0) {
                    $guide['programmes'] = $store->guideCounts()['programmes']; // filler removed, events inserted
                    $msg = "Guide enhanced: {$enh['added']} event/PPV channels given real guide data from their names";
                    if ($enh['cleared'] > 0) {
                        $msg .= "; replaced {$enh['cleared']} \"No EVENT Today\" filler entries";
                    }
                    $log($msg . '.');
                } elseif ($enh['examined'] > 0) {
                    $log("Guide enhance: {$enh['examined']} event channels found, but their events have already ended — kept existing listings.");
                }
            }
            $log("Guide: {$guide['guide_channels']} channels, {$guide['programmes']} programmes (stop ≥ now-6h).");
        } else {
            @unlink($path);
            $log('No XMLTV guide returned (provider may not supply one).');
        }

        return [
            'channels'       => $counts['channels'],
            'groups'         => $counts['groups'],
            'guide_channels' => $guide['guide_channels'],
            'programmes'     => $guide['programmes'],
            'enhanced'       => $guide['enhanced'] ?? 0,
            'removed'        => $removed,
        ];
    }

    /** GET an Xtream JSON endpoint (gzip, lax TLS, size-capped) and decode it. */
    private function fetchJson(string $url): mixed
    {
        $ch = curl_init();
        $bytes = 0;
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => (int) config('guidearr.feed.connect_timeout', 30),
            CURLOPT_TIMEOUT => (int) config('guidearr.feed.timeout', 1200),
            CURLOPT_LOW_SPEED_LIMIT => (int) config('guidearr.feed.low_speed_limit', 1024),
            CURLOPT_LOW_SPEED_TIME => (int) config('guidearr.feed.low_speed_time', 60),
            CURLOPT_HTTPHEADER => ['Accept: application/json, */*;q=0.8', 'User-Agent: Guidearr/1.x'],
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$bytes, &$buf) {
                $bytes += strlen($data);
                if ($bytes > self::SIZE_CAP) {
                    return -1; // abort: exceeds cap
                }
                $buf .= $data;

                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $err  = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err && $err !== CURLE_WRITE_ERROR) {
            throw new \RuntimeException('HTTP error fetching API: ' . curl_strerror($err));
        }
        if ($http >= 400) {
            throw new \RuntimeException("API returned HTTP {$http}.");
        }

        return json_decode($buf, true);
    }

    /** Stream the XMLTV guide straight to disk (gzip-decoded, size-capped). Returns bytes written. */
    private function downloadXmltv(string $url, string $path): int
    {
        @mkdir(dirname($path), 0777, true);
        $fo = @fopen($path, 'wb');
        if (! $fo) {
            throw new \RuntimeException('Could not open guide temp file for writing.');
        }

        $ch = curl_init();
        $bytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => (int) config('guidearr.feed.connect_timeout', 30),
            CURLOPT_TIMEOUT => (int) config('guidearr.feed.timeout', 1200),
            CURLOPT_LOW_SPEED_LIMIT => (int) config('guidearr.feed.low_speed_limit', 1024),
            CURLOPT_LOW_SPEED_TIME => (int) config('guidearr.feed.low_speed_time', 60),
            CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml, */*;q=0.8', 'User-Agent: Guidearr/1.x'],
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$bytes, $fo) {
                $len = strlen($data);
                $bytes += $len;
                if ($bytes > self::SIZE_CAP) {
                    return -1;
                }

                return fwrite($fo, $data) === false ? 0 : $len;
            },
        ]);
        curl_exec($ch);
        $err = curl_errno($ch);
        fclose($fo);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err && $err !== CURLE_WRITE_ERROR) {
            throw new \RuntimeException('HTTP error downloading guide: ' . curl_strerror($err));
        }
        if ($http >= 400) {
            throw new \RuntimeException("Guide endpoint returned HTTP {$http}.");
        }

        @chmod($path, 0666);

        return $bytes;
    }
}
