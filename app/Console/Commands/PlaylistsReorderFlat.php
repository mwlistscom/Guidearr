<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\PlaylistStore;
use Illuminate\Console\Command;

class PlaylistsReorderFlat extends Command
{
    protected $signature = 'playlists:reorder-flat {--dry-run : List playlists without renumbering}';

    protected $description = 'One-time flatten: renumber each playlist\'s channels into a single global 10/20/30 sequence, preserving the current (group-then-position) on-screen order. Run once after switching to flat ordering.';

    public function handle(): int
    {
        $ids = Playlist::orderBy('id')->pluck('id');
        $dry = (bool) $this->option('dry-run');
        $done = 0;

        foreach ($ids as $id) {
            if (! PlaylistStore::existsFor($id)) {
                $this->line("playlist {$id}: no channel store, skipped");
                continue;
            }
            if ($dry) {
                $this->line("playlist {$id}: would flatten");
                continue;
            }
            $n = (new PlaylistStore($id))->reindexChannels(true); // byGroup = preserve current order
            $this->info("playlist {$id}: renumbered {$n} channels");
            $done++;
        }

        $this->info($dry ? 'Dry run complete.' : "Flattened {$done} playlist(s).");

        return self::SUCCESS;
    }
}
