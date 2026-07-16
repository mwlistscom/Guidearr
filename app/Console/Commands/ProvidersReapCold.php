<?php

namespace App\Console\Commands;

use App\Models\Provider;
use Illuminate\Console\Command;

class ProvidersReapCold extends Command
{
    protected $signature = 'providers:reap-cold {--days=14 : Inactivity threshold} {--dry-run : Report without disabling}';

    protected $description = 'Disable (never delete) providers gone cold — no playlist serve and no dashboard activity for N days (default 14), which also covers providers with no playlist at all. Their feed store and DB row are kept, and a later serve or edit of a backing playlist auto-re-enables them. Providers disabled by fetch failures or an admin are left untouched.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        // Only currently-enabled providers are candidates, so already-disabled ones (failed/admin/
        // previously-reaped) are never re-processed. Cold = never touched, or not since the cutoff.
        $cold = Provider::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('last_touch_at')->orWhere('last_touch_at', '<', $cutoff))
            ->orderBy('id')
            ->get();

        $count = 0;
        foreach ($cold as $p) {
            $last = $p->last_touch_at ? $p->last_touch_at->diffForHumans() : 'never touched';
            $this->line(($dry ? 'would disable' : 'disabled')." provider {$p->id} \"{$p->name}\" (owner #{$p->user_id}) — last touch: {$last}");

            if (! $dry) {
                $p->forceFill(['enabled' => false, 'last_status' => Provider::REAPED_STATUS])->save();
            }
            $count++;
        }

        $this->info($dry
            ? "Dry run: {$count} cold provider(s) would be disabled (inactive > {$days}d)."
            : "Disabled {$count} cold provider(s) (inactive > {$days}d). Data kept; re-enabled automatically on next access.");

        return self::SUCCESS;
    }
}
