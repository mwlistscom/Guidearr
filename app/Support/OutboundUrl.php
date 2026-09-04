<?php

namespace App\Support;

/**
 * Guard for URLs the SERVER fetches on a user's behalf.
 *
 * Provider URLs are supplied by whoever adds the provider, and Guidearr then fetches them
 * itself — so without a check they are a server-side request forgery: a signed-up user can
 * point a provider at `http://127.0.0.1:9000/`, `http://db:3306/` or a LAN address and have
 * this host reach it. Registration is open by default, so "authenticated" is a low bar.
 *
 * It is not blind, either. The validator distinguishes "connection refused" from "fetched,
 * but did not look like an M3U" and reports the byte count, which is a working port scanner;
 * and anything whose first bytes resemble a playlist or XMLTV is imported and shown back.
 *
 * Two things have to hold, and the second is the one that is easy to miss:
 *
 *  - the host must not resolve into private or reserved space, checked across EVERY address
 *    it resolves to — a name with one public and one loopback A record is a bypass otherwise;
 *  - the check must be repeated on every redirect hop, because an attacker controls their own
 *    server and can answer the first request with `302 http://169.254.169.254/`. Callers turn
 *    curl's own redirect following OFF and re-validate each hop through nextHop().
 *
 * Operators who genuinely run a provider on their LAN can allow it back: OUTBOUND_ALLOW_HOSTS
 * for named hosts, or OUTBOUND_ALLOW_PRIVATE=true to drop the range check entirely.
 */
class OutboundUrl
{
    /**
     * Ranges filter_var()'s NO_PRIV_RANGE|NO_RES_RANGE pair does not reject, verified against
     * PHP 8.4: carrier-grade NAT (which several VPN and container networks sit inside), the
     * IETF protocol block, and the benchmarking block.
     *
     * @var list<array{0:string,1:int}>
     */
    private const EXTRA_DENY_V4 = [
        ['100.64.0.0', 10],
        ['192.0.0.0', 24],
        ['198.18.0.0', 15],
    ];

    /** Why this URL may not be fetched, or null when it may. */
    public static function reason(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'No URL was given.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'Only http and https URLs can be fetched.';
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return 'That URL has no host.';
        }

        $host = trim($host, '[]');

        if (self::allowlisted($host)) {
            return null;
        }

        if ((bool) config('guidearr.outbound.allow_private', false)) {
            return null;
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            return "Could not resolve {$host}.";
        }

        foreach ($ips as $ip) {
            if (self::isBlocked($ip)) {
                return "Refusing to fetch a private or reserved address ({$host} resolves to {$ip}).";
            }
        }

        return null;
    }

    public static function allowed(?string $url): bool
    {
        return self::reason($url) === null;
    }

    /** @throws \RuntimeException */
    public static function assertAllowed(?string $url): void
    {
        $reason = self::reason($url);

        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }
    }

    /**
     * Validate one redirect hop and return the URL to follow, or null to stop.
     *
     * Callers follow redirects by hand — curl's own following would reach the target before
     * anything could object. A relative Location is resolved against the URL that produced it.
     *
     * @throws \RuntimeException when the hop is not allowed
     */
    public static function nextHop(?string $location, string $from): ?string
    {
        $location = trim((string) $location);
        if ($location === '') {
            return null;
        }

        $next = self::absolutise($location, $from);
        self::assertAllowed($next);

        return $next;
    }

    public static function maxRedirects(): int
    {
        return max(0, (int) config('guidearr.outbound.max_redirects', 5));
    }

    /**
     * curl options every outbound fetch shares.
     *
     * FOLLOWLOCATION is off so hops can be checked. The protocol pins matter independently:
     * without them a redirect to another scheme is left to libcurl's defaults, which have
     * changed across versions and are not something to rely on.
     *
     * @return array<int,mixed>
     */
    public static function curlOptions(): array
    {
        return [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
    }

    /**
     * Run a prepared curl handle, following redirects by hand so every hop is checked.
     *
     * The handle must already carry curlOptions() (so curl does not follow anything itself).
     * $reset runs before each attempt, for callers that accumulate a buffer or a file and
     * must discard what a 3xx wrote. Returns the final HTTP status; the caller still checks
     * curl_errno() as it would have before.
     *
     * @param  \CurlHandle  $ch
     *
     * @throws \RuntimeException when a hop is not allowed, or there are too many
     */
    public static function execFollowing($ch, string $url, ?callable $reset = null): int
    {
        $location = null;

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$location) {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }

            return strlen($line);
        });

        $max = self::maxRedirects();

        for ($hop = 0; ; $hop++) {
            $location = null;
            if ($reset !== null) {
                $reset();
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_exec($ch);

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                return $status;
            }
            if ($status < 300 || $status >= 400 || $location === null) {
                return $status;
            }
            if ($hop >= $max) {
                throw new \RuntimeException('Too many redirects.');
            }

            $next = self::nextHop($location, $url);
            if ($next === null) {
                return $status;
            }

            $url = $next;
        }
    }

    private static function allowlisted(string $host): bool
    {
        $host = strtolower($host);

        foreach ((array) config('guidearr.outbound.allow_hosts', []) as $allowed) {
            if ($host === strtolower(trim((string) $allowed))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every address the host resolves to */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        // A name can hold both A and AAAA records, and gethostbynamel only returns the A
        // side — so an AAAA pointing at ::1 would sail past a v4-only check.
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (! empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isBlocked(string $ip): bool
    {
        // Catches loopback, link-local (169.254.169.254 included), private space, and the
        // ::ffff:127.0.0.1 style v4-mapped form.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $ip
            : (str_starts_with(strtolower($ip), '::ffff:') ? substr($ip, 7) : null);

        if ($v4 === null || ! filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($v4);
        if ($long === false) {
            return false;
        }

        foreach (self::EXTRA_DENY_V4 as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if ((ip2long($net) & $mask) === ($long & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve a possibly-relative Location against the URL it came from. */
    private static function absolutise(string $location, string $from): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $p = parse_url($from);
        $scheme = $p['scheme'] ?? 'http';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? ':'.$p['port'] : '';
        $base = "{$scheme}://{$host}{$port}";

        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }

        if (str_starts_with($location, '/')) {
            return $base.$location;
        }

        $dir = rtrim(dirname($p['path'] ?? '/'), '/');

        return "{$base}{$dir}/{$location}";
    }
}
