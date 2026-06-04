<?php

namespace App\Console\Commands;

use App\Models\FeedLog;
use Illuminate\Console\Command;

class FeedTrim extends Command
{
    protected $signature = 'feed:trim {--days=14 : Delete feed_logs older than this many days}';

    protected $description = 'Prune old feed_logs so the table does not grow unbounded';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = FeedLog::where('created_at', '<', $cutoff)->delete();

        $this->info("feed:trim removed {$deleted} log row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
