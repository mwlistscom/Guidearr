<?php

namespace App\Console\Commands;

use App\Models\FeedQueue;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Supervises the feed queue. Runs as the worker container's main process and keeps up to
 * the configured Worker Limit (Admin → Config) of `feed:work --drain` children alive while
 * providers are queued, scaling the pool with the backlog and back down to zero when idle.
 *
 * The queue claim (FeedQueue::claimNext) already uses SELECT … FOR UPDATE SKIP LOCKED, so
 * children never claim the same job; over-spawning is self-correcting (a child that finds no
 * work exits immediately). This process owns the liveness heartbeat and the orphan reclaim.
 */
class FeedSupervise extends Command
{
    protected $signature = 'feed:supervise
        {--sleep=15 : Seconds to wait between polls when the queue is empty}
        {--tick=3 : Seconds between supervisor checks while workers are active}';

    protected $description = 'Run up to the configured Worker Limit of feed:work children, scaling with the queue backlog';

    /** @var Process[] Live child worker processes. */
    private array $pool = [];

    private bool $stopping = false;

    public function handle(): int
    {
        $host = gethostname() ?: 'worker';
        $sleep = max(1, (int) $this->option('sleep'));
        $tick = max(1, (int) $this->option('tick'));

        $this->installSignalHandlers();
        $this->info("feed:supervise started on {$host}");
        Log::channel('worker')->info("Supervisor started on {$host} (worker limit ".Settings::workerLimit().').');

        while (! $this->stopping) {
            // Beat BEFORE the DB work, so a DB outage reads as "alive, DB down" not "dead".
            $this->beat();

            try {
                FeedQueue::reclaimOrphans();
                $this->reap();

                $limit = Settings::workerLimit();
                $active = count($this->pool);
                $queued = self::backlogCount();

                $spawn = self::slotsToSpawn($limit, $active, $queued);
                if ($spawn > 0) {
                    Log::channel('worker')->info("Backlog {$queued} queued, {$active} running (limit {$limit}) — started {$spawn} worker(s).");
                }
                for ($i = 0; $i < $spawn; $i++) {
                    $this->spawn();
                }
            } catch (Throwable $e) {
                // A DB blip must never kill the supervisor; log, back off, keep looping.
                $this->error('feed:supervise loop error: '.$e->getMessage());
                Log::channel('worker')->error('Supervisor loop error: '.$e->getMessage());
                report($e);
            }

            // Busy → short tick to top the pool up responsively; idle → longer poll.
            $this->interruptibleSleep(count($this->pool) > 0 ? $tick : $sleep);
        }

        $this->info('feed:supervise stopping — draining workers…');
        Log::channel('worker')->info('Supervisor stopping — draining '.count($this->pool).' worker(s).');
        $this->drain();

        return self::SUCCESS;
    }

    /**
     * Jobs a worker could claim right now.
     *
     * Deliberately NOT a plain count of 'queued': a job waiting out a retry backoff is queued but
     * unclaimable, and counting it would have the pool spawn children that find nothing and exit
     * on every tick. Extracted so the distinction is testable.
     */
    public static function backlogCount(): int
    {
        return FeedQueue::claimable()->count();
    }

    /**
     * How many new children to start: fill the free slots under the limit, but never more
     * than there are queued jobs waiting to be claimed. Pure + side-effect-free for testing.
     */
    public static function slotsToSpawn(int $limit, int $active, int $queued): int
    {
        $slots = max(1, $limit) - max(0, $active);

        return max(0, min($slots, max(0, $queued)));
    }

    private function spawn(): void
    {
        $php = (new PhpExecutableFinder)->find() ?: 'php';
        $process = new Process([$php, 'artisan', 'feed:work', '--drain'], base_path());
        $process->setTimeout(null); // a large, slow feed may legitimately run long
        $process->start();
        $this->pool[] = $process;
    }

    /** Drop finished children from the pool; surface a non-zero exit in the log. */
    private function reap(): void
    {
        foreach ($this->pool as $k => $process) {
            if ($process->isRunning()) {
                continue;
            }
            if (! $process->isSuccessful()) {
                $err = trim($process->getErrorOutput()) ?: trim($process->getOutput());
                $msg = 'worker exited non-zero'.($err !== '' ? ': '.strtok($err, "\n") : '.');
                $this->warn($msg);
                Log::channel('worker')->warning($msg);
            }
            unset($this->pool[$k]);
        }
        $this->pool = array_values($this->pool);
    }

    /** Best-effort wait for children to finish on shutdown, within Docker's stop grace. */
    private function drain(): void
    {
        $deadline = time() + 8;
        while ($this->pool !== [] && time() < $deadline) {
            $this->reap();
            usleep(200_000);
        }
        // Anything still running is stopped; its 'running' job is reset to 'queued' by
        // reclaimOrphans on the next start, so no work is lost.
        foreach ($this->pool as $process) {
            if ($process->isRunning()) {
                $process->stop(2);
            }
        }
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    /** Sleep in one-second slices so a SIGTERM is acted on promptly. */
    private function interruptibleSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && ! $this->stopping; $i++) {
            sleep(1);
        }
    }

    /** Write a liveness timestamp the health probe reads; never throws. */
    private function beat(): void
    {
        try {
            $dir = storage_path('app/health');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($dir.'/worker.beat', (string) time());
        } catch (Throwable $e) {
            // a failing heartbeat must never break the supervisor loop
        }
    }
}
