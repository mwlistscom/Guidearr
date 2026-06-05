<?php

namespace App\Console\Commands;

use App\Models\FeedQueue;
use App\Models\Provider;
use App\Services\M3uDownloader;
use App\Services\M3uParser;
use App\Services\ProviderStore;
use App\Services\ProviderValidator;
use Illuminate\Console\Command;
use Throwable;

class FeedWork extends Command
{
    protected $signature = 'feed:work
        {--once : Process a single job then exit}
        {--sleep=15 : Seconds to wait between polls when the queue is empty}';

    protected $description = 'Process queued provider feeds: claim a job, download/parse the source into its store, and log under its msgid';

    private string $host = 'worker';

    public function handle(M3uDownloader $downloader, M3uParser $parser, ProviderValidator $validator): int
    {
        $this->host = gethostname() ?: 'worker';
        $sleep = max(1, (int) $this->option('sleep'));
        $this->info("feed:work started on {$this->host}");

        do {
            $job = FeedQueue::claimNext($this->host);
            if (! $job) {
                if ($this->option('once')) { $this->line('No queued jobs.'); return self::SUCCESS; }
                sleep($sleep);
                continue;
            }

            $this->processJob($job, $downloader, $validator);

            if ($this->option('once')) {
                return self::SUCCESS;
            }
        } while (true);
    }

    private function processJob(FeedQueue $job, M3uDownloader $downloader, ProviderValidator $validator): void
    {
        $maxErr   = (int) config('guidearr.feed.max_errors', 4);
        $provider = $job->provider;

        if (! $provider) {
            $job->log('error', 'Provider no longer exists — removing job.');
            $job->delete();
            return;
        }

        // Terminal guard: too many accumulated errors (incl. orphan resets) -> disable + drop.
        if ($job->error >= $maxErr) {
            $job->log('error', "Reached {$job->error} errors — disabling provider and removing job.");
            $provider->forceFill(['enabled' => false, 'last_status' => 'failed'])->save();
            $job->delete();
            return;
        }

        $job->log('info', "Claimed by {$this->host} (provider #{$provider->id}, {$provider->type}).");

        try {
            switch ($provider->type) {
                case 'manual':
                    $job->log('info', 'Manual provider — nothing to download.');
                    $provider->forceFill(['last_status' => 'ok', 'last_refresh_at' => now()])->save();
                    $job->markDone();
                    $job->log('info', 'Done.');
                    return;

                case 'm3u':
                    $this->ingestM3u($job, $provider, $downloader);
                    return;

                case 'xtream':
                    $this->ingestXtream($job, $provider);
                    return;

                case 'xmltv':
                    $this->ingestXmltv($job, $provider);
                    return;

                default: // anything unexpected — validate only
                    $job->log('info', strtoupper($provider->type) . ' validation only.');
                    $check = $validator->validate($provider->type, $provider->url, $provider->username, $provider->password);
                    if (! $check['ok']) { $this->failJob($job, $provider, $check['message']); return; }
                    $updates = ['last_status' => 'ok', 'last_refresh_at' => now()];
                    if (! empty($check['timeshift'])) { $updates['timeshift'] = $check['timeshift']; }
                    $provider->forceFill($updates)->save();
                    $job->log('info', $check['message']);
                    $job->markDone();
                    $job->log('info', 'Done.');
                    return;
            }
        } catch (Throwable $e) {
            $this->failJob($job, $provider, 'Worker error: ' . $e->getMessage());
        }
    }

    private function ingestM3u(FeedQueue $job, Provider $provider, M3uDownloader $downloader): void
    {
        if (! $provider->url) { $this->failJob($job, $provider, 'No URL set.'); return; }

        $job->log('info', "Downloading M3U: {$provider->url}");
        $tmp = storage_path('app/feeds/dl_' . $provider->id . '_' . $job->msgid . '.m3u');
        @mkdir(dirname($tmp), 0775, true);

        $res = $downloader->download($provider->url, $tmp);
        if (! $res->ok) { @unlink($tmp); $this->failJob($job, $provider, 'Download failed: ' . $res->error); return; }
        $job->log('info', number_format($res->bytes) . ' bytes downloaded; parsing.');

        $handle = @fopen($tmp, 'r');
        if (! $handle) { @unlink($tmp); $this->failJob($job, $provider, 'Could not open downloaded file.'); return; }

        $store   = new ProviderStore($provider->id);
        $version = substr($job->msgid, 0, 12);

        $n = 0;
        $store->begin();
        $result = M3uParser::stream($handle, function (array $c) use ($store, $version, &$n) {
            $store->upsertChannel($c, $version);
            if (++$n % 2000 === 0) { $store->commit(); usleep(50000); $store->begin(); }
        });
        $store->commit();
        fclose($handle);
        @unlink($tmp);

        if ($result['count'] === 0) {
            $this->failJob($job, $provider, 'No channels found — source did not look like a valid M3U.');
            return;
        }

        $store->begin();
        $order = $store->nextGroupOrder();
        foreach ($result['groups'] as $g) { $store->upsertGroup($g, $order, $version); $order += 10; }
        $store->upsertGroup('[Dummy]', $order, $version);
        $store->commit();

        $removed = $store->sweep($version);
        $added   = $store->addedCount();
        $counts  = $store->counts();

        $job->log('info', "Parsed {$result['count']} channels (added {$added}, removed {$removed}); store now holds {$counts['channels']} channels in {$counts['groups']} groups.");

        // Optional XMLTV/EPG guide for this m3u provider — never fails the channel ingest.
        if (trim((string) $provider->epg_url) !== '') {
            try {
                (new \App\Services\M3uGuideImporter())->importGuide($provider, $version, fn (string $m) => $job->log('info', $m));
            } catch (Throwable $e) {
                $job->log('warning', 'Guide import error (channels kept): ' . $e->getMessage());
            }
        }

        $provider->forceFill(['last_status' => 'ok', 'last_refresh_at' => now()])->save();
        $job->markDone();
        $job->log('info', 'Done.');
    }

    /** XMLTV provider: the URL *is* an XMLTV guide (no channel list) — load it into the guide tables. */
    private function ingestXmltv(FeedQueue $job, Provider $provider): void
    {
        if (! $provider->url) { $this->failJob($job, $provider, 'No URL set.'); return; }
        $version = substr($job->msgid, 0, 12);

        try {
            $g = (new \App\Services\M3uGuideImporter())->importUrl(
                $provider, (string) $provider->url, $version, fn (string $m) => $job->log('info', $m)
            );
        } catch (Throwable $e) {
            $this->failJob($job, $provider, 'Guide import failed: ' . $e->getMessage());
            return;
        }

        if (isset($g['skipped'])) {
            $this->failJob($job, $provider, 'Guide not ingested: ' . $g['skipped']);
            return;
        }

        $provider->forceFill(['last_status' => 'ok', 'last_refresh_at' => now()])->save();
        $job->markDone();
        $job->log('info', "Done. {$g['guide_channels']} guide channels, {$g['programmes']} programmes.");
    }

    private function ingestXtream(FeedQueue $job, Provider $provider): void
    {
        if (! $provider->url) { $this->failJob($job, $provider, 'No URL set.'); return; }
        if (! $provider->username || ! $provider->password) {
            $this->failJob($job, $provider, 'Xtream provider needs a username and password.');
            return;
        }

        $version = substr($job->msgid, 0, 12);

        try {
            $r = (new \App\Services\XtreamImporter())->import(
                $provider, $version, fn (string $m) => $job->log('info', $m)
            );
        } catch (\Throwable $e) {
            $this->failJob($job, $provider, 'Xtream import failed: ' . $e->getMessage());
            return;
        }

        if ($r['channels'] === 0) {
            $this->failJob($job, $provider, 'No channels returned — not a valid Xtream API?');
            return;
        }

        $provider->forceFill(['last_status' => 'ok', 'last_refresh_at' => now()])->save();
        $job->markDone();
        $job->log('info', "Done. {$r['channels']} channels, {$r['groups']} groups, {$r['guide_channels']} guide channels, {$r['programmes']} programmes.");
    }

    /** A recoverable failure: count it, disable+drop at the threshold, otherwise requeue for another attempt. */
    private function failJob(FeedQueue $job, Provider $provider, string $message): void
    {
        $maxErr = (int) config('guidearr.feed.max_errors', 4);
        $job->forceFill(['error' => $job->error + 1])->save();
        $job->log('error', $message . " (error #{$job->error})");

        if ($job->error >= $maxErr) {
            $job->log('error', "Reached {$job->error} errors — disabling provider and removing job.");
            $provider->forceFill(['enabled' => false, 'last_status' => 'failed'])->save();
            $job->delete();
        } else {
            $provider->forceFill(['last_status' => 'failed'])->save();
            $job->forceFill(['state' => 'queued', 'processor' => null])->save();
        }
    }
}
