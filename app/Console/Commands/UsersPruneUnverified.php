<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UsersPruneUnverified extends Command
{
    protected $signature = 'users:prune-unverified {--days=14 : Grace period after registration} {--dry-run : Report without deleting}';

    protected $description = 'Delete accounts that never verified their email within N days (default 14) of registering. Admins (is_admin) are always protected. Deleting an account cascades its providers and playlists and queues its feed stores for cleanup (feed:purge). Manually created accounts are verified on creation, so they are never affected.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $stale = User::whereNull('email_verified_at')
            ->where('is_admin', false)          // SAFETY: never delete an admin, whatever its verify state
            ->where('created_at', '<', $cutoff) // grace period from registration
            ->orderBy('id')
            ->get();

        $count = 0;
        foreach ($stale as $u) {
            $age = $u->created_at ? $u->created_at->diffForHumans() : 'unknown age';
            $this->line(($dry ? 'would delete' : 'deleted')." user {$u->id} <{$u->email}> — registered {$age}, never verified");

            if (! $dry) {
                $u->delete(); // fires User::deleting (queues store cleanup) + DB cascade of providers/playlists
            }
            $count++;
        }

        $this->info($dry
            ? "Dry run: {$count} unverified account(s) would be deleted (registered > {$days}d ago)."
            : "Deleted {$count} unverified account(s) (registered > {$days}d ago).");

        return self::SUCCESS;
    }
}
