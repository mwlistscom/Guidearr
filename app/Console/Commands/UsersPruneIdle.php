<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlaylistStore;
use Illuminate\Console\Command;

/**
 * Delete accounts that signed up, never set anything up, and have sat that way.
 *
 * Registration is open by default, so drive-by signups accumulate: an account with no provider
 * and nothing in its playlists is a row, a session and (once seeded) a stray SQLite file that
 * will never be used. This clears them out.
 *
 * "Never set anything up" is deliberately read as **no providers AND no non-empty playlist** —
 * both, not either. A user with real providers is never a candidate, however empty one of their
 * playlists happens to be, and a user with a curated playlist is never a candidate either.
 * The looser reading would delete working accounts.
 */
class UsersPruneIdle extends Command
{
    protected $signature = 'users:prune-idle
        {--days=30 : How long an account must have been idle since registering}
        {--dry-run : Report without deleting}';

    protected $description = 'Delete non-admin accounts older than N days (default 30) that have no providers and no playlist containing any channels. Admins are always protected, as is any account with a provider or a non-empty playlist. Deleting cascades the account\'s playlists and queues its feed stores for cleanup (feed:purge). Destructive — run with --dry-run first.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $candidates = User::query()
            ->where('is_admin', false)            // SAFETY: never delete an admin, as prune-unverified does
            ->where('created_at', '<', $cutoff)   // old enough to have got round to it
            ->doesntHave('providers')             // SAFETY: any provider at all disqualifies
            ->orderBy('id')
            ->get();

        $count = 0;
        $skipped = 0;

        foreach ($candidates as $u) {
            $playlists = $u->playlists()->get(['id', 'name']);

            // A playlist row on its own is not "set up" — an empty one is what the editor leaves
            // behind after a create with nothing added. Anything with channels protects the account.
            $used = $playlists->first(fn ($p) => $this->channelCount($p->id) > 0);

            if ($used !== null) {
                $skipped++;
                $this->line("  keeping user {$u->id} <{$u->email}> — playlist {$used->id} \"{$used->name}\" has channels");

                continue;
            }

            // Exact days, not diffForHumans(): Carbon shows 30.9 days as "1 month ago", which on
            // a preview of a 30-day threshold reads like it is deleting something too new.
            $age = $u->created_at
                ? number_format($u->created_at->diffInDays(now()), 1).'d ago ('.$u->created_at->toDateString().')'
                : 'unknown age';
            $what = $playlists->isEmpty()
                ? 'no providers, no playlists'
                : 'no providers, '.$playlists->count().' empty playlist(s)';

            $this->line(($dry ? 'would delete' : 'deleted')." user {$u->id} <{$u->email}> — registered {$age}, {$what}");

            if (! $dry) {
                $u->delete(); // fires User::deleting (queues store cleanup) + DB cascade
            }
            $count++;
        }

        $this->info($dry
            ? "Dry run: {$count} idle account(s) would be deleted (registered > {$days}d ago, nothing set up); {$skipped} kept."
            : "Deleted {$count} idle account(s) (registered > {$days}d ago, nothing set up); {$skipped} kept.");

        return self::SUCCESS;
    }

    /**
     * Channels in a playlist's store, without creating one.
     *
     * A missing store file means nothing was ever seeded, which counts as empty — opening the
     * store to ask would seed it, writing a file for an account we are about to delete.
     */
    private function channelCount(int $playlistId): int
    {
        if (! PlaylistStore::existsFor($playlistId)) {
            return 0;
        }

        try {
            return (int) (new PlaylistStore($playlistId))->counts()['channels'];
        } catch (\Throwable $e) {
            // An unreadable store is not evidence the account is idle. Report it as in use so a
            // damaged file can never be the reason an account gets deleted.
            report($e);

            return 1;
        }
    }
}
