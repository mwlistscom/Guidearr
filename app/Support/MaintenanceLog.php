<?php

namespace App\Support;

/**
 * A dedicated, self-managed log for admin/scheduled maintenance tasks — kept separate from the
 * worker log so the operator can watch it on its own under Admin → Logs. Lines are plain text with
 * a Laravel-style `[Y-m-d H:i:s]` prefix (so the Logs viewer colourises + timestamps them), and the
 * file is trimmed to the last N days on each write-burst so it never grows unbounded.
 */
class MaintenanceLog
{
    public const RETENTION_DAYS = 30;

    public static function path(): string
    {
        return storage_path('logs/maintenance.log');
    }

    /** Append one timestamped line. Level is a bare word (INFO/ERROR/…) the viewer can colourise. */
    public static function write(string $message, string $level = 'INFO'): void
    {
        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = '['.now()->format('Y-m-d H:i:s').'] '.$level.': '.rtrim($message)."\n";
        @file_put_contents(self::path(), $line, FILE_APPEND | LOCK_EX);
    }

    /** Append many lines under one level (blank lines skipped). */
    public static function writeBlock(string $text, string $level = 'INFO'): void
    {
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                self::write($line, $level);
            }
        }
    }

    /** Drop lines older than the retention window, parsing each line's leading `[Y-m-d H:i:s]`. */
    public static function trim(int $days = self::RETENTION_DAYS): void
    {
        $path = self::path();
        if (! is_file($path)) {
            return;
        }

        $cutoff = now()->subDays($days)->timestamp;
        $kept = [];
        $emitting = false;
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^\[(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\]/', $line, $m)) {
                $ts = mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
                $emitting = $ts >= $cutoff;
            }
            // Keep continuation lines (no stamp) only once we're inside the window.
            if ($emitting && $line !== '') {
                $kept[] = $line;
            }
        }

        @file_put_contents($path, $kept ? implode("\n", $kept)."\n" : '', LOCK_EX);
    }

    /** Whole-file contents (empty string when absent). */
    public static function read(): string
    {
        return is_file(self::path()) ? (string) @file_get_contents(self::path()) : '';
    }
}
