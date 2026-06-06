<?php

namespace App\Console\Commands;

use App\Models\FeedQueue;
use App\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Internal health probe for Guidearr's app/DB/queue/worker state.
 *
 * Designed to be called by the host heartbeat (health/heartbeat.sh) via
 *   docker compose exec -T app php artisan health:check --format=env
 * but is equally readable by a human (default output is a short table).
 *
 * Exit code: 0 = healthy, 1 = one or more issues. (If the app itself can't
 * boot, `docker compose exec` returns non-zero and the heartbeat treats that
 * as an app-down condition.)
 */
class HealthCheck extends Command
{
    protected $signature = 'health:check
        {--format=human : human | env | json}';

    protected $description = 'Report DB, worker, queue and refresh health (exit 1 if any issue)';

    public function handle(): int
    {
        $cfg          = (array) config('guidearr.health');
        $orphanMin    = (int) config('guidearr.feed.orphan_minutes', 60);
        $beatStale    = (int) ($cfg['worker_stale_seconds'] ?? 180);
        $refreshMaxHr = (int) ($cfg['refresh_max_age_hours'] ?? 26);

        $m = [
            'db'                       => 'unknown',
            'worker'                   => 'unknown',
            'worker_beat_age'          => -1,
            'queue'                    => 'unknown',
            'queued'                   => -1,
            'running'                  => -1,
            'stuck'                    => -1,
            'refresh'                  => 'unknown',
            'oldest_refresh_age_hours' => -1,
            'oldest_refresh_provider'  => '',
        ];
        $issues = [];

        // --- DB connectivity -------------------------------------------------
        $dbOk = false;
        try {
            DB::select('select 1');
            $dbOk = true;
            $m['db'] = 'ok';
        } catch (Throwable $e) {
            $m['db'] = 'fail';
            $issues[] = 'db';
        }

        // --- Worker liveness (file heartbeat, DB-independent) ----------------
        $beat = storage_path('app/health/worker.beat');
        if (is_file($beat)) {
            $age = time() - (int) @file_get_contents($beat);
            $m['worker_beat_age'] = $age;
            if ($age <= $beatStale) {
                $m['worker'] = 'ok';
            } else {
                $m['worker'] = 'stale';
                $issues[] = 'worker';
            }
        } else {
            $m['worker'] = 'missing';
            $issues[] = 'worker';
        }

        // --- Queue + refresh staleness (only meaningful if DB is up) ---------
        if ($dbOk) {
            try {
                $m['queued']  = (int) FeedQueue::where('state', 'queued')->count();
                $m['running'] = (int) FeedQueue::where('state', 'running')->count();
                $m['stuck']   = (int) FeedQueue::where('state', 'running')
                    ->whereNotNull('dstart')
                    ->where('dstart', '<', now()->subMinutes($orphanMin))
                    ->count();
                $m['queue'] = $m['stuck'] > 0 ? 'stuck' : 'ok';
                if ($m['stuck'] > 0) { $issues[] = 'queue'; }
            } catch (Throwable $e) {
                $m['queue'] = 'fail';
                $issues[] = 'queue';
            }

            if ($refreshMaxHr > 0) {
                try {
                    $oldest = Provider::where('enabled', true)
                        ->whereNotNull('last_refresh_at')
                        ->orderBy('last_refresh_at')
                        ->first();
                    // Treat an enabled provider that has NEVER refreshed as stale too.
                    $never = Provider::where('enabled', true)->whereNull('last_refresh_at')->first();

                    if ($never) {
                        $m['oldest_refresh_age_hours'] = 9999;
                        $m['oldest_refresh_provider']  = $never->name;
                        $m['refresh'] = 'stale';
                        $issues[] = 'refresh';
                    } elseif ($oldest) {
                        $ageHr = (int) floor($oldest->last_refresh_at->diffInHours(now()));
                        $m['oldest_refresh_age_hours'] = $ageHr;
                        $m['oldest_refresh_provider']  = $oldest->name;
                        if ($ageHr > $refreshMaxHr) {
                            $m['refresh'] = 'stale';
                            $issues[] = 'refresh';
                        } else {
                            $m['refresh'] = 'ok';
                        }
                    } else {
                        $m['refresh'] = 'ok'; // no enabled providers
                    }
                } catch (Throwable $e) {
                    $m['refresh'] = 'fail';
                    $issues[] = 'refresh';
                }
            } else {
                $m['refresh'] = 'disabled';
            }
        }

        $ok = $issues === [];
        $this->render($ok, $m, $issues);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function render(bool $ok, array $m, array $issues): void
    {
        $fmt = (string) $this->option('format');

        if ($fmt === 'json') {
            $this->line(json_encode(['ok' => $ok, 'issues' => array_values($issues)] + $m, JSON_UNESCAPED_SLASHES));
            return;
        }

        if ($fmt === 'env') {
            $this->line('ok=' . ($ok ? 1 : 0));
            $this->line('issues=' . implode('|', $issues));
            foreach ($m as $k => $v) {
                $this->line($k . '=' . (is_string($v) ? $v : (string) $v));
            }
            return;
        }

        // human
        $this->line('Guidearr health: ' . ($ok ? '<info>OK</info>' : '<error>ISSUES: ' . implode(', ', $issues) . '</error>'));
        $this->table(['check', 'value'], [
            ['db', $m['db']],
            ['worker', $m['worker'] . " (beat {$m['worker_beat_age']}s)"],
            ['queue', $m['queue'] . " (queued {$m['queued']}, running {$m['running']}, stuck {$m['stuck']})"],
            ['refresh', $m['refresh'] . " (oldest {$m['oldest_refresh_age_hours']}h: {$m['oldest_refresh_provider']})"],
        ]);
    }
}
