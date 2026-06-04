<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the per-run feed log table from growing unbounded.
Schedule::command('feed:trim')->weekly();

// Clean up data left by deleted accounts (per-provider SQLite stores). Infrequent.
Schedule::command('feed:purge')->hourly();
