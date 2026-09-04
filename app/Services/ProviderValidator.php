<?php

namespace App\Services;

use App\Support\OutboundUrl;

use Illuminate\Support\Facades\Http;
use Throwable;

class ProviderValidator
{
    /** Bytes to sniff from the head of an m3u/xmltv source. */
    private const SNIFF_BYTES = 16384;

    /**
     * Validate that a source actually matches the declared type.
     *
     * @return array{ok:bool, message:string, timeshift:?string, bytes:int}
     */
    public function validate(string $type, ?string $url, ?string $username, ?string $password): array
    {
        try {
            return match ($type) {
                'manual' => $this->result(true, 'Manual provider — no validation needed.'),
                'm3u'    => $this->validateSignature($url, 'm3u'),
                'xmltv'  => $this->validateSignature($url, 'xmltv'),
                'xtream' => $this->validateXtream($url, $username, $password),
                default  => $this->result(false, "Unknown provider type '{$type}'."),
            };
        } catch (Throwable $e) {
            return $this->result(false, 'Validation error: ' . $e->getMessage());
        }
    }

    /** Pure check: does the sniffed head match the declared type? Testable without network. */
    public static function contentMatchesType(string $head, string $type): bool
    {
        $trimmed = ltrim($head);

        return match ($type) {
            // IPTV playlists always start with the extended-M3U header
            'm3u'   => str_starts_with($trimmed, '#EXTM3U'),
            // XMLTV guides are XML with a <tv> root element
            'xmltv' => str_starts_with($trimmed, '<?xml')
                       && (str_contains($head, '<tv ') || str_contains($head, '<tv>') || str_contains($head, '<!DOCTYPE tv')),
            default => false,
        };
    }

    /** Pure parse of an Xtream player_api response. Testable without network. */
    public static function parseXtream(?string $json): array
    {
        $data = json_decode((string) $json, true);
        if (! is_array($data)) {
            return ['ok' => false, 'timeshift' => null, 'status' => null];
        }
        $auth   = (int) data_get($data, 'user_info.auth', 0);
        $status = data_get($data, 'user_info.status');
        $tz     = data_get($data, 'server_info.timezone');

        return ['ok' => $auth === 1, 'timeshift' => $tz, 'status' => $status];
    }

    private function validateSignature(?string $url, string $type): array
    {
        if (! $this->isHttpUrl($url)) {
            return $this->result(false, 'A valid http(s) URL is required.');
        }
        if ($reason = OutboundUrl::reason($url)) {
            return $this->result(false, $reason);
        }

        try {
            $head = $this->fetchHead($url);
        } catch (\RuntimeException $e) {
            // A redirect pointed somewhere it may not go — say so rather than "could not fetch".
            return $this->result(false, $e->getMessage());
        }

        if ($head === null) {
            return $this->result(false, 'Could not fetch the URL (timeout, DNS, or connection refused).');
        }

        $label = $type === 'm3u' ? 'M3U (missing #EXTM3U header)' : 'XMLTV guide (missing <tv> root)';

        return self::contentMatchesType($head, $type)
            ? $this->result(true, strtoupper($type) . ' source looks valid.', null, strlen($head))
            : $this->result(false, "The URL did not look like a {$label}.", null, strlen($head));
    }

    private function validateXtream(?string $url, ?string $username, ?string $password): array
    {
        if (! $this->isHttpUrl($url)) {
            return $this->result(false, 'A valid http(s) server URL is required for Xtream.');
        }
        if ($reason = OutboundUrl::reason($url)) {
            return $this->result(false, $reason);
        }
        if (! $username || ! $password) {
            return $this->result(false, 'Xtream providers require a username and password.');
        }

        $base = rtrim(preg_replace('#/+$#', '', $url), '/');
        $api  = $base . '/player_api.php';

        $resp = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'Guidearr/1.0'])
            // Guzzle follows redirects itself, so the hop check rides on its callback: an
            // Xtream server answering with "302 http://127.0.0.1/" is otherwise a way past
            // the check above, which only ever sees the URL that was typed in.
            ->withOptions(['allow_redirects' => [
                'max' => OutboundUrl::maxRedirects(),
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) {
                    OutboundUrl::assertAllowed((string) $uri);
                },
            ]])
            ->get($api, ['username' => $username, 'password' => $password]);

        $parsed = self::parseXtream($resp->body());

        if (! $parsed['ok']) {
            return $this->result(false, 'Xtream authentication failed (no valid user_info.auth in response).');
        }

        $msg = 'Xtream login OK' . ($parsed['status'] ? " — account {$parsed['status']}." : '.');

        return $this->result(true, $msg, $parsed['timeshift'], strlen($resp->body()));
    }

    /**
     * Fetch only the head of a (potentially huge) file without downloading it all.
     *
     * Redirects are followed here rather than by PHP's http wrapper, so each hop can be
     * checked first — plenty of legitimate playlists redirect to a CDN, but so would an
     * attacker's server pointing the second hop at loopback.
     *
     * @throws \RuntimeException when a redirect leads somewhere it may not go
     */
    private function fetchHead(?string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 15,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects'   => 1,
                'header'        => "Range: bytes=0-" . (self::SNIFF_BYTES - 1) . "\r\nUser-Agent: Guidearr/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        for ($hop = 0, $max = OutboundUrl::maxRedirects(); ; $hop++) {
            $fh = @fopen($url, 'rb', false, $ctx);
            if ($fh === false) {
                return null;
            }

            // Set by the http wrapper in this scope for the request just made.
            $headers = $http_response_header ?? [];
            $head = @stream_get_contents($fh, self::SNIFF_BYTES);
            @fclose($fh);

            $status = 0;
            $location = null;
            foreach ($headers as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                    $status = (int) $m[1];      // last status line wins
                    $location = null;
                } elseif (stripos($h, 'location:') === 0) {
                    $location = trim(substr($h, 9));
                }
            }

            if ($status >= 300 && $status < 400 && $location !== null && $hop < $max) {
                $next = OutboundUrl::nextHop($location, $url);   // throws if disallowed
                if ($next === null) {
                    return $head === false ? null : $head;
                }
                $url = $next;

                continue;
            }

            return $head === false ? null : $head;
        }
    }

    private function isHttpUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST);
    }

    private function result(bool $ok, string $message, ?string $timeshift = null, int $bytes = 0): array
    {
        // Cap the timezone to the providers.timeshift column width — the value comes from an external,
        // user-supplied Xtream server, so a long/garbage value must never overflow the column (500).
        if ($timeshift !== null) {
            $timeshift = mb_substr($timeshift, 0, 64);
        }

        return ['ok' => $ok, 'message' => $message, 'timeshift' => $timeshift, 'bytes' => $bytes];
    }
}
