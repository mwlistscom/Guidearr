<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Enqueue provider refreshes the moment each provider's per-provider daily time arrives.
Schedule::command('feed:due')->everyMinute()->withoutOverlapping();

// Keep the per-run feed log table from growing unbounded.
Schedule::command('feed:trim')->weekly();

// Reclaim SQLite free-page bloat in the feed stores (mark-sweep + guide-reload churn). Infrequent.
Schedule::command('feed:vacuum')->weekly()->withoutOverlapping();

// Clean up data left by deleted accounts (per-provider SQLite stores). Infrequent.
Schedule::command('feed:purge')->hourly();

// Daily maintenance: stop refreshing feeds nobody views. Disables (keeps data for) providers with
// no playlist access or dashboard activity for 14 days; a later access auto-re-enables them.
Schedule::command('providers:reap-cold')->daily();

// Daily maintenance: delete accounts that never verified their email within 14 days of registering
// (admins are always protected). Cascades their providers/playlists and queues store cleanup.
Schedule::command('users:prune-unverified')->daily();
