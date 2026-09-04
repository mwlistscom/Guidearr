<?php

namespace App\Services;

use App\Support\OutboundUrl;

/**
 * Streaming downloader: writes to disk chunk-by-chunk (never buffers the whole
 * file in memory), enforces a hard byte cap mid-stream, emulates a browser, and
 * times out rather than hanging forever.
 */
class M3uDownloader
{
    /** @return object{ok:bool, bytes:int, error:?string, file:string} */
    public function download(string $url, string $dest): object
    {
        $cfg = config('guidearr.feed');
        $out = (object) ['ok' => false, 'bytes' => 0, 'error' => null, 'file' => $dest];

        // This URL came from a user, and we are the ones fetching it. See App\Support\OutboundUrl.
        if ($reason = OutboundUrl::reason($url)) {
            $out->error = $reason;
            return $out;
        }

        $fo = @fopen($dest, 'wb');
        if (! $fo) {
            $out->error = 'Cannot open destination file for writing.';
            return $out;
        }

        $maxBytes = (int) $cfg['max_bytes'];
        $ch = curl_init();

        // A 3xx body is discarded rather than written, so a redirect never lands in the file.
        $callback = function ($ch, $data) use (&$out, $fo, $maxBytes) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 300 && $code < 400) {
                return strlen($data);
            }
            if ($code >= 400) {
                $out->error = "HTTP {$code}";
                return -1;
            }
            $written = fwrite($fo, $data);
            if ($written === false) {
                $out->error = 'File write error.';
                return -1;
            }
            $out->bytes += strlen($data);
            if ($out->bytes > $maxBytes) {
                $out->error = 'Source exceeds the maximum allowed size.';
                return -1;
            }
            return strlen($data);
        };

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_WRITEFUNCTION  => $callback,
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_CONNECTTIMEOUT => (int) $cfg['connect_timeout'],
            CURLOPT_TIMEOUT        => (int) $cfg['timeout'],
            CURLOPT_LOW_SPEED_LIMIT => (int) ($cfg['low_speed_limit'] ?? 1024),
            CURLOPT_LOW_SPEED_TIME  => (int) ($cfg['low_speed_time'] ?? 60),
            CURLOPT_SSL_VERIFYPEER => (bool) $cfg['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $cfg['verify_tls'] ? 2 : 0,
            CURLOPT_BUFFERSIZE     => 1024 * 256,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: max-age=0',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_USERAGENT      => $cfg['user_agent'],
        ] + OutboundUrl::curlOptions());

        // Redirects are followed by hand, checking each hop — letting curl follow them would
        // put the request on the wire before anything could object, and an attacker controls
        // their own server, so "302 to 169.254.169.254" is the obvious way around a check
        // that only ever looks at the URL that was typed in.
        try {
            OutboundUrl::execFollowing($ch, $url, function () use (&$out, $fo) {
                // A 3xx body is discarded by the write callback, but reset anyway so a
                // partial write on one hop cannot bleed into the next.
                $out->bytes = 0;
                ftruncate($fo, 0);
                rewind($fo);
            });
        } catch (\RuntimeException $e) {
            $out->error = $e->getMessage();
        }

        if ($out->error === null && curl_errno($ch)) {
            $out->error = curl_error($ch);
        }
        curl_close($ch);
        fclose($fo);

        $out->ok = $out->error === null && $out->bytes > 0;
        if (! $out->ok && $out->bytes === 0 && $out->error === null) {
            $out->error = 'Empty response.';
        }

        return $out;
    }
}
