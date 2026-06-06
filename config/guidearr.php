<?php
return [
    // Application version. Single source of truth is the VERSION file at the
    // project root — bump it on every change (especially before pushing to
    // GitHub). Shown on the admin Status page.
    'version' => trim(@file_get_contents(base_path('VERSION')) ?: '') ?: 'dev',

    'admin' => [
        // URL segment for the admin panel: 'admin' => /admin. Override with ADMIN_PATH
        // to a hard-to-guess value to reduce automated probing of /admin.
        'path' => env('ADMIN_PATH', 'admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'registration_requires_approval' => env('REGISTRATION_REQUIRES_APPROVAL', false),

    // Update check: the admin Status page pings the GitHub Releases API (cached 6h)
    // and shows an alert when a newer version is published. Set GUIDEARR_UPDATE_CHECK=false
    // to disable the outbound call entirely.
    'update_check' => (bool) env('GUIDEARR_UPDATE_CHECK', true),
    'github_repo'  => env('GUIDEARR_GITHUB_REPO', 'mwlistscom/Guidearr'),

    // Background feed downloader limits (all overridable via .env).
    'feed' => [
        'max_bytes'        => (int) env('FEED_MAX_BYTES', 1288490188), // ~1.2 GB hard cap
        'connect_timeout'  => (int) env('FEED_CONNECT_TIMEOUT', 30),   // seconds
        'timeout'          => (int) env('FEED_TIMEOUT', 1200),         // seconds (20 min) — absolute cap for a *progressing* transfer
        // Abort a STALLED transfer fast: if throughput stays below low_speed_limit
        // bytes/sec for low_speed_time seconds, cURL gives up. This is what stops a
        // dead/hung upstream (e.g. an Xtream server that accepts the connection then
        // trickles nothing) from holding a worker for the full 20-minute cap.
        'low_speed_limit'  => (int) env('FEED_LOW_SPEED_LIMIT', 1024), // bytes/sec
        'low_speed_time'   => (int) env('FEED_LOW_SPEED_TIME', 60),    // seconds below the limit before aborting
        'verify_tls'       => (bool) env('FEED_VERIFY_TLS', false),    // many IPTV servers have bad/no TLS
        'max_errors'       => (int) env('FEED_MAX_ERRORS', 4),         // at this error count: delete job + disable provider
        'orphan_minutes'   => (int) env('FEED_ORPHAN_MINUTES', 60),    // running longer than this = orphan -> requeue + error++
        'user_agent'       => env('FEED_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'),
    ],

    // health:check thresholds (read by the artisan health probe + the host heartbeat).
    'health' => [
        // The worker writes storage/app/health/worker.beat every poll (~every 'sleep'
        // seconds when idle). Older than this => the worker is wedged or dead.
        'worker_stale_seconds'  => (int) env('HEALTH_WORKER_STALE', 180),
        // An enabled provider that hasn't refreshed within this many hours is flagged
        // (daily cadence + slack). 0 disables the staleness check.
        'refresh_max_age_hours' => (int) env('HEALTH_REFRESH_MAX_AGE_HOURS', 26),
    ],
];
