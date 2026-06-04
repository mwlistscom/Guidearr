<?php

namespace App\Console\Commands;

use App\Models\FeedQueue;
use App\Services\ProviderValidator;
use Illuminate\Console\Command;
use Throwable;

class FeedWork extends Command
{
    protected $signature = 'feed:work
        {--once : Process a single job then exit}
        {--sleep=15 : Seconds to wait between polls when the queue is empty}';

    protected $description = 'Process queued provider feeds: claim a job, validate/download the source, and log progress under its msgid';

    public function handle(ProviderValidator $validator): int
    {
        $host  = gethostname() ?: 'worker';
        $sleep = max(1, (int) $this->option('sleep'));
        $this->info("feed:work started on {$host}");

        do {
            $job = FeedQueue::claimNext($host);

            if (! $job) {
                if ($this->option('once')) {
                    $this->line('No queued jobs.');
                    return self::SUCCESS;
                }
                sleep($sleep);
                continue;
            }

            $this->processJob($job, $validator, $host);

            if ($this->option('once')) {
                return self::SUCCESS;
            }
        } while (true);
    }

    private function processJob(FeedQueue $job, ProviderValidator $validator, string $host): void
    {
        $provider = $job->provider;
        if (! $provider) {
            $job->log('error', 'Provider no longer exists.');
            $job->markError(404);
            return;
        }

        $job->log('info', "Claimed by {$host} (provider #{$provider->id}, {$provider->type}).");

        try {
            if ($provider->type === 'manual') {
                $job->log('info', 'Manual provider — nothing to download.');
                $provider->forceFill(['last_status' => 'ok', 'last_refresh_at' => now()])->save();
                $job->markDone();
                $job->log('info', 'Done.');
                return;
            }

            $job->log('info', "Validating {$provider->type} source: {$provider->url}");
            $check = $validator->validate($provider->type, $provider->url, $provider->username, $provider->password);

            if (! $check['ok']) {
                $provider->forceFill(['last_status' => 'failed'])->save();
                $job->log('error', $check['message']);
                $job->markError(1);
                return;
            }

            $updates = ['last_status' => 'ok', 'last_refresh_at' => now()];
            if (! empty($check['timeshift'])) {
                $updates['timeshift'] = $check['timeshift'];
                $job->log('info', "Server timezone: {$check['timeshift']}");
            }
            $provider->forceFill($updates)->save();

            $job->log('info', $check['message'] . " ({$check['bytes']} bytes read).");
            $job->log('info', 'TODO (phase 3): parse and populate channels/guide.');
            $job->markDone();
            $job->log('info', 'Done.');
        } catch (Throwable $e) {
            $provider->forceFill(['last_status' => 'failed'])->save();
            $job->log('error', 'Worker error: ' . $e->getMessage());
            $job->markError(2);
        }
    }
}
