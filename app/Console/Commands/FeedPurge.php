<?php

namespace App\Console\Commands;

use App\Models\FeedLog;
use App\Models\PurgeJob;
use Illuminate\Console\Command;

class FeedPurge extends Command
{
    protected $signature = 'feed:purge
        {--id= : Process only this purge_queue row id}
        {--prune-done=7 : Delete completed purge rows older than this many days (0 to keep all)}';

    protected $description = 'Delete leftover data (per-provider SQLite store files) for removed accounts queued in purge_queue';

    public function handle(): int
    {
        $query = PurgeJob::where('state', 'queued');
        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        }

        $jobs = $query->orderBy('id')->get();

        foreach ($jobs as $job) {
            try {
                $job->update(['state' => 'running', 'attempts' => $job->attempts + 1]);

                $removed = 0;
                foreach (($job->payload ?? []) as $entry) {
                    $path = $entry['path'] ?? null;
                    if (! $path) {
                        continue;
                    }
                    foreach (['', '-wal', '-shm'] as $suffix) {
                        if (is_file($path . $suffix)) {
                            @unlink($path . $suffix);
                            $removed++;
                        }
                    }
                }

                // Belt-and-suspenders: drop any feed_logs still tied to this user id.
                FeedLog::where('user_id', $job->user_id)->delete();

                $job->update(['state' => 'done', 'error' => null]);
                $this->info("Purged user #{$job->user_id} ({$job->email}): removed {$removed} store file(s).");
            } catch (\Throwable $e) {
                $job->update(['state' => 'error', 'error' => $e->getMessage()]);
                $this->error("Purge #{$job->id} failed: {$e->getMessage()}");
            }
        }

        if ($jobs->isEmpty()) {
            $this->info('feed:purge: nothing queued.');
        }

        // Housekeeping: drop old completed rows so the table stays small.
        $keepDays = (int) $this->option('prune-done');
        if ($keepDays > 0) {
            $pruned = PurgeJob::where('state', 'done')
                ->where('updated_at', '<', now()->subDays($keepDays))
                ->delete();
            if ($pruned) {
                $this->line("feed:purge: pruned {$pruned} completed row(s).");
            }
        }

        return self::SUCCESS;
    }
}
