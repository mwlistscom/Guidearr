<?php

namespace App\Support;

/**
 * Tiny key/value settings store backed by a JSON file in storage/app/settings.
 * Mirrors the lightweight flat-file approach already used for branding, and
 * survives container restarts because storage/ is a bind/volume mount.
 */
class Settings
{
    private static function file(): string
    {
        return storage_path('app/settings/app.json');
    }

    public static function all(): array
    {
        $f = self::file();
        if (! is_file($f)) {
            return [];
        }
        $j = json_decode((string) @file_get_contents($f), true);

        return is_array($j) ? $j : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $dir = dirname(self::file());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $all = self::all();
        $all[$key] = $value;
        @file_put_contents(self::file(), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Public base URL the playlist "Links" overlay builds its M3U/EPG/Stream
     * links from (no trailing slash). Running in Docker behind a reverse proxy,
     * the app can't reliably detect its own public origin, so an admin sets it.
     */
    public static function linksBaseUrl(): string
    {
        return rtrim((string) self::get('links_base_url', ''), '/');
    }

    /** Max unique IPs allowed per playlist within the rolling window before serving is throttled. */
    public static function serveMaxIps(): int
    {
        $v = (int) self::get('serve_max_ips', 10);

        return $v > 0 ? $v : 10;
    }

    /** Length of the rolling unique-IP window, in hours. */
    public static function serveWindowHours(): int
    {
        $v = (int) self::get('serve_window_hours', 4);

        return $v > 0 ? $v : 4;
    }

    /**
     * Max concurrent feed workers the supervisor may run. 1 (the default) matches the
     * historical single-worker behavior; raise it to parallelise a backlog of many
     * providers when the box has spare CPU/RAM. Clamped to a sane 1–16.
     */
    public static function workerLimit(): int
    {
        return max(1, min(16, (int) self::get('worker_limit', 1)));
    }

    /** Is the firewall-facing threat feed being served? */
    public static function threatFeedEnabled(): bool
    {
        return (bool) self::get('threat_feed_enabled', false);
    }

    /**
     * Secret URL segment the threat feed is served under. Generated and stored on
     * first read so a fresh install has a working URL with no setup step — there is
     * nothing for an admin to run, and no window where the endpoint exists under a
     * guessable path. Admins can replace it with their own value.
     */
    public static function threatFeedSlug(): string
    {
        $slug = trim((string) self::get('threat_feed_slug', ''));

        if ($slug === '') {
            $slug = bin2hex(random_bytes(16));
            self::set('threat_feed_slug', $slug);
        }

        return $slug;
    }

    /**
     * Hostile requests from one address before it is listed. Clamped to a sane 1–10000.
     * A stored null or empty string falls back to the default rather than casting to 0,
     * which would otherwise list an address on its very first 404.
     */
    public static function threatFeedMinHits(): int
    {
        $v = self::get('threat_feed_min_hits');

        return max(1, min(10000, $v === null || $v === '' ? 20 : (int) $v));
    }

    /** Absolute URL to hand to pfBlockerNG (or any firewall) as a custom list source. */
    public static function threatFeedUrl(): string
    {
        $base = self::linksBaseUrl() ?: rtrim((string) config('app.url'), '/');

        // The .txt is cosmetic — the route strips it — but pfBlockerNG infers a list's
        // format from the URL and rejects one without a file extension.
        return $base.'/security/threat-feed/'.self::threatFeedSlug().'.txt';
    }
}
