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
    ],
    'registration_requires_approval' => env('REGISTRATION_REQUIRES_APPROVAL', false),

    // Update check: the admin Status page pings the GitHub Releases API (cached 6h)
    // and shows an alert when a newer version is published. Set GUIDEARR_UPDATE_CHECK=false
    // to disable the outbound call entirely.
    'update_check' => (bool) env('GUIDEARR_UPDATE_CHECK', true),
    'github_repo'  => env('GUIDEARR_GITHUB_REPO', 'mwlistscom/Guidearr'),

    // Rate limits on the public auth endpoints, in attempts per window per key.
    // Deliberately generous for a human and hostile to a script: a real person signs up
    // once and mistypes a password a handful of times. Raise them if you front the app
    // with a shared corporate NAT where many genuine users share one address.
    'auth_limits' => [
        'login_per_account' => (int) env('AUTH_LIMIT_LOGIN_PER_ACCOUNT', 5),   // per minute, per email+ip
        'login_per_ip' => (int) env('AUTH_LIMIT_LOGIN_PER_IP', 20),            // per minute, per ip
        'register_per_minute' => (int) env('AUTH_LIMIT_REGISTER_PER_MINUTE', 5),
        'register_per_hour' => (int) env('AUTH_LIMIT_REGISTER_PER_HOUR', 15),
        'password_email_per_ip' => (int) env('AUTH_LIMIT_PASSWORD_EMAIL_PER_IP', 5),      // per minute
        'password_email_per_account' => (int) env('AUTH_LIMIT_PASSWORD_EMAIL_PER_ACCOUNT', 5), // per hour
        'password_update_per_ip' => (int) env('AUTH_LIMIT_PASSWORD_UPDATE_PER_IP', 10),   // per minute
        // Social sign-in. The provider callback handles SIGN-IN as well as sign-up, so this
        // one has to stay loose — several people behind one office or carrier NAT all signing
        // in with Google must not lock each other out. It only stops hammering.
        'oauth_callback_per_ip' => (int) env('AUTH_LIMIT_OAUTH_CALLBACK_PER_IP', 30),     // per minute
        // The control that actually matters: how many NEW accounts one address may
        // auto-provision through a provider in an hour. Signing in to an existing account is
        // never counted, so this is invisible to returning users.
        'oauth_new_accounts_per_ip' => (int) env('AUTH_LIMIT_OAUTH_NEW_ACCOUNTS_PER_IP', 10), // per hour
    ],

    // Threat feed: a plain-text list of IPs caught probing this install, shaped for a
    // firewall (pfBlockerNG and friends) to poll as a custom IPv4/IPv6 list.
    //
    // The switch, the secret URL and the listing threshold live in the settings store and
    // are edited under Admin -> Configuration — they are NOT here, so a fresh install or an
    // upgrade needs nothing run by hand. These are the advanced knobs only.
    'threat_feed' => [
        // How far back to look. An address is listed only while it has recent hostile
        // hits in this window and drops off on the next rebuild once it goes quiet —
        // nothing is ever banned permanently, and nothing accumulates between rebuilds.
        //
        // The REAL bound is usually log retention, not this number: docker/nginx-logrotate.sh
        // trims the access log by SIZE (15 MB -> keep 5 MB), so a busy install may only hold
        // a few days of history and addresses age out sooner than the value here. Raising
        // this past what the logs keep buys nothing; raise NGINX_LOG_MAX_BYTES too if you
        // genuinely want a longer memory.
        'window_days' => (int) env('THREAT_FEED_WINDOW_DAYS', 14),
        // Hard cap, so a log flood cannot turn into a firewall rule explosion.
        'max_entries' => (int) env('THREAT_FEED_MAX_ENTRIES', 5000),
        // Rebuild at most this often when a request finds the cached file stale. The
        // scheduler also refreshes hourly; this is what makes the very first fetch work.
        'min_rebuild_seconds' => (int) env('THREAT_FEED_MIN_REBUILD_SECONDS', 900),
        // Comma-separated IPs/CIDRs never to emit, on top of the built-in exclusions
        // (private/reserved space, and any host that has served a playlist).
        'allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('THREAT_FEED_ALLOWLIST', '')),
        ), static fn ($v) => $v !== '')),
    ],

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

    // Xtream provider behaviour.
    'xtream' => [
        // Editing an Xtream provider's URL/username/password rewrites its stored
        // channel URLs in place (keeping channel IDs stable so playlists survive).
        // Before doing so it downloads the channel list with the new credentials and
        // requires at least this fraction of the existing stream_ids to still match —
        // a guard against a wrong account or a typo wiping every playlist pointer.
        'credential_match_threshold' => (float) env('XTREAM_CREDENTIAL_MATCH_THRESHOLD', 0.70),
    ],

    // M3U provider behaviour.
    'm3u' => [
        // Changing an M3U provider's URL matches the new list to the current channels by
        // tvg-id (fallback name+group) and rewrites matched channel URLs in place (keeping
        // channel IDs stable so playlists survive). It requires at least this fraction to
        // match — otherwise the new URL is treated as a different provider and rejected.
        'url_match_threshold' => (float) env('M3U_URL_MATCH_THRESHOLD', 0.70),
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
