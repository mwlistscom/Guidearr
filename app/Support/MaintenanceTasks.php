<?php

namespace App\Support;

use App\Models\FeedQueue;

/**
 * Registry of maintenance tasks the admin panel knows about. A single source of truth shared by the
 * Maintenance page (display + which are runnable), the `maintenance:run` command (what to execute)
 * and the scheduler. `manual` tasks get a "Run now" button; `destructive` ones are listed read-only.
 */
class MaintenanceTasks
{
    /**
     * @return array<string, array{label:string, schedule:string, desc:string, manual:bool,
     *     destructive?:bool, command?:string, args?:array<string,mixed>, callback?:callable}>
     */
    public static function all(): array
    {
        return [
            'health' => [
                'label' => 'Health check', 'schedule' => 'host heartbeat', 'manual' => true,
                'desc' => 'Probe DB, worker heartbeat, queue depth and refresh staleness.',
                'command' => 'health:check', 'args' => ['--format' => 'human'],
            ],
            'reclaim' => [
                'label' => 'Reclaim stuck jobs', 'schedule' => 'each supervisor loop', 'manual' => true,
                'desc' => 'Reset jobs stuck "running" past the orphan window back to queued.',
                'callback' => fn () => 'Reclaimed '.FeedQueue::reclaimOrphans().' orphaned job(s).',
            ],
            'purge' => [
                'label' => 'Purge deleted-account stores', 'schedule' => 'hourly', 'manual' => true,
                'desc' => 'Delete leftover SQLite store files for deleted accounts.',
                'command' => 'feed:purge',
            ],
            'trim' => [
                'label' => 'Trim old feed logs', 'schedule' => 'weekly', 'manual' => true,
                'desc' => 'Delete feed_logs rows older than 14 days.',
                'command' => 'feed:trim',
            ],
            'vacuum' => [
                'label' => 'Vacuum feed stores', 'schedule' => 'weekly', 'manual' => true,
                'desc' => 'SQLite VACUUM of provider stores to reclaim free-page bloat. Can take a while.',
                'command' => 'feed:vacuum',
            ],
            'reap' => [
                'label' => 'Reap cold providers', 'schedule' => 'daily', 'manual' => true,
                'desc' => 'Disable (never delete) enabled providers untouched for 14 days. Auto-revive on access.',
                'command' => 'providers:reap-cold',
            ],
            'prune-unverified' => [
                'label' => 'Prune unverified users', 'schedule' => 'daily', 'manual' => true,
                'destructive' => true, 'dryRun' => true,
                'desc' => 'Deletes non-admin accounts that never verified their email within 14 days.',
                'command' => 'users:prune-unverified',
            ],
            'prune-missing' => [
                'label' => 'Prune missing channels', 'schedule' => 'on refresh', 'manual' => true,
                'destructive' => true, 'dryRun' => true,
                'desc' => 'Removes "(missing channel)" pointers from playlists after a provider drops/rotates channels.',
                'command' => 'playlists:prune-missing',
            ],
            'prune-idle' => [
                'label' => 'Prune idle accounts', 'schedule' => 'manual only', 'manual' => true,
                'destructive' => true, 'dryRun' => true,
                'desc' => 'Deletes non-admin accounts older than 30 days that have no providers and no playlist with any channels. Any provider, or one non-empty playlist, protects the account.',
                'command' => 'users:prune-idle',
            ],
            'reap-stale' => [
                'label' => 'Reap stale playlists & providers', 'schedule' => 'weekly', 'manual' => true,
                'destructive' => true, 'dryRun' => true,
                'desc' => 'Permanently deletes playlists and providers with no access for 60 days, and their stores. A provider still attached to a surviving playlist is kept. Irreversible — a deleted playlist takes its ordering and renames with it.',
                'command' => 'maintenance:reap-stale',
            ],
        ];
    }

    /** Non-destructive tasks — a single "Run now" button. */
    public static function safe(): array
    {
        return array_filter(self::all(), fn ($t) => ($t['manual'] ?? false) && ! ($t['destructive'] ?? false));
    }

    /** Destructive tasks — run dry-first, then an explicit "Apply for real". */
    public static function destructive(): array
    {
        return array_filter(self::all(), fn ($t) => ($t['manual'] ?? false) && ($t['destructive'] ?? false));
    }
}
