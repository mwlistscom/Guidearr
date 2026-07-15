<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Console\Command;

class PlaylistsPruneMissing extends Command
{
    protected $signature = 'playlists:prune-missing {ids?* : Only these playlist IDs (default: all)} {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Remove "(missing channel)" pointer rows from playlists — pointers into a provider store whose channel no longer exists (swept after >3 missed refreshes, or its URL changed). These already serve nothing (skipped at serve time); this clears them from the editor. SAFETY: a provider whose store is missing or currently empty (e.g. mid-import) is skipped, so a transient provider outage never wipes a playlist.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_map('intval', (array) $this->argument('ids'));

        $query = Playlist::orderBy('id');
        if ($only) {
            $query->whereIn('id', $only);
        }
        $playlists = $query->get();

        $affected = 0;
        $totalRemoved = 0;

        foreach ($playlists as $pl) {
            if (! PlaylistStore::existsFor($pl->id)) {
                continue; // no channel store on disk — nothing to prune
            }

            $store = new PlaylistStore($pl->id);
            $removedHere = 0;

            foreach ($store->pointerProviderIds() as $pid) {
                // SAFETY GUARD: only reconcile against a real, populated provider store.
                // A missing or empty store may just be mid-import; treating its channels as
                // "gone" would delete every pointer and blank the playlist.
                if (! ProviderStore::exists($pid) || ProviderStore::channelCountFor($pid) === 0) {
                    $this->warn("playlist {$pl->id} \"{$pl->name}\": provider {$pid} store missing/empty — skipped (not pruned)");

                    continue;
                }

                $ps = new ProviderStore($pid);
                $removedHere += $dry
                    ? $store->missingPointerCount($pid, $ps)
                    : $store->reconcileProvider($pid, $ps);
            }

            if ($removedHere > 0) {
                $affected++;
                $totalRemoved += $removedHere;
                $verb = $dry ? 'would remove' : 'removed';
                $this->info("playlist {$pl->id} \"{$pl->name}\": {$verb} {$removedHere} missing channel(s)");
            }
        }

        $this->info($dry
            ? "Dry run complete: would remove {$totalRemoved} missing channel(s) from {$affected} playlist(s)."
            : "Pruned {$totalRemoved} missing channel(s) from {$affected} playlist(s).");

        return self::SUCCESS;
    }
}
