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
}
