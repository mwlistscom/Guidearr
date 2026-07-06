# Changelog

All notable changes to **Guidearr** since v1.18. Newest first.

> **Tagged public releases:** v1.20.0, v1.22.3, v1.22.5, v1.22.6, v1.22.7, v1.22.8, v1.22.9, v1.22.10, v1.22.11 and v1.22.12.
> Intermediate entries (1.21.0–1.22.2, 1.22.4) were development iterations rolled into the next tagged release.

---

## v1.22.12 — Worker activity in the admin Logs page · 2026-07-06

**Added**
- **`worker.log` on the admin Logs page.** The background feed worker is a separate daemon whose
  output previously only reached the container's stdout (`docker logs`). It now writes lifecycle and
  one-line activity to a dedicated `worker` log channel (`storage/logs/worker.log`), which the Logs
  page lists automatically — so you can watch the worker from the admin without shell access. It
  records the supervisor start/stop, each spawn/scale decision (backlog, running, limit → workers
  started), per-job claim + `done in Ns` summaries, and failures (with the disable-after-N-errors
  event). Full per-provider detail still lives in the `feed_logs` table (**Admin → Feeds**), and
  worker exceptions still land in `laravel.log`. The file is clearable from the UI and included in
  the downloadable log bundle.

**Upgrade note**
- After `git pull`, restart the worker (`docker compose restart worker`) so the supervisor reloads
  and starts writing its lifecycle lines; per-job lines from worker children appear immediately.

---

## v1.22.11 — Parallel feed workers (Worker Limit) · 2026-07-06

**Added**
- **Configurable Worker Limit** (**Admin → Config → Worker limit**). Run more than one provider
  refresh at a time: a new `feed:supervise` process keeps up to *N* `feed:work` children busy while
  providers are queued and scales the pool back to zero when idle. Default **1** matches the classic
  single-worker behavior; the setting is stored in the settings JSON store and takes effect within a
  few seconds — no restart. Best for parallelising **many** providers refreshing at once (e.g. the
  daily refresh hour); a single large feed is still one job on one worker.

**Internal**
- Feed queue claims already used `SELECT … FOR UPDATE SKIP LOCKED`, so multiple workers never claim
  the same job and each provider writes only its own store. The supervisor owns the liveness
  heartbeat and orphan reclaim, and shuts down gracefully on `SIGTERM` — a child cut off mid-job is
  reset to `queued` and retried, so no work is lost.
- CI: bumped the pinned commit SHAs for the `actions/checkout` and `shivammathur/setup-php` GitHub
  Actions (Dependabot).

**Upgrade note**
- The `worker` container command changed to `php artisan feed:supervise`. After `git pull`, recreate
  it (`docker compose up -d worker`) so it picks up the new command. Cloners get it from the updated
  `docker-compose.yml.example`.

---

## v1.22.10 — Interactive admin recovery & social-account badges · 2026-07-06

**Changed**
- **Admin recovery is now interactive.** `php artisan admin:password` prompts for the email and a
  hidden password (nothing lands on the command line or in shell history), then **creates the admin
  if none exists** (e.g. it was deleted) or **resets** the matching account — re-activating and
  email-verifying it. This replaces the old `admin:sync` command together with the `ADMIN_EMAIL` /
  `ADMIN_PASSWORD` `.env` variables, which are **removed**.

**Security**
- The admin password is no longer kept in plain text in `.env`. Existing installs can delete the
  now-unused `ADMIN_EMAIL` / `ADMIN_PASSWORD` lines — the Environment page no longer lists them, and
  nothing reads them.

**Added**
- **Social-account badges in Admin → Users.** A small **G** (Google) or **F** (Facebook) badge next
  to a user's role marks a linked social identity, so social sign-ins are distinguishable at a glance.

**Fixed**
- The **Continue with Google / Facebook** button text is now white, so it stays readable on the dark
  sign-in and registration pages.

---

## v1.22.9 — Social sign-in (Google & Facebook) · 2026-07-06

**Added**
- **Sign in with Google and Facebook.** Optional OAuth social login (Laravel Socialite) —
  "Continue with Google / Facebook" on the login and registration pages. Accounts are found,
  created, or linked by the provider's verified email; new social users are created already
  email-verified. Requires `php artisan migrate` (adds `social_accounts`, makes `password` nullable).
- **Admin → Social** — configure it without touching `.env`: a per-provider **Enable** toggle,
  Client ID / Secret / Redirect inputs, and on-page setup instructions with the exact callback,
  data-deletion, and privacy URLs to register. Secrets are **encrypted at rest** in the settings
  store and injected into `config('services.*')` at boot.
- **Settings → Connected accounts** — link or disconnect Google/Facebook, and **set a password**
  for social-only accounts so they can also sign in by email (and safely disconnect a provider).
- **Data deletion** — a Facebook data-deletion callback (`/data-deletion/facebook`, required by
  Meta) plus a human-readable, editable **`/data-deletion`** instructions page (Admin → Legal); the
  privacy policy links to it.

**Security**
- Social login re-applies the login guards `Auth::login()` would otherwise bypass: a non-active
  account can't sign in, and a 2FA-enabled account must use password + 2FA.

**Internal**
- The test harness now relocates storage and clears the settings store **before** the app boots, so
  tests can never read or write real production data.

---

## v1.22.8 — Legal pages, email verification & log tooling · 2026-07-06

**Added**
- **Editable legal pages.** Public `/privacy`, `/terms` and `/cookies`, rendered from Markdown and
  editable in **Admin → Legal** (stored in the settings JSON store, with shipped defaults and a
  per-doc "reset to default"). Footer links on the landing and sign-in/registration pages. The
  default privacy policy includes a Google/Facebook sign-in section for the planned social-login
  release. Raw HTML in the source is stripped on render.
- **Email verification by code.** Sign-up email verification now uses a six-digit code (with a
  resend cooldown) instead of a link, plus an admin **Send test email** action on the Environment
  page to check SMTP settings before saving.

**Fixed**
- The Environment page's **Send test email** button no longer disappears when `.env` has no
  `MAIL_PASSWORD` row yet — you configure mail there, so the button is always available.

**Changed**
- The downloadable **log bundle** now also includes rotated siblings (`nginx-access.log.1`,
  `nginx-error.log.2.gz`, …) within the retention window, so a support bundle keeps the full
  recent history even right after a rotation. The viewer already lists every live `*.log`
  (`laravel`, `nginx-access`, `nginx-error`).

---

## v1.22.7 — Playlist reordering fixes & self-healing stores · 2026-07-06

**Fixed**
- **Group reordering now moves the whole group.** `moveGroupToRow` relocates all of a group's
  channels together to the target spot (front of the list for row 1), so a group sent to the top
  actually leads the exported playlist. Replaces the old "largest contiguous run + anchor" logic
  that could leave a group's channels mid-list when the flat order had drifted from the group pane.
- **Manual channel moves are authoritative.** Moving a channel (single or bulk) places it exactly
  where dropped — anywhere in the list, across group boundaries — while keeping its own group
  label. The serve/editor order stays flat on `position_order`; group order never overrides it.

**Added**
- **Self-healing playlist stores.** `Playlist::ensureStoreSeeded()` rebuilds a playlist's channel
  store from its attached providers when the store file is missing (only when missing — an empty
  store may be a deliberate delete-all), wired into the public serve path and the editor. A warning
  is logged when a provider-backed playlist serves zero channels, so a lost/emptied store surfaces
  in **Admin → Logs**.

**Changed**
- Removed the internal hostname from docs, examples and the admin config placeholder,
  replacing it with the generic `guidearr.example.com` and neutral "docker host" wording
  (`README.md`, `build/README.md`, `health/README.md`, `health/heartbeat.sh`,
  `resources/views/admin/config.blade.php`). The live `docker/nginx.conf` already used
  `server_name _;`, so it needed no change.

**Internal**
- The test suite now isolates its storage to a temp dir (`tests/TestCase::setUp()` →
  `useStoragePath`), so running it can never touch real playlist/provider SQLite stores.

---

## v1.22.6 — Automatic nginx log rotation · 2026-06-07

**Added**
- The bundled web server now keeps its own logs bounded automatically: `docker/nginx-logrotate.sh`
  runs inside the `web` container (via the nginx image's `/docker-entrypoint.d/`) and trims
  `nginx-access.log` / `nginx-error.log` to a recent tail once they pass a size cap — checked
  daily, no host cron or logrotate to set up. It runs as root in nginx's container (the logs are
  root-owned) and relies on nginx's `O_APPEND` writes so the in-place trim stays clean.
  Tunable: `NGINX_LOG_MAX_BYTES` (15 MB), `NGINX_LOG_KEEP_BYTES` (5 MB), `NGINX_LOG_INTERVAL` (daily).
  `docker-compose.yml.example` mounts it on the `web` service.

---

## v1.22.5 — Distribution-ready web server & log tooling · 2026-06-07

**Added**
- The bundled nginx now writes its access/error logs into `storage/logs` as
  `nginx-access.log` / `nginx-error.log` (alongside container stdout/stderr), so they
  appear in the **Admin → Logs** tail viewer and in the downloadable log bundle — but
  only when you're running Guidearr's own web server.
- The admin **log bundle** is trimmed to the **last 5 days** of every log (timestamp-aware
  across Laravel, nginx-access and nginx-error formats); `diagnostics.txt` notes the window.

**Changed**
- `docker/nginx.conf` is now distribution-ready: trusts private-range proxies for
  `real_ip` (X-Forwarded-For) so logs and per-IP logic see the true client behind a
  reverse proxy; sends security headers (X-Frame-Options, X-Content-Type-Options,
  X-XSS-Protection, Referrer-Policy, Cache-Control); adds a query-string WAF;
  sets `server_tokens off`; and enlarges fastcgi buffers (clears the
  "buffered to a temporary file" warnings on large branding images).
- Admin **Clear** is disabled/guarded for `nginx-*` logs — nginx holds them open and
  they're rotated on the host, not truncated from the app.

---

## v1.22.4 — Admin: clear a log file · 2026-06-07

**Added**
- **Clear** button on Admin → Logs truncates the selected `storage/logs/*.log` in place
  (keeps the file so logging continues). Path-traversal-guarded, admin-only.

---

## v1.22.3 — Worker resilience & health monitoring · 2026-06-06

**Added**
- `php artisan health:check` — internal probe for DB connectivity, worker liveness,
  stuck queue jobs and refresh staleness. `--format=human|env|json`; exits 1 on any issue.
- `health/heartbeat.sh` — host cron (every 5 min) that runs the probe, checks container
  status and host CPU/memory/disk, **auto-restarts** wedged services, and **emails the
  admin** — throttled to one alert per issue per 4 h, with a single "recovered" note.
- Worker liveness heartbeat written to `storage/app/health/worker.beat` every poll.
- Config: `FEED_LOW_SPEED_LIMIT`/`FEED_LOW_SPEED_TIME`, `HEALTH_WORKER_STALE`,
  `HEALTH_REFRESH_MAX_AGE_HOURS`.

**Changed / Fixed**
- `feed:work` now wraps its loop in `try/catch` with backoff: a transient DB error is a
  logged retry instead of a crash-loop, so a momentary database outage no longer becomes a
  silent multi-hour stall. *(Root cause of the missed scheduled refreshes.)*
- cURL **stall-abort** on Xtream + M3U downloads: a dead upstream is dropped in ~60 s
  instead of holding a worker for the full 20-minute timeout cap.
- `docker-compose.yml`: `db` healthcheck + `depends_on: { db: { condition: service_healthy } }`
  on app/worker/scheduler so the daemons never start ahead of MySQL.
- README rewritten to document the feed/scheduler/worker subsystem, guide enhancement,
  health monitoring and resilient startup (`health/README.md` covers heartbeat setup).

---

## v1.22.2 — Enhance Guide: read fresh channel names · 2026-06-05

**Fixed**
- The guide enhancer now reads the **live channel-list name** (updated in real time),
  overriding the XMLTV `<display-name>` which lags a day — so current/upcoming events
  (e.g. ESPN+ channels) are picked up instead of stale ones.
- Event titles are cut at the human-date marker and have trailing stream IDs stripped
  (`… Johnson 1395` → `… Johnson`).

---

## v1.22.1 — Enhance Guide: keep filler for ended events · 2026-06-05

**Fixed**
- Channels whose only embedded event has already ended keep their `No EVENT Today`
  filler so they stay visible in the guide; only live/upcoming events replace filler.
  (Fixes the blank-guide regression from v1.22.0.) Default synthetic duration raised to 180 min.

---

## v1.22.0 — Enhance Guide: replace filler with real event · 2026-06-05

**Changed**
- Replaced `No EVENT Today` filler rows with the synthesized event for channels that had
  no real programmes. *(Regression: all-ended event channels went blank — fixed in v1.22.1.)*

---

## v1.21.1 — Enhance Guide: richer logs · 2026-06-05

**Changed**
- Feed import logs now report examined / added / enhanced counts.

---

## v1.21.0 — Enhance Guide · 2026-06-05

**Added**
- Per-provider **Enhance Guide** toggle (on by default). Synthesizes EPG programmes for
  event / PPV channels that encode the event in the channel name (parsed as US Eastern)
  but ship no guide of their own.

---

## v1.20.0 — Reverse-proxy aware · 2026-06-05  *(public release)*

**Added / Changed**
- App is reverse-proxy aware out of the box: nginx serves TLS on `:7979` **and** plain
  HTTP on `:8080`, with `TrustProxies` configured for an upstream proxy/HAProxy.
- Corrected the `docker-compose.yml` / `.env` examples.

**Fixed**
- Playlist editor UX: scroll-jump on reorder and the reindex loader-flash.

---

## v1.19.1 — Reorder migration guard · 2026-06-05

**Fixed**
- Made the flat-ordering migration idempotent (safe to re-run without re-numbering).

---

## v1.19.0 — Flat per-channel ordering · 2026-06-05

**Changed**
- Reworked playlist ordering to a single flat per-channel sequence with **group as an
  attribute** rather than the sort key, plus group-move support. Channels now keep a
  stable position independent of their group.

---

## v1.18.0 — Bulk channel move · 2026-06-05

**Added**
- Multi-row **bulk channel move** with shift-range selection in the playlist editor.
