<?php

namespace App\Services;

use App\Models\Provider;

/**
 * Downloads an m3u provider's separate XMLTV/EPG URL and loads it into the
 * per-provider guide tables via the same atomic reload the Xtream importer uses.
 *
 * Mirrors the rockmym3u m3uguidedownload.php protections: streamed to disk
 * (never buffered), gzip files transparently decompressed, an HTML/garbage
 * guard before parsing, and a fault-tolerant sequential parse that runs to the
 * end even when individual nodes are malformed. A guide failure never fails the
 * channel ingest — channels are already in the store; we just log and skip.
 */
class M3uGuideImporter
{
    /** @return array{guide_channels?:int,programmes?:int,skipped?:string} */
    public function importGuide(Provider $provider, string $version, callable $log): array
    {
        $url = trim((string) $provider->epg_url);
        if ($url === '') {
            return ['skipped' => 'no EPG URL'];
        }

        $dir = storage_path('app/feeds');
        @mkdir($dir, 0777, true);
        $dl = "{$dir}/epg_{$provider->id}_{$version}.dl";

        $log('Downloading EPG guide…');
        $res = (new M3uDownloader())->download($url, $dl);
        if (! $res->ok) {
            @unlink($dl);
            $log('EPG download failed: ' . $res->error . ' — keeping channels, skipping guide.');

            return ['skipped' => 'download failed'];
        }
        $log(number_format($res->bytes) . ' bytes downloaded.');

        $xmlPath = ProviderStore::xmltvPath($provider->id);
        @unlink($xmlPath);

        // Transparently decompress .xml.gz (static gzipped files curl won't auto-inflate).
        if (self::isGzip($dl)) {
            if (! self::gunzipFile($dl, $xmlPath)) {
                @unlink($dl);
                @unlink($xmlPath);
                $log('EPG looked gzipped but could not be decompressed — skipping guide.');

                return ['skipped' => 'gunzip failed'];
            }
            @unlink($dl);
        } else {
            @rename($dl, $xmlPath);
        }

        // HTML/garbage guard — never feed a login/error page into the parser.
        if (! XmltvParser::looksLikeXml($xmlPath)) {
            @unlink($xmlPath);
            $log('EPG source is not XMLTV (HTML/web page detected) — keeping channels, skipping guide.');

            return ['skipped' => 'not XMLTV'];
        }

        $store   = new ProviderStore($provider->id);
        $minStop = now()->timestamp - 6 * 3600; // keep programmes ending within the last 6h or later

        $store->guideReloadBegin();
        XmltvParser::stream(
            $xmlPath,
            fn (array $c) => $store->guideChannel($c['tvg_id'], $c['display_name'], $c['icon']),
            fn (array $p) => $store->guideProgramme($p),
            $minStop,
        );
        $guide = $store->guideReloadCommit();
        @unlink($xmlPath);

        $log("Guide: {$guide['guide_channels']} channels, {$guide['programmes']} programmes (stop ≥ now-6h).");

        return $guide;
    }

    private static function isGzip(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (! $h) { return false; }
        $magic = (string) fread($h, 2);
        fclose($h);

        return strlen($magic) === 2 && ord($magic[0]) === 0x1f && ord($magic[1]) === 0x8b;
    }

    /** Streamed gunzip (never holds the whole file in memory). */
    private static function gunzipFile(string $src, string $dst): bool
    {
        $in = @gzopen($src, 'rb');
        if (! $in) { return false; }
        $out = @fopen($dst, 'wb');
        if (! $out) { gzclose($in); return false; }
        while (! gzeof($in)) {
            $buf = gzread($in, 1 << 20);
            if ($buf === false) { break; }
            fwrite($out, $buf);
        }
        gzclose($in);
        fclose($out);
        @chmod($dst, 0666);

        return true;
    }
}
