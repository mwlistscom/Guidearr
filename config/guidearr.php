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

    // Background feed downloader limits (all overridable via .env).
    'feed' => [
        'max_bytes'        => (int) env('FEED_MAX_BYTES', 1288490188), // ~1.2 GB hard cap
        'connect_timeout'  => (int) env('FEED_CONNECT_TIMEOUT', 30),   // seconds
        'timeout'          => (int) env('FEED_TIMEOUT', 1200),         // seconds (20 min)
        'verify_tls'       => (bool) env('FEED_VERIFY_TLS', false),    // many IPTV servers have bad/no TLS
        'max_errors'       => (int) env('FEED_MAX_ERRORS', 4),         // at this error count: delete job + disable provider
        'orphan_minutes'   => (int) env('FEED_ORPHAN_MINUTES', 60),    // running longer than this = orphan -> requeue + error++
        'user_agent'       => env('FEED_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'),
    ],
];
