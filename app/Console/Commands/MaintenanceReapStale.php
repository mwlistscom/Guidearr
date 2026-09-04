<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Models\Provider;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Delete playlists and providers nothing has touched for N days.
 *
 * This is the second, permanent stage behind `providers:reap-cold`, which only ever *disables*
 * a provider at 14 days and lets any access revive it. Here the row and its SQLite store go for
 * good.
 *
 * What "touched" means is the same signal the rest of the app uses: `last_touch_at`, written
 * only by human activity — opening the editor, editing, or a player pulling the m3u/EPG.
 * `Playlist::markTouched()` stamps the playlist *and every provider behind it*, so a playlist
 * anyone is still using keeps its providers warm too, and a provider that has gone stale really
 * does have nothing serving it.
 *
 * Two things make this safe to run unattended, and both matter:
 *
 *  - **Playlists are deleted before providers**, so a provider whose only playlist just went
 *    becomes eligible in the same run rather than lingering for another week.
 *  - **A provider still referenced by a surviving playlist is never deleted**, even if its own
 *    `last_touch_at` is stale. Deleting it would orphan that playlist's pointers into
 *    "(missing channel)" rows — silently breaking a playlist that is still in use. The check is
 *    made against the state *after* the playlist deletions, not before.
 */
class MaintenanceReapStale extends Command
{
    protected $signature = 'maintenance:reap-stale
        {--days=60 : Inactivity threshold}
        {--dry-run : Report without deleting}';

    protected $description = 'Permanently delete playlists and providers with no access for N days (default 60), along with their SQLite stores. Providers still attached to a surviving playlist are never deleted. Activity means human activity — an editor visit or a player pulling the playlist. Destructive and irreversible: a deleted playlist takes its ordering, group flags and renames with it. Run with --dry-run first.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $stale = fn ($q) => $q->where(fn ($w) => $w
            ->whereNull('last_touch_at')
            ->orWhere('last_touch_at', '<', $cutoff));

        // --- playlists first ---------------------------------------------------------------
        // A never-touched playlist is only a candidate once it is older than the window itself,
        // so one created this morning is not reaped before anyone has had the chance to use it.
        $playlists = Playlist::query()
            ->where($stale)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $deletedPlaylists = [];

        foreach ($playlists as $p) {
            $last = $this->lastAccess($p->last_touch_at);
            $this->line(($dry ? 'would delete' : 'deleted')." playlist {$p->id} \"{$p->name}\" (owner #{$p->user_id}) — last access: {$last}");

            if (! $dry) {
                $p->delete(); // Playlist::deleting removes its SQLite store
            }
            $deletedPlaylists[] = $p->id;
        }

        // --- then providers ----------------------------------------------------------------
        $providers = Provider::query()
            ->where($stale)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $deletedProviders = 0;
        $keptProviders = 0;

        foreach ($providers as $p) {
            // On a dry run the playlists above are still present, so discount the ones that
            // would have gone — otherwise the preview under-reports what a real run would delete.
            $holders = $p->playlists()
                ->when($dry && $deletedPlaylists, fn ($q) => $q->whereNotIn('playlists.id', $deletedPlaylists))
                ->get(['playlists.id', 'playlists.name']);

            $guideFor = Playlist::where('guide_provider_id', $p->id)
                ->when($dry && $deletedPlaylists, fn ($q) => $q->whereNotIn('id', $deletedPlaylists))
                ->get(['id', 'name']);

            $attached = $holders->concat($guideFor);

            if ($attached->isNotEmpty()) {
                $names = $attached->take(3)->map(fn ($x) => "{$x->id} \"{$x->name}\"")->implode(', ');
                $more = $attached->count() > 3 ? ' +'.($attached->count() - 3).' more' : '';
                $this->line("  keeping provider {$p->id} \"{$p->name}\" — still attached to playlist {$names}{$more}");
                $keptProviders++;

                continue;
            }

            $last = $this->lastAccess($p->last_touch_at);
            $this->line(($dry ? 'would delete' : 'deleted')." provider {$p->id} \"{$p->name}\" (owner #{$p->user_id}) — last access: {$last}");

            if (! $dry) {
                $p->delete(); // Provider::deleting removes its SQLite store and feed logs
            }
            $deletedProviders++;
        }

        $pc = count($deletedPlaylists);
        $this->info($dry
            ? "Dry run: {$pc} playlist(s) and {$deletedProviders} provider(s) would be deleted (no access > {$days}d); {$keptProviders} provider(s) kept because a playlist still uses them."
            : "Deleted {$pc} playlist(s) and {$deletedProviders} provider(s) (no access > {$days}d); {$keptProviders} provider(s) kept because a playlist still uses them.");

        return self::SUCCESS;
    }

    /**
     * Exact days, not diffForHumans().
     *
     * Carbon renders 60.9 days as "1 month ago", which on a preview of a 60-day threshold reads
     * as though the command is about to delete something well inside the window. On a destructive
     * command the operator has to be able to check the arithmetic.
     */
    private function lastAccess(?CarbonInterface $at): string
    {
        return $at === null
            ? 'never accessed'
            : number_format($at->diffInDays(now()), 1).' days ago ('.$at->toDateString().')';
    }
}
