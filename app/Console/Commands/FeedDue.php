<?php

namespace App\Console\Commands;

use App\Models\FeedQueue;
use App\Models\Provider;
use Illuminate\Console\Command;

class FeedDue extends Command
{
    protected $signature = 'feed:due {--dry-run : List due providers without enqueuing}';

    protected $description = 'Enqueue a refresh for every enabled provider whose daily refresh time has arrived';

    public function handle(): int
    {
        $now = now(); // interpreted in config('app.timezone')
        $queued = 0;

        foreach (Provider::where('enabled', true)->get() as $provider) {
            $scheduled = $now->copy()->setTime(
                (int) ($provider->refresh_hour ?? 0),
                (int) ($provider->refresh_minute ?? 0),
                0
            );

            // Due when we've passed today's scheduled moment and the last successful
            // refresh predates it. This catches up if a minute was skipped, and is
            // idempotent: last_refresh_at advances past $scheduled once the job finishes,
            // so a provider is enqueued at most once per day.
            $due = $now->greaterThanOrEqualTo($scheduled)
                && ($provider->last_refresh_at === null || $provider->last_refresh_at->lt($scheduled));

            if (! $due) {
                continue;
            }

            // Never stomp a job already in flight for this provider.
            $inFlight = FeedQueue::where('provider_id', $provider->id)
                ->whereIn('state', ['queued', 'running'])
                ->exists();
            if ($inFlight) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("due:    #{$provider->id} {$provider->name} (scheduled {$scheduled->format('H:i')})");
                $queued++;
                continue;
            }

            FeedQueue::enqueue($provider);
            $queued++;
            $this->line("queued: #{$provider->id} {$provider->name}");
        }

        $this->info("feed:due: {$queued} provider(s) " . ($this->option('dry-run') ? 'due.' : 'queued.'));

        return self::SUCCESS;
    }
}
