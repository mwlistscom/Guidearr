<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Console\Command;

class PlaylistsBackfill extends Command
{
    protected $signature = 'playlists:backfill {ids?* : Only these playlist IDs (default: all)} {--dry-run : Report what would be added without writing}';

    protected $description = 'Additively re-seed playlists from their attached providers, adding channels/groups that were never seeded (e.g. a playlist created while its provider was still importing). Safe & idempotent: INSERT OR IGNORE on the (provider_id, channel_id) unique index means it never duplicates, never resurrects deliberately-deleted channels, and preserves existing ordering, renames and group flags.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_map('intval', (array) $this->argument('ids'));

        $query = Playlist::orderBy('id');
        if ($only) {
            $query->whereIn('id', $only);
        }
        $playlists = $query->get();

        $repaired = 0;
        $totalChannels = 0;

        foreach ($playlists as $pl) {
            $pids = $pl->providerIds();
            if (! $pids) {
                continue; // manual playlist — nothing to seed from
            }
            if (! PlaylistStore::existsFor($pl->id)) {
                // A missing file is handled by ensureStoreSeeded on next serve; skip here.
                $this->line("playlist {$pl->id} \"{$pl->name}\": no channel store on disk, skipped");

                continue;
            }

            $store = new PlaylistStore($pl->id);
            $before = $store->counts()['channels'];

            if ($dry) {
                $provTotal = 0;
                foreach ($pids as $pid) {
                    $provTotal += ProviderStore::channelCountFor((int) $pid);
                }
                $gap = max(0, $provTotal - $before);
                $note = $gap > 0 ? "up to {$gap} channel(s) would be added" : 'already complete';
                $this->line("playlist {$pl->id} \"{$pl->name}\": has {$before} of {$provTotal} provider channels — {$note}");

                continue;
            }

            $added = 0;
            foreach ($pids as $pid) {
                if (! ProviderStore::exists((int) $pid)) {
                    continue; // provider never imported yet
                }
                $r = $store->seedFromProvider((int) $pid, new ProviderStore((int) $pid));
                $added += $r['channels_added'];
            }

            if ($added > 0) {
                $repaired++;
                $totalChannels += $added;
                $this->info("playlist {$pl->id} \"{$pl->name}\": added {$added} channel(s) ({$before} → ".($before + $added).')');
            } else {
                $this->line("playlist {$pl->id} \"{$pl->name}\": already complete, no change");
            }
        }

        $this->info($dry
            ? 'Dry run complete.'
            : "Backfilled {$repaired} playlist(s); {$totalChannels} channel(s) added.");

        return self::SUCCESS;
    }
}
