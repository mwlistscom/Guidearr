<?php

use App\Support\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Enqueue provider refreshes the moment each provider's per-provider daily time arrives.
Schedule::command('feed:due')->everyMinute()->withoutOverlapping();

// Maintenance jobs run through `maintenance:run <key>` so each writes BEGIN/output/END to the
// dedicated maintenance log (Admin → Logs → maintenance.log), separate from the worker log.

// Keep the per-run feed log table from growing unbounded.
Schedule::command('maintenance:run trim')->weekly();

// Reclaim SQLite free-page bloat in the feed stores (mark-sweep + guide-reload churn). Infrequent.
Schedule::command('maintenance:run vacuum')->weekly()->withoutOverlapping();

// Clean up data left by deleted accounts (per-provider SQLite stores). Infrequent.
Schedule::command('maintenance:run purge')->hourly();

// Daily maintenance: stop refreshing feeds nobody views. Disables (keeps data for) providers with
// no playlist access or dashboard activity for 14 days; a later access auto-re-enables them.
Schedule::command('maintenance:run reap')->daily();

// Daily maintenance: delete accounts that never verified their email within 14 days of registering
// (admins are always protected). Cascades their providers/playlists and queues store cleanup.
Schedule::command('maintenance:run prune-unverified')->daily();

// Weekly maintenance: PERMANENTLY delete playlists and providers nothing has accessed for 60 days.
// This is the stage after `reap`, which only disables a provider at 14 days and revives it on
// access — here the row and its SQLite store go for good, and a deleted playlist takes its
// ordering, group flags and renames with it. Playlists are deleted before providers so a provider
// whose last playlist just went is collected in the same run, and a provider still attached to a
// surviving playlist is always kept (deleting it would orphan that playlist into "(missing
// channel)" rows). Runs unattended, so the guards matter more than the reporting.
Schedule::command('maintenance:run reap-stale')->weekly();

// Rebuild the firewall-facing blocklist of hostile IPs, so the HTTP route only ever reads a
// static file. Skipped entirely unless the feed is switched on.
Schedule::command('security:threat-feed')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => Settings::threatFeedEnabled());
