<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

class FeedVacuum extends Command
{
    protected $signature = 'feed:vacuum {--playlists : also VACUUM the per-playlist stores}';

    protected $description = 'Reclaim SQLite free-page bloat in the provider feed stores (built up by channel mark-sweeps and atomic guide reloads, which delete rows but never shrink the file) by running VACUUM on each. Runs as the scheduler user so store files keep their www-data ownership.';

    public function handle(): int
    {
        $globs = [storage_path('app/feeds/*.sqlite')];
        if ($this->option('playlists')) {
            $globs[] = storage_path('app/playlists/*.sqlite');
        }

        $files = [];
        foreach ($globs as $g) {
            $files = array_merge($files, glob($g) ?: []);
        }

        $before = 0;
        $after = 0;
        $done = 0;
        $failed = 0;

        $human = function (int $bytes): string {
            $u = ['B', 'KB', 'MB', 'GB'];
            $i = 0;
            $n = (float) $bytes;
            while ($n >= 1024 && $i < count($u) - 1) {
                $n /= 1024;
                $i++;
            }

            return ($i === 0 ? (int) $n : number_format($n, 1)).$u[$i];
        };

        $total = count($files);
        $this->line($total ? "Vacuuming {$total} store(s)…" : 'No store files found to vacuum.');

        $i = 0;
        foreach ($files as $f) {
            $i++;
            $b = (int) @filesize($f);
            try {
                $db = new PDO('sqlite:'.$f);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->exec('PRAGMA busy_timeout=60000'); // wait out a mid-refresh writer rather than failing
                $db->exec('VACUUM');
                $db = null;
                clearstatcache(true, $f);
                $a = (int) @filesize($f);
                $before += $b;
                $after += $a;
                $done++;
                // One line per store so a long run streams visible progress (not just a final total).
                $this->line(sprintf(
                    '[%d/%d] %s: %s -> %s (reclaimed %s)',
                    $i, $total, basename($f), $human($b), $human($a), $human(max(0, $b - $a))
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('[%d/%d] %s: %s', $i, $total, basename($f), $e->getMessage()));
            }
        }

        $summary = sprintf(
            'feed:vacuum reclaimed %.0fMB across %d store(s)%s.',
            ($before - $after) / 1048576,
            $done,
            $failed ? " ({$failed} failed)" : ''
        );
        $this->info($summary);
        Log::channel('worker')->info($summary);

        return self::SUCCESS;
    }
}
