<?php

namespace App\Console\Commands;

use App\Support\ThreatFeed;
use Illuminate\Console\Command;

/**
 * Rebuild the firewall-facing threat feed from the nginx access logs.
 *
 * Runs hourly from the scheduler. The HTTP route reads the file this writes, and
 * rebuilds it itself if it is missing or stale — so nothing here has to be run by
 * hand after an install or an upgrade.
 */
class SecurityThreatFeed extends Command
{
    protected $signature = 'security:threat-feed';

    protected $description = 'Rebuild the threat feed (hostile IPs) from the nginx access logs';

    public function handle(): int
    {
        $stats = ThreatFeed::rebuild();

        $this->info(sprintf(
            'Threat feed: %d address(es) listed from %d log line(s); %d host(s) protected as playlist clients.',
            $stats['listed'],
            $stats['scanned'],
            $stats['protected'],
        ));

        return self::SUCCESS;
    }
}
