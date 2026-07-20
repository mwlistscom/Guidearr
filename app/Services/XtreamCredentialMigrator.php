<?php

namespace App\Services;

use App\Models\FeedQueue;
use App\Models\Provider;

/**
 * Applies an Xtream provider credential change (URL / username / password) without
 * breaking the playlists that point at its channels.
 *
 * The problem: channels are keyed on their full stream URL, which embeds the
 * credentials, and playlists point at channels by autoincrement id. A normal
 * re-import with new credentials would give every channel a new URL -> new id ->
 * orphan every playlist pointer.
 *
 * The fix: download the channel list with the new credentials, confirm >= the
 * configured share of stream_ids still match (same provider, not a wrong account),
 * then rewrite each existing channel's URL IN PLACE keyed on its stable stream_id —
 * preserving the row id. Because playlists resolve the URL live from the provider
 * store, every attached playlist follows automatically with no playlist changes.
 *
 * Progress is reported through the provider's FeedQueue/FeedLog so the existing
 * "update log" overlay can tail it live.
 */
class XtreamCredentialMigrator
{
    public function __construct(private XtreamImporter $importer) {}

    /**
     * Fraction of the store's existing stream_ids that also appear in a fresh
     * download. Pure + network-free, so it is unit-testable.
     *
     * @param  string[]  $existing  stream_ids already stored
     * @param  string[]  $downloaded  stream_ids from the new-credential download
     * @return array{ratio:float, hit:int, existing:int, downloaded:int}
     */
    public static function matchRatio(array $existing, array $downloaded): array
    {
        $have = array_values(array_unique($existing));
        $new = array_flip($downloaded);
        $hit = 0;
        foreach ($have as $sid) {
            if (isset($new[$sid])) {
                $hit++;
            }
        }
        $count = count($have);

        return [
            'ratio' => $count > 0 ? $hit / $count : 0.0,
            'hit' => $hit,
            'existing' => $count,
            'downloaded' => count($downloaded),
        ];
    }

    /**
     * Validate + apply a credential change in the background (called after the HTTP
     * response is sent). Login is assumed already validated by the caller; this
     * performs the download, the match gate, and — only on success — the in-place
     * URL rewrite and the credential save. Nothing is changed if the gate fails.
     */
    public function run(int $providerId, string $newUrl, string $newUser, string $newPass, string $msgid): void
    {
        $job = FeedQueue::where('msgid', $msgid)->first();
        $log = fn (string $level, string $msg) => $job?->log($level, $msg);

        try {
            $provider = Provider::find($providerId);
            if (! $provider) {
                $log('error', 'Provider no longer exists — aborting, nothing changed.');
                $job?->markError();

                return;
            }

            $newBase = XtreamImporter::baseUrlFromString($newUrl);

            $log('info', 'Downloading the channel list with the new credentials…');
            $downloaded = $this->importer->fetchLiveStreamIds($newBase, $newUser, $newPass);

            $store = new ProviderStore($providerId);
            $existing = $store->xtreamStreamIds();

            $m = self::matchRatio($existing, $downloaded);
            $pct = round($m['ratio'] * 100, 1);
            $log('info', "Downloaded {$m['downloaded']} channels; matched {$m['hit']} of {$m['existing']} existing channels ({$pct}%).");

            $threshold = (float) config('guidearr.xtream.credential_match_threshold', 0.70);
            if ($m['ratio'] < $threshold) {
                $log('error', 'Match '.$pct.'% is below the required '.round($threshold * 100).'% — no changes made. The provider still uses its previous credentials.');
                $job?->markError();

                return;
            }

            $log('info', 'Match OK — rewriting channel URLs in place (surviving IDs preserved, duplicate rows merged)…');
            $res = $store->rewriteXtreamCredentials($newBase, $newUser, $newPass);
            $log('info', "Store: {$res['updated']} URLs rewritten, {$res['deleted']} duplicate rows merged, {$res['skipped']} manual/non-Xtream left as-is (of {$res['total']}).");

            // If duplicates were merged, repoint every attached playlist's pointers (enabled,
            // disabled, and deleted alike) from the removed rows onto the survivors.
            if (! empty($res['remap'])) {
                $playlists = $provider->playlists()->get();
                $repointed = 0;
                $merged = 0;
                foreach ($playlists as $pl) {
                    $r = (new PlaylistStore($pl->id))->remapProviderPointers($provider->id, $res['remap']);
                    $repointed += $r['repointed'];
                    $merged += $r['merged'];
                    // Deliberately NOT markTouched(): last_touch_at tracks USER activity only, and
                    // this runs in the background. The edit that started it already stamped the
                    // provider in ProviderController::update().
                }
                $log('info', "Playlists: {$playlists->count()} updated — {$repointed} pointers repointed, {$merged} merged onto survivors.");
            }

            $provider->forceFill([
                'url' => $newUrl,
                'username' => $newUser,
                'password' => $newPass,
                'last_status' => 'ok',
                'last_refresh_at' => now(),
            ])->save();

            $log('info', 'New credentials saved. Every attached playlist now serves the new URLs automatically.');
            $job?->markDone();
        } catch (\Throwable $e) {
            report($e);
            $log('error', 'Credential update failed: '.$e->getMessage().' — no channel URLs or credentials were changed.');
            $job?->markError();
        }
    }
}
