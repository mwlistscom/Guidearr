<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete expired rows from the database cache store.
 *
 * Laravel's database cache driver never garbage-collects: an expired row is only removed when
 * something reads that exact key again. Most of what this app caches is a per-IP rate-limiter
 * bucket, written once by a scanner that never comes back, so the table grows for ever — on the
 * reference install 2,015 of 2,016 rows were expired, the oldest by eleven weeks. The login
 * throttle keys also carry the email address that was tried, so leaving them around keeps data
 * longer than it needs to be kept.
 *
 * Only ever touches rows whose expiration has passed, so it can never evict a live entry, and it
 * does nothing when the default cache store is not the database one.
 */
class CachePruneExpired extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete expired rows from the database cache store (and expired cache locks). The database driver never garbage-collects on its own, so rate-limiter buckets accumulate indefinitely.';

    public function handle(): int
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");

        if ($driver !== 'database') {
            $this->info("cache:prune-expired: default cache store '{$store}' is '{$driver}', not database — nothing to prune.");

            return self::SUCCESS;
        }

        $table = (string) (config("cache.stores.{$store}.table") ?: 'cache');
        $lockTable = (string) (config("cache.stores.{$store}.lock_table") ?: $table.'_locks');
        $connection = config("cache.stores.{$store}.connection");
        $now = now()->getTimestamp();

        // The driver stores expiration as a unix timestamp and treats `expiration <= now` as gone.
        $rows = DB::connection($connection)->table($table)->where('expiration', '<=', $now)->delete();
        $locks = DB::connection($connection)->table($lockTable)->where('expiration', '<=', $now)->delete();
        $left = DB::connection($connection)->table($table)->count();

        $this->info("cache:prune-expired removed {$rows} expired cache row(s) and {$locks} expired lock(s); {$left} live row(s) remain.");

        return self::SUCCESS;
    }
}
