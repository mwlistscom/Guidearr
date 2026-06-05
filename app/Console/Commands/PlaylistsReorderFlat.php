<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\PlaylistStore;
use Illuminate\Console\Command;

class PlaylistsReorderFlat extends Command
{
    protected $signature = 'playlists:reorder-flat {--dry-run : List what would change without writing} {--force : Re-flatten even playlists already flat (re-groups by group order — destroys manual ordering)}';

    protected $description = 'One-time flatten of legacy per-group channel numbering into a single global 10/20/30 sequence. Idempotent: playlists already flat are skipped, so it is safe to re-run and never clobbers a manual ordering. Use --force only to deliberately re-group.';

    public function handle(): int
    {
        $ids     = Playlist::orderBy('id')->pluck('id');
        $dry     = (bool) $this->option('dry-run');
        $force   = (bool) $this->option('force');
        $done    = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            if (! PlaylistStore::existsFor($id)) {
                $this->line("playlist {$id}: no channel store, skipped");
                continue;
            }
            $store = new PlaylistStore($id);

            if (! $force && ! $store->needsFlatten()) {
                $this->line("playlist {$id}: already flat, skipped");
                $skipped++;
                continue;
            }
            if ($dry) {
                $this->line("playlist {$id}: would flatten" . ($force ? ' (forced)' : ''));
                continue;
            }

            $n = $store->reindexChannels(true); // byGroup = lay out by the old group-then-position order
            $this->info("playlist {$id}: flattened {$n} channels");
            $done++;
        }

        $this->info($dry ? 'Dry run complete.' : "Flattened {$done} playlist(s); {$skipped} already flat.");

        return self::SUCCESS;
    }
}
