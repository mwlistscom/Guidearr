<?php

namespace App\Services;

use App\Models\FeedQueue;
use App\Models\Provider;

/**
 * Applies an M3U provider URL change without breaking the playlists that point at its
 * channels — the M3U analog of {@see XtreamCredentialMigrator}.
 *
 * M3U channels have no embedded stable id (unlike Xtream's stream_id); each channel's
 * identity in the store is its full stream URL, which a provider can rotate (e.g. a
 * renewed-subscription M3U with a token in every URL). So this matches the current
 * channels to the freshly-downloaded M3U by **tvg-id (fallback name+group)**, and if at
 * least the configured share still match, rewrites each matched channel's URL IN PLACE —
 * preserving the row id — before importing the rest. Because playlists resolve URLs live
 * from the provider store, matched channels keep working with no re-import. If too few
 * match (a different list), nothing is changed.
 *
 * Progress is reported through the provider's FeedQueue/FeedLog so the existing "update
 * log" overlay can tail it live.
 */
class M3uProviderMigrator
{
    public function __construct(private M3uDownloader $downloader) {}

    /**
     * Stable-ish match key for one channel: its tvg-id when present, else name+group
     * (both lower-cased/trimmed). Pure + network-free, so it is unit-testable.
     */
    public static function matchKey(string $tvgId, string $name, string $group): string
    {
        $tvgId = trim($tvgId);
        if ($tvgId !== '') {
            return 'id:'.mb_strtolower($tvgId);
        }

        return 'nm:'.mb_strtolower(trim($name)).'|'.mb_strtolower(trim($group));
    }

    /**
     * Validate + apply an M3U URL change in the background (after the HTTP response). The
     * caller has already checked the new URL is a valid M3U signature; this downloads it,
     * matches by key, gates on the match ratio, and — only on success — rewrites matched
     * URLs in place, imports the full new list, and saves the new URL.
     */
    public function run(int $providerId, string $newUrl, string $msgid): void
    {
        $job = FeedQueue::where('msgid', $msgid)->first();
        $log = fn (string $level, string $msg) => $job?->log($level, $msg);
        $tmp = storage_path('app/feeds/m3umig_'.$providerId.'_'.$msgid.'.m3u');

        try {
            $provider = Provider::find($providerId);
            if (! $provider) {
                $log('error', 'Provider no longer exists — aborting, nothing changed.');
                $job?->markError();

                return;
            }

            $log('info', 'Downloading the M3U from the new URL…');
            $dl = $this->downloader->download($newUrl, $tmp);
            if (! $dl->ok) {
                $log('error', 'Download failed: '.$dl->error.' — no changes made.');
                $job?->markError();

                return;
            }

            // Pass 1: index the new list by match key (first occurrence wins).
            $newByKey = [];
            $newCount = 0;
            $h = @fopen($tmp, 'r');
            if (! $h) {
                $log('error', 'Could not read the downloaded M3U — no changes made.');
                $job?->markError();

                return;
            }
            M3uParser::stream($h, function (array $c) use (&$newByKey, &$newCount) {
                $k = self::matchKey($c['tvg_id'] ?? '', $c['name'] ?? '', $c['group'] ?? '');
                $newByKey[$k] ??= $c['url'];
                $newCount++;
            });
            fclose($h);

            if ($newCount === 0) {
                $log('error', 'The new URL returned no channels — it does not look like a valid M3U. No changes made.');
                $job?->markError();
                @unlink($tmp);

                return;
            }

            // Match the current store channels to the new list, building an id => new-URL plan.
            $store = new ProviderStore($providerId);
            $current = $store->channelsForMatching();
            $plan = [];
            foreach ($current as $c) {
                $k = self::matchKey($c['tvg_id'], $c['name'], $c['group_title']);
                if (isset($newByKey[$k])) {
                    $plan[$c['id']] = $newByKey[$k];
                }
            }
            $have = count($current);
            $matched = count($plan);
            $ratio = $have > 0 ? $matched / $have : 0.0;
            $pct = round($ratio * 100, 1);
            $log('info', "Downloaded {$newCount} channels; matched {$matched} of {$have} existing channels ({$pct}%).");

            $threshold = (float) config('guidearr.m3u.url_match_threshold', 0.70);
            if ($ratio < $threshold) {
                $log('error', 'Match '.$pct.'% is below the required '.round($threshold * 100).'% — this looks like a different provider. No changes made; the URL is unchanged.');
                $job?->markError();
                @unlink($tmp);

                return;
            }

            // Rewrite the matched channels' URLs in place (ids preserved) so the import below
            // upserts them rather than re-creating them.
            $log('info', 'Match OK — rewriting matched channel URLs in place (IDs preserved)…');
            $rw = $store->rewriteChannelUrls($plan);
            $log('info', "Rewrote {$rw['updated']} channel URLs in place, merged {$rw['deleted']} duplicate rows.");
            if (! empty($rw['remap'])) {
                foreach ($provider->playlists as $pl) {
                    (new PlaylistStore($pl->id))->remapProviderPointers($providerId, $rw['remap']);
                }
            }

            // Full import from the same download: refresh metadata + add genuinely-new channels;
            // matched rows now match by URL and upsert in place.
            $version = substr($msgid, 0, 12);
            $h = @fopen($tmp, 'r');
            $n = 0;
            $store->begin();
            $parsed = M3uParser::stream($h, function (array $c) use ($store, $version, &$n) {
                $store->upsertChannel($c, $version);
                if (++$n % 2000 === 0) {
                    $store->commit();
                    $store->begin();
                }
            });
            $store->commit();
            fclose($h);
            @unlink($tmp);

            $store->begin();
            $order = $store->nextGroupOrder();
            foreach ($parsed['groups'] as $g) {
                $store->upsertGroup($g, $order, $version);
                $order += 10;
            }
            $store->upsertGroup('[Dummy]', $order, $version);
            $store->commit();
            $removed = $store->sweep($version);
            $log('info', "Imported {$parsed['count']} channels ({$store->addedCount()} new, {$removed} removed).");

            // Save the new URL, then sync attached playlists (add new channels, drop gone ones).
            $provider->forceFill([
                'url' => $newUrl,
                'last_status' => 'ok',
                'last_refresh_at' => now(),
                'last_touch_at' => now(),
            ])->save();

            if ($store->channelCount() > 0) {
                foreach ($provider->playlists as $pl) {
                    $ps = new PlaylistStore($pl->id);
                    $ps->insertNewFromProvider($providerId, $store);
                    $ps->reconcileProvider($providerId, $store);
                    $pl->markTouched();
                }
            }

            $log('info', 'New URL saved. Attached playlists updated — matched channels kept their place.');
            $job?->markDone();
        } catch (\Throwable $e) {
            report($e);
            $log('error', 'M3U URL change failed: '.$e->getMessage().' — see logs. Some changes may have been partially applied.');
            $job?->markError();
            @unlink($tmp);
        }
    }
}
