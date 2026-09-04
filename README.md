# Guidearr

**Guidearr** is a self‑hosted M3U / IPTV playlist editor — the same engine that runs the playlist tooling on **[RockMyM3U.com](https://rockmym3u.com)**, packaged as a standalone, Dockerized application you can run on your own server.

It gives you a clean web UI for importing, editing, reordering and exporting M3U playlists and EPG/TVG data, wrapped in a hardened Laravel application with a built‑in admin panel for user management, environment configuration and branding.

> Built on Laravel 13 + Livewire + Flux, served over HTTPS by nginx, with MySQL for storage — all orchestrated with Docker Compose.

---

## Table of contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuring `.env` for the first time](#configuring-env-for-the-first-time)
- [Environment variable reference](#environment-variable-reference)
- [TLS certificates](#tls-certificates)
- [The admin panel](#the-admin-panel)
- [Resetting the admin password](#resetting-the-admin-password)
- [Branding & logo sizes](#branding--logo-sizes)
- [Cloudflare Turnstile (CAPTCHA)](#cloudflare-turnstile-captcha)
- [Background feeds, scheduling & health](#background-feeds-scheduling--health)
- [Applying configuration changes](#applying-configuration-changes)
- [Common maintenance commands](#common-maintenance-commands)
- [Updating](#updating)
- [Versioning](#versioning)
- [Tests & CI](#tests--ci)
- [Troubleshooting](#troubleshooting)
- [Project layout](#project-layout)
- [License](#license)

---

## Features

**Playlist editing**
- Web-based M3U playlist editor with channel/group management.
- EPG / TVG data handling.
- Export playlists for use in your IPTV clients.

**Authentication & accounts**
- Email + password login backed by [Laravel Fortify](https://laravel.com/docs/fortify).
- Two‑factor authentication (TOTP authenticator apps) and **passkeys**.
- Code‑based email verification (a 6‑digit code, valid 15 minutes — no link to click).
- Optional "registration requires admin approval" mode.
- Optional [Cloudflare Turnstile](#cloudflare-turnstile-captcha) CAPTCHA on registration, admin login and email verification.

**Admin panel** (at a configurable, hard‑to‑guess URL)
- **Status** page with user/pending/banned counts and a one‑click **Reload services** action.
- **Users** — list, search/filter, edit (name, email, role, status, verification, password), enable/ban, mark verified/unverified, and delete, with guards that stop you banning/deleting yourself or the last admin.
- **Environment** — edit `.env` safely from the browser: secrets are masked, `APP_KEY` is locked, every save is backed up and written atomically, and caches are cleared automatically.
- **Branding** — upload a separate **app icon** and **logo**, and set the footer copyright.

**Operations & security**
- HTTPS‑only, served by nginx with HTTP/2.
- Configurable admin path to reduce automated `/admin` probing.
- First admin login forces a password change.
- Bootstrap/recovery admin account driven from `.env`.

**Providers, EPG & background refresh**
- Add **M3U**, **Xtream Codes**, **XMLTV** or **manual** providers; channels and guide data import in the background.
- Per‑provider **daily auto‑refresh** at a configurable hour, plus on‑demand refresh from the UI.
- A long‑running **worker** processes a refresh queue; a **scheduler** enqueues providers when their daily time arrives.
- **Guide enhancement** synthesizes EPG programmes for event / PPV channels that carry their event in the channel name but ship no guide of their own.
- Resilient downloads: hard size cap, overall timeout, and a **stall‑abort** that drops a dead upstream in seconds instead of holding a worker for the full timeout.

**Health monitoring**
- `php artisan health:check` probes DB connectivity, worker liveness, stuck queue jobs and refresh staleness (exit 0 = healthy, 1 = issue).
- An optional **host heartbeat** (`health/heartbeat.sh`, cron every 5 min) checks the stack + host CPU/memory/disk, **auto‑restarts** wedged services and **emails the admin** on problems — throttled so it never spams (one alert per issue per 4 h, plus a single recovery note).
- Ordered startup: the worker/scheduler wait for MySQL to report **healthy** before starting, so a database restart can't crash‑loop them.

---

## Tech stack

| Layer        | Technology                                            |
|--------------|-------------------------------------------------------|
| Framework    | Laravel 13, Livewire, Flux UI                         |
| Auth         | Laravel Fortify (2FA, passkeys)                       |
| Runtime      | PHP 8.3+ (FPM)                                         |
| Web server   | nginx (TLS, HTTP/2)                                   |
| Database     | MySQL 8                                               |
| Background   | `feed:supervise` worker pool + Laravel `scheduler`    |
| Mail         | Your SMTP relay (no mail server bundled)              |
| Orchestration| Docker Compose                                        |

---

## Requirements

- **Docker** and the **Docker Compose** plugin (assumed already installed).
- A TLS certificate + key for the hostname you'll serve from (a self‑signed pair is fine for testing — see [TLS certificates](#tls-certificates)).
- Outbound internet from the browser if you enable Turnstile (the widget loads from `challenges.cloudflare.com`).

No PHP, Composer or Node is required on the host — everything runs in containers.

---

## Quick start

```bash
# 1. Clone
git clone git@github.com:mwlistscom/Guidearr.git
cd Guidearr

# 2. Generate your environment file (interactive — writes fresh secrets)
chmod +x setup.sh
./setup.sh
#    prompts for hostname, admin email/password and admin path, then writes .env

# 3. Provide TLS certs in ./certs (see "TLS certificates")
mkdir -p certs
#    drop fullchain.pem + privkey.pem into ./certs, or generate a self-signed pair

# 4. Point nginx at your hostname
#    edit docker/nginx.conf -> server_name <your-host>;   (e.g. guidearr.example.com)

# 5. Build and start the stack
docker compose up -d --build

# 6. Initialise the application (setup.sh already generated APP_KEY)
docker compose exec app php artisan migrate --force
docker compose exec app php artisan admin:password   # create the admin (prompts for email + password)
```

Then browse to `https://<your-host>:7979` and log in with the admin email and password you entered when running `admin:password`.

> **Note on the default port:** the stack publishes the app on **`7979`** (TLS). `setup.sh` asks for this and writes it to `.env` as `TLS_PORT`, which `docker-compose.yml` reads — so changing it later means editing `.env`, not the compose file, and keeping `APP_URL` in sync. The `listen`/redirect lines in `docker/nginx.conf` are the *container* side and stay at 7979 unless you change them there too.

> **Where to make local changes.** `docker-compose.yml` **is tracked in git**, which is what lets an upgrade deliver changes to it — a proxy security bump, a new service, a corrected default. Everything install-specific comes from `.env` (`TLS_PORT`, `HTTP_BIND`, `HTTP_PORT`, `DB_*`). For anything else, create a **`docker-compose.override.yml`**: Compose merges it on top automatically, and it is never committed. Editing the tracked file directly works, but the next `git pull` will conflict with you.

PHP dependencies and front‑end assets are both installed **during the image build**, so nothing needs PHP, composer or Node on the host — `--build` in step 5 is what produces them. If you want them on the host as well (to run the test suite, say), see [Building dependencies with Docker](#building-dependencies-with-docker) in Troubleshooting.

---

## Configuring `.env` for the first time

Guidearr ships a generator (`setup.sh`) instead of a committed example file, so every install gets its own secrets. Run it once after cloning:

```bash
./setup.sh            # interactive
./setup.sh --force    # overwrite an existing .env (keeps a timestamped backup)
```

It prompts for the hostname, HTTPS port, admin email, admin password (blank → generates a strong one) and admin URL path, then writes a complete `.env` — including a freshly generated `APP_KEY`, database credentials that match the `db` service, and your outgoing mail settings. It asks for an SMTP relay host: leave it blank and mail is written to the Laravel log instead of being delivered, which you can change later. If it generates an admin password it prints it once, so note it down.

From then on you can edit most values from the browser — see [The admin panel → Environment](#the-admin-panel) — instead of touching the file by hand.

> ⚠️ **Database credentials live in `.env` only.** `docker-compose.yml` reads `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DB_ROOT_PASSWORD` from there, so there is only one place to change them — they used to have to be kept in step in two files. `setup.sh` generates both passwords.
>
> **A database volume is initialised once**, with whatever it saw the first time. Changing `DB_PASSWORD` afterwards does not change the volume, and the app will simply stop being able to log in — so change it *before* first start, or reset the volume. `DB_ROOT_PASSWORD` is never used by the app; it exists because the MySQL image refuses to initialise without one. If either is missing, Compose stops with a message naming it rather than starting a database with a blank password.

---

## Environment variable reference

These are the variables Guidearr cares about most. (Standard Laravel variables — logging, session, cache, queue, Redis, AWS — behave as usual.)

| Variable | What it does |
|---|---|
| `APP_NAME` | Display name shown in titles, emails and the brand. |
| `APP_ENV` | `local`, `staging` or `production`; affects error verbosity and caching. |
| `APP_DEBUG` | Detailed error pages. Keep `false` in production. |
| `APP_URL` | Canonical base URL; used to build absolute links and redirects. Must include the scheme and port, e.g. `https://host:7979`. |
| `APP_KEY` | Encryption key for sessions/cookies. Generated with `key:generate`; **never change on a live app**. |
| `DB_*` | Database connection (driver/host/port/name/user/password). `DB_HOST=db` points at the compose service. |
| `ADMIN_PATH` | URL segment for the admin panel (`admin` → `/admin`). Use a hard‑to‑guess value to reduce probing. |
| `REGISTRATION_REQUIRES_APPROVAL` | When `true`, new sign‑ups are held `pending` until an admin enables them. |
| `MAIL_*` | Outgoing mail (SMTP) settings. `MAIL_SCHEME=smtps` for port 465, `tls`/null for 587. |
| `AUTH_LIMIT_*` | Rate limits on the public auth endpoints (sign-in, sign-up, password reset). Sensible defaults ship built in — raise them only if many genuine users share one public IP, e.g. behind a corporate NAT. See `config/guidearr.php` for the full list. |
| `TURNSTILE_SITE_KEY` | Public Cloudflare Turnstile key for the widget. Blank = CAPTCHA off. |
| `TURNSTILE_SECRET_KEY` | Private Turnstile key for server‑side verification. Blank = CAPTCHA off. |
| `FEED_CONNECT_TIMEOUT` / `FEED_TIMEOUT` | cURL connect timeout (30s) and overall cap for a *progressing* download (1200s). |
| `FEED_LOW_SPEED_LIMIT` / `FEED_LOW_SPEED_TIME` | Stall‑abort: if throughput stays below the limit (1024 B/s) for this long (60s), the transfer is aborted — stops a hung upstream from holding a worker for the full timeout. |
| `FEED_MAX_BYTES` | Hard size cap per download (~1.2 GB). |
| `OUTBOUND_ALLOW_HOSTS` | Comma‑separated hosts a provider may point at even though they resolve into private space — e.g. `nas.lan,10.0.0.5`. Provider URLs are fetched **by the server**, so by default anything resolving to loopback, link‑local, private or reserved space is refused; this is the narrow way to allow a genuine LAN provider back. |
| `OUTBOUND_ALLOW_PRIVATE` | `true` drops that range check entirely (default `false`). Only sensible where every account is trusted. |
| `OUTBOUND_MAX_REDIRECTS` | Redirect hops followed while fetching a provider URL (5). Each hop is re‑checked against the rules above. |
| `FEED_MAX_ERRORS` | Errors before a job is dropped and its provider disabled (4). |
| `FEED_ORPHAN_MINUTES` | A job `running` longer than this is treated as orphaned and requeued (60). |
| `HEALTH_WORKER_STALE` | Seconds before the worker heartbeat is considered stale / wedged (180). |
| `HEALTH_REFRESH_MAX_AGE_HOURS` | An enabled provider not refreshed within this many hours is flagged (26; 0 disables). |

---

## TLS certificates

nginx serves HTTPS on port `7979` and reads its certificate from a bind‑mounted `./certs` directory (mapped into the container). It expects two files:

```
certs/fullchain.pem
certs/privkey.pem
```

**Self‑signed (testing).** Run this from the project root (uses a throwaway container, so you don't need openssl on the host):

```bash
mkdir -p certs
docker run --rm -v "$PWD/certs":/certs alpine/openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /certs/privkey.pem -out /certs/fullchain.pem -days 365 \
  -subj "/CN=your-host"
```

Browsers will warn about the self‑signed cert; that's expected for testing.

**Production.** Drop in a real certificate/key pair for your hostname (e.g. issued via Let's Encrypt or your CA) as `certs/fullchain.pem` and `certs/privkey.pem`, then reload nginx:

```bash
docker compose exec web nginx -s reload
```

The `server_name` in `docker/nginx.conf` should match your hostname.

---

## The admin panel

Visit `https://<your-host>:7979/<ADMIN_PATH>` (default `/admin`) and sign in with the admin account. The panel has four areas:

- **Status** — at‑a‑glance counts (users / pending / banned) and a **Reload services** button (see [Applying configuration changes](#applying-configuration-changes)).
- **Users** — search and filter the user list; per‑row actions to **edit**, **mark verified/unverified**, **ban/unban** and **delete**. The edit screen covers name, email, role (user/admin), status (enabled/banned), email‑verification state and an optional password reset. You can't ban or delete yourself, or remove/ban the last admin.
- **Environment** — a safe `.env` editor. Secrets (anything with `PASSWORD`, `SECRET`, `TOKEN`, `KEY`, …) are shown masked with a reveal toggle, `APP_KEY` is locked, each save writes a timestamped backup to `storage/app/env-backups/` and clears the config/route cache. You can even change `ADMIN_PATH` here (it redirects you to the new URL).
- **Branding** — upload the [app icon and logo](#branding--logo-sizes) and edit the footer copyright text.

The admin account is created **email‑verified and active**, so it never hits the verification screen.

### Threat feed (blocking scanners at your firewall)

Any Guidearr on the public internet gets probed constantly for `/.env`, `/wp-login.php`,
`/.ssh/id_rsa` and similar. Guidearr refuses all of it, but the noise is real — on one install
those probes were **74% of all requests**.

**Admin → Config → Threat feed** publishes the offending addresses as a plain‑text list, one per
line, for **pfBlockerNG** (or any tool that polls a URL) to block at the edge. Tick *Serve the
feed*, copy the URL shown, and add it as a **custom IPv4 source**. Set *List after N attacks* to
control how aggressive it is (default `20`).

There is **nothing to run** after an install or upgrade: the secret URL is generated for you, the
list builds itself on the first fetch and refreshes hourly. The URL segment is yours to change; a
wrong one returns `404`, so the endpoint can't be discovered by guessing.

Two things are **never** listed, so the feed can't take down your own service:

- any host that has successfully pulled a playlist (a customer's player may share an address with
  a scanner), and
- private/reserved addresses, where your reverse proxy and health checks live.

> **Worth knowing:** scanners rent cloud hosts and rotate them within a day or two, so a list of
> past offenders blocks tomorrow's traffic only sometimes. Its dependable benefit is cutting log
> noise and bandwidth. Treat it as a complement to a curated blocklist, not a replacement.

---

## Resetting or creating the admin account

Recover a locked‑out or deleted admin (break‑glass) with the interactive command. It prompts for
the email and a new password — the password is entered hidden and **nothing is stored in `.env`**:

```bash
docker compose exec app php artisan admin:password
```

It resets the password of the matching account, or creates a new active, verified admin if none
exists (e.g. the admin user was deleted). Re‑enables and email‑verifies the account as a side effect.

---

## Branding & logo sizes

Guidearr uses two separate brand images, both managed under **Admin → Branding** (uploaded files override the bundled defaults):

| Asset | Where it appears | Recommended | Notes |
|---|---|---|---|
| **App icon** | Sidebar, header, admin panel, browser tab | **512 × 512** PNG, transparent background | Square. It's displayed small (~32 px), so use a simple mark, not a wordmark. |
| **Logo** | Landing‑page hero | **~1024 × 280** PNG (roughly 3.5:1), transparent background | A wide wordmark reads best here. |

- Accepted upload formats: **PNG, JPG, WEBP, GIF**. SVG is intentionally not accepted for uploads (it's served publicly).
- Maximum upload size: **10 MB**.
- The browser **favicon** is served from static files in `public/` (`favicon.ico` at 16/32/48 px, `favicon.svg`, and `apple-touch-icon.png` at 180 × 180). Replace those files to change the favicon, and bump the `?v=` query in `resources/views/partials/head.blade.php` to defeat browser caching.

---

## Cloudflare Turnstile (CAPTCHA)

Turnstile is **off** unless both `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are set (and it's automatically bypassed in the test suite). To enable it:

1. In the [Cloudflare dashboard](https://dash.cloudflare.com) open **Turnstile → Add widget**.
2. Name it, add your **hostname** (e.g. `your-host`), choose **Managed** mode, and **Create**.
3. Copy the **sitekey** and **secret key**.
4. Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` (via **Admin → Environment** or `.env`), then **Reload services**.

Using Cloudflare's demo keys shows a "For testing only" banner on the widget — real keys remove it. If verification fails, the usual cause is the page hostname not being listed on the widget.

---

## Background feeds, scheduling & health

Guidearr imports and refreshes playlist/EPG data in the background using two
long‑running containers alongside `app`, `web` and `db`:

| Service | Command | Role |
|---|---|---|
| `worker` | `php artisan feed:supervise` | Supervises the queue: keeps up to the **Worker Limit** of `feed:work` children busy, downloading/parsing each provider's source into its store, and owns the liveness heartbeat. |
| `scheduler` | Laravel scheduler (`feed:due` every minute) | Enqueues each enabled provider when its **daily refresh hour** arrives; also runs `feed:trim` (weekly) and `feed:purge` (hourly). |

**Provider refresh model.** Each provider has a `refresh_hour` (and minute). Every
minute the scheduler runs `feed:due`, which enqueues a provider once per day after
its scheduled time, provided no job is already in flight for it. The workers drain
the queue; a job that runs longer than `FEED_ORPHAN_MINUTES` is reclaimed and
retried, and after `FEED_MAX_ERRORS` failures the provider is disabled.

**Parallel workers (Worker Limit).** Set **Admin → Config → Worker limit** to run
more than one provider refresh at a time. The `feed:supervise` process starts extra
`feed:work` children only while providers are queued and scales the pool back to
zero as the backlog drains, never exceeding the limit (default **1** = a single
worker, the classic behavior). Claims use `SELECT … FOR UPDATE SKIP LOCKED`, so
workers never collide, and each provider writes only its own store. This
parallelises *many* providers refreshing at once (e.g. the daily hour) — a single
large feed is still one job on one worker. Size the limit to the box's spare CPU and
RAM: each worker downloads and parses a feed independently. The setting is live —
no restart needed.

> **Refresh hours are interpreted in `config('app.timezone')`.** If that's `UTC`,
> a `refresh_hour` of `5` fires at 05:00 **UTC**. Set `APP_TIMEZONE` (e.g.
> `America/Denver`) if you want the hours to mean local time — the served EPG uses
> absolute UTC timestamps regardless, so this only affects scheduling and displayed times.

**Guide enhancement.** For event/PPV channels whose name encodes the event (e.g.
`… ESPN FC Jun 05 5:00PM ET (2026-06-05 17:00:00)`) but which ship only placeholder
guide rows, the importer synthesizes a real programme from the **live channel name**.
Toggle it per provider with the **Enhance Guide** checkbox (on by default).

**After changing the worker, scheduler or any importer/service code**, restart the
daemons so they pick up the new classes (they hold the old ones in memory):

```bash
docker compose restart worker scheduler
```

### Resilient startup (DB healthcheck)

So a database restart can't crash‑loop the worker, give `db` a healthcheck and make
the other services wait for it. In `docker-compose.yml`:

```yaml
services:
  db:
    # ...existing mysql:8.4 config...
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "--silent"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 40s

  app:        { depends_on: { db: { condition: service_healthy } } }
  worker:     { restart: unless-stopped, depends_on: { db: { condition: service_healthy } } }
  scheduler:  { restart: unless-stopped, depends_on: { db: { condition: service_healthy } } }
```

Apply with `docker compose up -d` (recreates the services); `docker compose ps`
should then show `db` as `(healthy)`. The worker additionally tolerates a transient
DB outage at runtime — it logs and backs off rather than exiting — and writes a
liveness heartbeat the health probe reads.

### Health probe

```bash
docker compose exec app php artisan health:check                 # human table
docker compose exec -T app php artisan health:check --format=env # machine-readable (or --format=json)
```

It reports `db`, `worker` (via the heartbeat at `storage/app/health/worker.beat`),
`queue` (stuck jobs) and `refresh` (oldest enabled provider), and exits non‑zero if
any check fails.

### Host heartbeat (auto‑restart + email)

A host cron job that runs the probe, checks container status and host CPU/memory/disk,
**auto‑restarts** wedged services and **emails the admin** on issues — throttled to
one alert per issue per 4 hours, with a single recovery note when it clears.

```bash
cd /opt/Guidearr/health
cp heartbeat.env.example heartbeat.env     # set SMTP_* and MAIL_TO at minimum
chmod +x heartbeat.sh
./heartbeat.sh && tail -n 20 heartbeat.log # one-shot smoke test

# every 5 minutes
( crontab -l 2>/dev/null; echo '*/5 * * * * /opt/Guidearr/health/heartbeat.sh >/dev/null 2>&1' ) | crontab -
```

See [`health/README.md`](health/README.md) for the full configuration reference
(thresholds, which services may be auto‑restarted, SMTP shapes) and a `logrotate`
snippet.

---

## Applying configuration changes

Changes to `.env` (database, mail, Turnstile, app settings) need the app to re‑read its configuration. The simplest way:

- **Admin → Status → Reload services.** This clears all caches and gracefully reloads the PHP‑FPM workers inside the app container, so changes take effect immediately. (It reloads the app only — it does not restart the database, web or mail containers.)

Equivalent from the CLI:

```bash
docker compose exec app php artisan optimize:clear
```

A full stack restart (e.g. after changing the compose file itself) is a host command:

```bash
docker compose restart
```

---

## Common maintenance commands

```bash
# Clear all caches (config, routes, views, compiled, events)
docker compose exec app php artisan optimize:clear

# Run database migrations
docker compose exec app php artisan migrate --force

# Create or recover the admin account (interactive: prompts for email + password)
docker compose exec app php artisan admin:password

# Health probe (DB / worker / queue / refresh)
docker compose exec app php artisan health:check

# Feed/scheduler control
docker compose exec app php artisan feed:due --dry-run  # list providers currently due
docker compose restart worker scheduler                 # reload after importer/worker code changes

# Tail application logs
docker compose exec app tail -f storage/logs/laravel.log

# Open a shell in the app container
docker compose exec app sh
```

---

## Updating

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler   # reload importer/worker code held in memory
```

> **Upgrading from before v1.23.14?** `docker-compose.yml` used to be gitignored, and **`git pull`
> overwrites an ignored file without warning** — git protects untracked files, not ignored ones. So
> copy yours aside *first*, then move its values into `.env`:
>
> ```bash
> cp docker-compose.yml docker-compose.yml.backup
> git pull
> ./docker/migrate-compose.sh docker-compose.yml.backup
> docker compose config          # check before starting anything
> ```
>
> Already pulled without a copy? Nothing is lost while the containers are still up — they carry the
> values they were created with: `./docker/migrate-compose.sh --from-running`.

> `--build` is not optional. **PHP dependencies and frontend assets are both produced inside
> the image** and copied into place when a container starts, so skipping the rebuild leaves you
> on the previous packages *and* the previous stylesheet — a release that patches a dependency
> would not reach you at all, and any new styling would silently have no effect. There is no
> separate `composer install` or `npm run build` step; neither PHP nor Node is needed on the host.
>
> The dependency install is keyed on `composer.lock`, so it only runs when the packages have
> actually changed. To confirm it happened:
>
> ```bash
> docker compose logs app | grep guidearr:
> # guidearr: installed PHP dependencies into vendor/
> ```
>
> If you pull without rebuilding, the app container logs `composer.lock does not match this
> image` and keeps the packages it has rather than pinning the old versions silently.

---

## Versioning

The current version is shown on the admin **Status** page and is read from the `VERSION` file at the project root. Bump `VERSION` on every change — especially before pushing to GitHub — so the running build is always identifiable.

## Tests & CI

The bundled PHPUnit suite (auth, settings) runs in GitHub Actions on push/PR across PHP 8.3 / 8.4 / 8.5. Turnstile is disabled in the `testing` environment so the suite runs without CAPTCHA tokens.

Run the tests locally in a container:

```bash
docker compose exec app ./vendor/bin/phpunit
```

---

## Troubleshooting

**The browser tab shows the old favicon.** Favicons are cached aggressively. The `?v=` query in the head partial handles most cases; otherwise close and reopen the tab, or visit `/favicon.ico?v=2` directly once.

**"The logo failed to upload."** The uploaded file is larger than PHP's `upload_max_filesize`. Guidearr ships a `public/.user.ini` that raises this to 16 MB; if you changed it, make sure `upload_max_filesize` / `post_max_size` and nginx's `client_max_body_size` (20 MB) all allow your file, then `docker compose restart app`.

**A site works in an incognito window but hangs in your normal browser profile.** Usually stale per‑host network state (HSTS + cached redirects). In Chrome: `chrome://net-internals/#hsts` → delete the host, `#dns` → clear host cache, then fully restart the browser.

**Class not found after pulling new files.** If your install uses an authoritative classmap, run `docker run --rm -v "$PWD":/app -w /app composer:2 dump-autoload`.

<a name="building-dependencies-with-docker"></a>
**Building dependencies with Docker (host has no PHP/Node).** The image installs both at build time, so this is not needed to run Guidearr. It is here for working on the source on a host with no PHP or Node — to run the test suite, which needs the dev dependencies the image deliberately omits:

```bash
# PHP dependencies -> ./vendor
docker run --rm -v "$PWD":/app -w /app composer:2 install \
  --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# Front-end assets -> public/build
docker run --rm -v "$PWD":/app -w /app node:22-alpine sh -c "npm ci && npm run build"
```

---

## Project layout

```
app/
  Console/Commands/AdminPassword.php    # admin:password command (interactive admin recovery)
  Console/Commands/FeedDue.php          # scheduler: enqueue providers due for refresh
  Console/Commands/FeedWork.php         # worker: drain the refresh queue
  Console/Commands/HealthCheck.php      # health:check probe
  Http/Controllers/Admin/              # Status, Users, Environment, Branding controllers
  Services/                            # M3uDownloader, XtreamImporter, M3uGuideImporter, ProviderStore, …
  Support/Turnstile.php                # CAPTCHA enable/disable gate
config/guidearr.php                    # admin path/email + feed limits + health thresholds
docker/nginx.conf                      # TLS vhost on :7979
health/                                # heartbeat.sh, heartbeat.env.example, README.md
public/.user.ini                       # PHP upload limits
public/branding/                       # default icon + logo
resources/views/admin/                 # admin panel views
routes/admin.php                       # admin routes (prefixed by ADMIN_PATH)
routes/console.php                     # scheduled tasks (feed:due / feed:trim / feed:purge)
routes/web.php                         # public + app routes
setup.sh                               # interactive .env generator
VERSION                                # app version (bump on every change)
```

---

## License

© Jules Potvin. Built on the Laravel framework and the Livewire starter kit (MIT).
