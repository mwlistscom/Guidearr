# Changelog

All notable changes to **Guidearr** since v1.18. Newest first.

> **Tagged public releases:** v1.20.0, v1.22.3 and v1.22.5. Intermediate entries
> (1.21.0–1.22.2, 1.22.4) were development iterations rolled into the next tagged release.

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
