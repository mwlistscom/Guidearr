<?php

namespace App\Services;

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

        $head = $this->fetchHead($url);
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
        if (! $username || ! $password) {
            return $this->result(false, 'Xtream providers require a username and password.');
        }

        $base = rtrim(preg_replace('#/+$#', '', $url), '/');
        $api  = $base . '/player_api.php';

        $resp = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'Guidearr/1.0'])
            ->get($api, ['username' => $username, 'password' => $password]);

        $parsed = self::parseXtream($resp->body());

        if (! $parsed['ok']) {
            return $this->result(false, 'Xtream authentication failed (no valid user_info.auth in response).');
        }

        $msg = 'Xtream login OK' . ($parsed['status'] ? " — account {$parsed['status']}." : '.');

        return $this->result(true, $msg, $parsed['timeshift'], strlen($resp->body()));
    }

    /** Fetch only the head of a (potentially huge) file without downloading it all. */
    private function fetchHead(?string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Range: bytes=0-" . (self::SNIFF_BYTES - 1) . "\r\nUser-Agent: Guidearr/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $fh = @fopen($url, 'rb', false, $ctx);
        if ($fh === false) {
            return null;
        }
        $head = @stream_get_contents($fh, self::SNIFF_BYTES);
        @fclose($fh);

        return $head === false ? null : $head;
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
        return ['ok' => $ok, 'message' => $message, 'timeshift' => $timeshift, 'bytes' => $bytes];
    }
}
