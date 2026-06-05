<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\Provider;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, unauthenticated serving endpoints, keyed by a playlist's cipher.
 * Ports the legacy m3u.php / tvg.php / strm.php behaviour onto Guidearr's data
 * model (per-playlist SQLite + per-provider guide store), honouring IP lock,
 * a rolling unique-IP rate limit, channel_start, #EXTGRP tags, and the
 * enabled/deleted filters. Routed without a .php extension so the Laravel
 * router handles them rather than nginx trying to exec a file.
 */
class PlaylistServeController extends Controller
{
    public function m3u(Request $request)
    {
        [$playlist, $deny] = $this->gate($request, 'm3u');
        if ($deny) { return $deny; }

        $this->touch($playlist);
        $rows = $this->effectiveChannels($playlist);
        $start = max(1, (int) ($playlist->channel_start ?: 100));
        $extgrp = (bool) $playlist->extgrp_tags;

        return $this->stream('application/x-mpegurl', function () use ($rows, $start, $extgrp) {
            echo "#EXTM3U\n";
            $n = $start;
            foreach ($rows as $r) {
                $url = (string) $r['url'];
                if ($url === '') { continue; }
                $name  = (string) ($r['name'] !== '' ? $r['name'] : ($r['tvg_name'] ?: 'Channel'));
                $group = (string) $r['group_title'];
                printf(
                    '#EXTINF:-1 tvg-chno="%s" tvg-id="%s" tvg-name="%s" tvg-logo="%s" group-title="%s",%s' . "\n",
                    $n,
                    $this->attr($r['tvg_id']),
                    $this->attr($r['tvg_name']),
                    $this->attr($r['tvg_logo']),
                    $this->attr($group),
                    $name
                );
                if ($extgrp && $group !== '') { echo '#EXTGRP:' . $group . "\n"; }
                echo $url . "\n";
                $n++;
            }
        });
    }

    public function strm(Request $request)
    {
        [$playlist, $deny] = $this->gate($request, 'json');
        if ($deny) { return $deny; }

        $this->touch($playlist);
        $rows = $this->effectiveChannels($playlist);

        return $this->stream('application/json; charset=utf-8', function () use ($rows) {
            echo '[';
            $comma = '';
            foreach ($rows as $r) {
                if ((string) $r['url'] === '') { continue; }
                echo $comma . json_encode([
                    'url'         => $r['url'],
                    'tvg_name'    => $r['tvg_name'] !== '' ? $r['tvg_name'] : $r['name'],
                    'group_title' => $r['group_title'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $comma = ",\n";
            }
            echo ']';
        });
    }

    public function epg(Request $request)
    {
        [$playlist, $deny] = $this->gate($request, 'xml');
        if ($deny) { return $deny; }

        $gid = (int) $playlist->guide_provider_id;
        if ($gid <= 0 || ! ProviderStore::exists($gid)) {
            return $this->xml('<?xml version="1.0" encoding="utf-8"?><tv></tv>');
        }

        $this->touch($playlist);
        if ($p = Provider::find($gid)) { $p->forceFill(['last_touch_at' => now()])->save(); }

        // Distinct tvg-ids actually present (enabled) in this playlist.
        $tvgIds = [];
        foreach ($this->effectiveChannels($playlist) as $r) {
            $t = (string) $r['tvg_id'];
            if ($t !== '') { $tvgIds[$t] = true; }
        }
        $tvgIds = array_keys($tvgIds);

        $store = new ProviderStore($gid);
        $channels = $store->guideChannelsForIds($tvgIds);
        $minStop = now()->timestamp - 6 * 3600;

        return $this->stream('application/xml; charset=utf-8', function () use ($store, $channels, $tvgIds, $minStop) {
            $w = new \XMLWriter();
            $w->openURI('php://output');
            $w->startDocument('1.0', 'utf-8');
            $w->setIndent(false);
            $w->startElement('tv');

            foreach ($channels as $c) {
                $w->startElement('channel');
                $w->writeAttribute('id', (string) $c['tvg_id']);
                $w->writeElement('display-name', (string) ($c['display_name'] ?: $c['tvg_id']));
                if (! empty($c['icon'])) {
                    $w->startElement('icon');
                    $w->writeAttribute('src', (string) $c['icon']);
                    $w->endElement();
                }
                $w->endElement();
                $w->flush();
            }

            $store->streamGuideProgrammesForIds($tvgIds, $minStop, function (array $p) use ($w) {
                $title = (strlen((string) $p['title']) < 2) ? (string) $p['tvg_id'] : (string) $p['title'];
                $w->startElement('programme');
                // start/stop are stored as UTC unix timestamps -> emit UTC.
                $w->writeAttribute('start', gmdate('YmdHis', (int) $p['start']) . ' +0000');
                $w->writeAttribute('stop', gmdate('YmdHis', (int) $p['stop']) . ' +0000');
                $w->writeAttribute('channel', (string) $p['tvg_id']);
                $w->writeElement('title', $title);
                if (strlen((string) $p['descr']) > 1) { $w->writeElement('desc', (string) $p['descr']); }
                $w->endElement();
                $w->flush();
            });

            $w->endElement(); // tv
            $w->endDocument();
            $w->flush();
        });
    }

    // ---- shared ----

    /** Resolve + authorise the request. Returns [Playlist, null] on success or [null, Response] on denial. */
    private function gate(Request $request, string $format): array
    {
        $key = preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('key', ''));
        if (strlen((string) $key) < 8) {
            return [null, $this->empty($format)];
        }

        $playlist = Playlist::where('cipher', $key)->where('enabled', true)->first();
        if (! $playlist || ! PlaylistStore::existsFor($playlist->id)) {
            return [null, $this->empty($format)];
        }

        $ip = (string) $request->ip();
        $locked = filter_var($playlist->iplock, FILTER_VALIDATE_IP);

        if ($locked) {
            if ($playlist->iplock !== $ip) {
                return [null, $this->denied($format)];
            }
            return [$playlist, null]; // locked IP skips the rate limit
        }

        if ($this->rateLimited($playlist->id, $ip)) {
            return [null, $this->tooMany($format)];
        }

        return [$playlist, null];
    }

    /** Rolling unique-IP limit. Returns true if this request pushes the playlist over the cap. */
    private function rateLimited(int $playlistId, string $ip): bool
    {
        $maxIps = \App\Support\Settings::serveMaxIps();
        $window = \App\Support\Settings::serveWindowHours();

        $now = now();
        DB::table('playlist_ip_log')->upsert(
            [['playlist_id' => $playlistId, 'ip' => $ip, 'last_seen' => $now]],
            ['playlist_id', 'ip'],
            ['last_seen' => $now]
        );

        $cutoff = Carbon::now()->subHours($window);
        DB::table('playlist_ip_log')
            ->where('playlist_id', $playlistId)
            ->where('last_seen', '<', $cutoff)
            ->delete();

        $count = DB::table('playlist_ip_log')
            ->where('playlist_id', $playlistId)
            ->where('last_seen', '>=', $cutoff)
            ->distinct()
            ->count('ip');

        return $count > $maxIps;
    }

    private function touch(Playlist $playlist): void
    {
        $playlist->forceFill(['last_touch_at' => now()])->saveQuietly();
    }

    /** Effective channel rows (playlist override wins over the provider's value), in display order. */
    private function effectiveChannels(Playlist $playlist): array
    {
        $store = new PlaylistStore($playlist->id);
        $rows  = $store->allForServe();

        $byProvider = [];
        foreach ($rows as $r) {
            if ((int) $r['provider_id'] > 0) { $byProvider[(int) $r['provider_id']][] = (int) $r['channel_id']; }
        }
        $data = [];
        foreach ($byProvider as $pid => $ids) {
            $data[$pid] = ProviderStore::exists($pid) ? (new ProviderStore($pid))->channelsByIds($ids) : [];
        }

        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['provider_id'];
            $src = $pid > 0 ? ($data[$pid][(int) $r['channel_id']] ?? null) : null;
            if ($pid > 0 && $src === null) { continue; } // pointer to a channel the provider no longer has
            $pick = function (string $k) use ($r, $src) {
                $v = $r[$k] ?? '';
                return ($v !== '' && $v !== null) ? $v : ($src[$k] ?? '');
            };
            $out[] = [
                'name'        => (string) $pick('name'),
                'url'         => (string) $pick('url'),
                'tvg_id'      => (string) $pick('tvg_id'),
                'tvg_name'    => (string) $pick('tvg_name'),
                'tvg_logo'    => (string) $pick('tvg_logo'),
                'group_title' => (string) ($r['group_title'] ?? ''),
            ];
        }

        return $out;
    }

    private function attr($v): string
    {
        return str_replace('"', '', (string) ($v ?? ''));
    }

    private function stream(string $contentType, callable $body): StreamedResponse
    {
        return response()->stream($body, 200, [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    private function xml(string $s)
    {
        return response($s, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function empty(string $format)
    {
        return match ($format) {
            'xml'  => $this->xml('<?xml version="1.0"?><tv>No Guide Data</tv>'),
            'json' => response('[]', 200, ['Content-Type' => 'application/json; charset=utf-8']),
            default => response("#EXTM3U", 200, ['Content-Type' => 'application/x-mpegurl']),
        };
    }

    private function denied(string $format)
    {
        return match ($format) {
            'xml'  => $this->xml('<?xml version="1.0"?><tv>Access Denied</tv>'),
            'json' => response(json_encode(['error' => 'Access Denied']), 200, ['Content-Type' => 'application/json']),
            default => response("#EXTM3U\n#EXTINF:-1,Access Denied\nhttp://0.0.0.0\n", 200, ['Content-Type' => 'application/x-mpegurl']),
        };
    }

    private function tooMany(string $format)
    {
        return match ($format) {
            'xml'  => $this->xml('<?xml version="1.0"?><tv>Too Many Devices - Contact Support</tv>'),
            'json' => response(json_encode(['error' => 'Too Many Devices - Contact Support']), 200, ['Content-Type' => 'application/json']),
            default => response("#EXTM3U\n#EXTINF:-1,Too Many Devices - Contact Support\nhttp://0.0.0.0\n", 200, ['Content-Type' => 'application/x-mpegurl']),
        };
    }
}
