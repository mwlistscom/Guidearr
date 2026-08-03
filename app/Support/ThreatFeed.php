<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Builds a plain-text IP blocklist from the nginx access log, for a firewall
 * (pfBlockerNG and friends) to consume as a custom IPv4/IPv6 list.
 *
 * Only requests that are unambiguously hostile count — a scanner asking for
 * /.env, POSTing to a Joomla upload task that does not exist here, or probing
 * /wp-login.php. Those all end in 403/404/405, so a hit here means "this IP
 * tried something that has no legitimate interpretation", never "this IP made
 * a mistake". An IP that has ever successfully pulled a playlist is protected
 * outright, whatever else it did.
 */
final class ThreatFeed
{
    /**
     * Where the generated feed is cached. The HTTP route serves this file
     * verbatim so a polling firewall never triggers a log parse.
     */
    public static function path(): string
    {
        return storage_path('app/threat-feed.txt');
    }

    /**
     * Access-log line: IP, timestamp, method, path, status.
     * Matches the `main` format in docker/nginx.conf (combined + X-Forwarded-For).
     */
    private const LINE = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) (\S+)[^"]*" (\d{3}) /';

    /**
     * Serving a playlist to a real client. An IP that got one of these is a
     * customer's set-top box or player and must never end up on the list.
     */
    private const SERVE = '#^/(m3u|epg|strm)(\?|$)#';

    /**
     * Paths that only ever come from a scanner. Grouped by the status the app
     * answers with, so a future route that legitimately 404s cannot leak in.
     */
    private const HOSTILE = [
        // Dotfile / secret harvesting: nginx denies these outright.
        403 => '#/\.(env|git|ssh|aws|npmrc|docker|config|gitlab|github|hg|svn|bash|htpasswd)#i',
        // Exploit POSTs against CMSes we do not run.
        405 => '#(option=com_|rest_route|auto_prepend_file|wp-json|/wp-admin|allow_url_include)#i',
        // Classic scanner paths.
        404 => '#(wp-login|wp-admin|wp-content|wp-includes|xmlrpc|phpmyadmin|/administrator|actuator|eval-stdin|cgi-bin|/solr|/druid|/boaform|EWS/Exchange|autodiscover|/telescope|_ignition|\.(php|asp|aspx|jsp|cgi)(\?|$))#i',
        // Encoded traversal that nginx rejects before routing.
        400 => '#(\.\./|%2e%2e|\.%2e|/bin/sh)#i',
    ];

    /**
     * Parse one access-log line.
     *
     * @return array{ip: string, at: DateTimeImmutable, path: string, status: int}|null
     */
    public static function parseLine(string $line): ?array
    {
        if (! preg_match(self::LINE, $line, $m)) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[2]);

        if ($at === false) {
            return null;
        }

        return [
            'ip' => $m[1],
            'at' => $at,
            'path' => $m[4],
            'status' => (int) $m[5],
        ];
    }

    /**
     * Did this request serve playlist data to a real client?
     */
    public static function isServe(string $path, int $status): bool
    {
        return ($status === 200 || $status === 206)
            && preg_match(self::SERVE, $path) === 1;
    }

    /**
     * Is this request hostile beyond reasonable doubt?
     */
    public static function isHostile(string $path, int $status): bool
    {
        $pattern = self::HOSTILE[$status] ?? null;

        return $pattern !== null && preg_match($pattern, $path) === 1;
    }

    /**
     * Tally hostile hits per IP over a window, and note which IPs are protected.
     *
     * @param  iterable<string>  $lines
     * @return array{hits: array<string, int>, protected: array<string, true>, scanned: int}
     */
    public static function collect(iterable $lines, DateTimeInterface $since): array
    {
        $hits = [];
        $protected = [];
        $scanned = 0;

        foreach ($lines as $line) {
            $row = self::parseLine($line);

            if ($row === null || $row['at'] < $since) {
                continue;
            }

            $scanned++;

            // Protection is evaluated over the whole window regardless of hit count:
            // one successful playlist pull outweighs any number of 404s from the
            // same address (CGNAT, a customer behind a scanned-through relay).
            if (self::isServe($row['path'], $row['status'])) {
                $protected[$row['ip']] = true;

                continue;
            }

            if (self::isHostile($row['path'], $row['status'])) {
                $hits[$row['ip']] = ($hits[$row['ip']] ?? 0) + 1;
            }
        }

        return ['hits' => $hits, 'protected' => $protected, 'scanned' => $scanned];
    }

    /**
     * Reduce a tally to the addresses that belong on the list.
     *
     * @param  array{hits: array<string, int>, protected: array<string, true>, scanned: int}  $tally
     * @param  list<string>  $allowlist  Literal IPs or CIDRs never to emit.
     * @return array<string, int> ip => hits, most hits first
     */
    public static function select(array $tally, int $minHits, int $maxEntries, array $allowlist = []): array
    {
        $out = [];

        foreach ($tally['hits'] as $ip => $hits) {
            if ($hits < $minHits || isset($tally['protected'][$ip])) {
                continue;
            }

            // Never emit anything non-routable: private, loopback, link-local and
            // reserved space. On this deployment the reverse proxy and the health
            // checks live there, and blocking them takes the site down.
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }

            if (self::allowlisted($ip, $allowlist)) {
                continue;
            }

            $out[$ip] = $hits;
        }

        arsort($out);

        return array_slice($out, 0, max(0, $maxEntries), true);
    }

    /**
     * Is $ip covered by a literal address or CIDR in $allowlist?
     *
     * @param  list<string>  $allowlist
     */
    public static function allowlisted(string $ip, array $allowlist): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        foreach ($allowlist as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (! str_contains($entry, '/')) {
                if (@inet_pton($entry) === $packed) {
                    return true;
                }

                continue;
            }

            [$net, $bits] = explode('/', $entry, 2);
            $netPacked = @inet_pton($net);

            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }

            $bits = (int) $bits;
            $whole = intdiv($bits, 8);
            $rest = $bits % 8;

            if ($whole > 0 && strncmp($packed, $netPacked, $whole) !== 0) {
                continue;
            }

            if ($rest === 0) {
                return true;
            }

            $mask = chr((0xFF << (8 - $rest)) & 0xFF);

            if (($packed[$whole] & $mask) === ($netPacked[$whole] & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stream every access-log line, current and rotated. Gzipped rotations are read
     * through the zlib wrapper when it is available.
     *
     * @return iterable<string>
     */
    public static function logLines(): iterable
    {
        foreach (glob(storage_path('logs/nginx-access.log*')) ?: [] as $file) {
            $gz = str_ends_with($file, '.gz');

            if ($gz && ! in_array('compress.zlib', stream_get_wrappers(), true)) {
                continue;
            }

            $handle = @fopen($gz ? 'compress.zlib://'.$file : $file, 'r');

            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Parse the logs and write the feed file.
     *
     * @return array{listed: int, scanned: int, protected: int}
     */
    public static function rebuild(): array
    {
        $cfg = config('guidearr.threat_feed');
        $windowDays = max(1, (int) $cfg['window_days']);
        $minHits = Settings::threatFeedMinHits();

        $since = (new DateTimeImmutable)->modify("-{$windowDays} days");
        $tally = self::collect(self::logLines(), $since);
        $ips = self::select($tally, $minHits, max(0, (int) $cfg['max_entries']), $cfg['allowlist']);

        $path = self::path();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, self::render($ips));

        return [
            'listed' => count($ips),
            'scanned' => $tally['scanned'],
            'protected' => count($tally['protected']),
        ];
    }

    /**
     * Rebuild only if the cached file is missing or stale. This is what lets the very
     * first fetch — on a fresh install or straight after an upgrade — return real data
     * without anyone running a command. The lock keeps a polling firewall (or several)
     * from triggering concurrent parses.
     */
    public static function ensureFresh(): void
    {
        $path = self::path();
        $maxAge = max(60, (int) config('guidearr.threat_feed.min_rebuild_seconds'));

        if (is_file($path) && (time() - (int) @filemtime($path)) < $maxAge) {
            return;
        }

        $lock = Cache::lock('threat-feed-rebuild', 300);

        if (! $lock->get()) {
            return;
        }

        try {
            self::rebuild();
        } finally {
            $lock->release();
        }
    }

    /** When the feed file was last written, or null if it has never been built. */
    public static function generatedAt(): ?DateTimeImmutable
    {
        $mtime = @filemtime(self::path());

        return $mtime === false ? null : (new DateTimeImmutable)->setTimestamp($mtime);
    }

    /** How many addresses the current feed file lists. */
    public static function entryCount(): int
    {
        $path = self::path();

        if (! is_file($path)) {
            return 0;
        }

        $n = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_starts_with($line, '#')) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Render the firewall-facing document: one bare IP address per line, nothing else.
     *
     * No header, no comments. pfBlockerNG's parser strips `#` lines, but plenty of tools
     * that consume a URL list do not, and a stray comment is the difference between a
     * working source and a silently empty one. Everything the header used to carry — the
     * criteria, the entry count, when it was last built — is on the Admin -> Config page,
     * where an operator can actually read it.
     *
     * @param  array<string, int>  $ips  ip => hits
     */
    public static function render(array $ips): string
    {
        if ($ips === []) {
            return '';
        }

        return implode("\n", array_map('strval', array_keys($ips)))."\n";
    }
}
