# Guidearr

**Guidearr** is a self‑hosted M3U / IPTV playlist editor — the same engine that runs the playlist tooling on **[mwlists.com](https://mwlists.com)**, packaged as a standalone, Dockerized application you can run on your own server.

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
- [Applying configuration changes](#applying-configuration-changes)
- [Common maintenance commands](#common-maintenance-commands)
- [Updating](#updating)
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

---

## Tech stack

| Layer        | Technology                                            |
|--------------|-------------------------------------------------------|
| Framework    | Laravel 13, Livewire, Flux UI                         |
| Auth         | Laravel Fortify (2FA, passkeys)                       |
| Runtime      | PHP 8.3+ (FPM)                                         |
| Web server   | nginx (TLS, HTTP/2)                                   |
| Database     | MySQL 8                                               |
| Mail (dev)   | Mailpit                                               |
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

# 2. Create your environment file
cp .env.example .env
#    -> then edit .env (see "Configuring .env for the first time" below)

# 3. Provide TLS certs in ./certs (see "TLS certificates")
mkdir -p certs
#    drop fullchain.pem + privkey.pem into ./certs, or generate a self-signed pair

# 4. Point nginx at your hostname
#    edit docker/nginx.conf -> server_name <your-host>;   (default is fidonet.corp.potvin.us)

# 5. Build and start the stack
docker compose up -d --build

# 6. Initialise the application
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan admin:sync   # creates the admin from ADMIN_EMAIL/ADMIN_PASSWORD
```

Then browse to `https://<your-host>:7979` and log in with the admin credentials you set in `.env`. You'll be prompted to change the admin password on first login.

> **Note on the default port:** the stack publishes the app on **`7979`** (TLS). Adjust the published port in `docker-compose.yml` and the `listen`/redirect lines in `docker/nginx.conf` if you want a different one, and keep `APP_URL` in sync.

If your image doesn't install PHP dependencies or build front‑end assets at build time, see [Building dependencies with Docker](#building-dependencies-with-docker) in Troubleshooting.

---

## Configuring `.env` for the first time

`cp .env.example .env` gives you Laravel's defaults. Before the first boot, set the keys below so the app can reach the database, send mail, and create your admin account. (The values shown are examples — `db`, `mailpit` etc. are the Docker **service names** the app talks to over the internal network.)

```dotenv
APP_NAME=Guidearr
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-host:7979

# --- Database (matches the db service in docker-compose.yml) ---
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=guidearr
DB_USERNAME=guidearr
DB_PASSWORD=change-this-strong-password

# --- Bootstrap admin (used by `php artisan admin:sync`) ---
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=change-me-on-first-login
# Hard-to-guess admin URL segment -> /<this>. Defaults to "admin".
ADMIN_PATH=admin
# Hold new sign-ups until an admin approves them
REGISTRATION_REQUIRES_APPROVAL=false

# --- Mail (example: an external SMTP relay) ---
MAIL_MAILER=smtp
MAIL_HOST=mail.example.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=admin@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=admin@example.com
MAIL_FROM_NAME=Guidearr

# --- Cloudflare Turnstile (leave blank to disable the CAPTCHA) ---
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

After editing, generate the app key and run the initial setup (steps 5–6 of the Quick start). From then on you can edit most values from the browser — see [The admin panel → Environment](#the-admin-panel) — instead of touching the file by hand.

> ⚠️ **Match the database credentials to your compose file.** `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` must match whatever the `db` service is initialised with in `docker-compose.yml`. If you change them after the volume already exists, recreate the DB volume or update the MySQL user — the app won't connect otherwise.

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
| `ADMIN_EMAIL` | Email of the bootstrap admin created by `admin:sync`. |
| `ADMIN_PASSWORD` | Bootstrap/recovery admin password; first login forces a change. |
| `ADMIN_PATH` | URL segment for the admin panel (`admin` → `/admin`). Use a hard‑to‑guess value to reduce probing. |
| `REGISTRATION_REQUIRES_APPROVAL` | When `true`, new sign‑ups are held `pending` until an admin enables them. |
| `MAIL_*` | Outgoing mail (SMTP) settings. `MAIL_SCHEME=smtps` for port 465, `tls`/null for 587. |
| `TURNSTILE_SITE_KEY` | Public Cloudflare Turnstile key for the widget. Blank = CAPTCHA off. |
| `TURNSTILE_SECRET_KEY` | Private Turnstile key for server‑side verification. Blank = CAPTCHA off. |

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

---

## Resetting the admin password

The admin account is bootstrapped from `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `.env`. To recover or reset it (break‑glass):

```bash
# 1. set/confirm ADMIN_PASSWORD in .env, then:
docker compose exec app php artisan admin:sync --reset
```

`--reset` re‑applies the `.env` password to the existing admin, re‑enables the account, marks it verified, and forces a password change on the next login. Running `admin:sync` **without** `--reset` is idempotent — it ensures the admin exists and is verified/active but leaves the password alone.

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

# Ensure / recover the admin account
docker compose exec app php artisan admin:sync          # idempotent
docker compose exec app php artisan admin:sync --reset  # re-apply .env password

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
```

---

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
**Building dependencies with Docker (host has no PHP/Node).** If your image doesn't install dependencies at build time:

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
  Console/Commands/AdminSync.php        # admin:sync command
  Http/Controllers/Admin/              # Status, Users, Environment, Branding controllers
  Support/Turnstile.php                # CAPTCHA enable/disable gate
config/guidearr.php                    # admin path/email/password + registration approval
docker/nginx.conf                      # TLS vhost on :7979
public/.user.ini                       # PHP upload limits
public/branding/                       # default icon + logo
resources/views/admin/                 # admin panel views
routes/admin.php                       # admin routes (prefixed by ADMIN_PATH)
routes/web.php                         # public + app routes
```

---

## License

© Jules Potvin. Built on the Laravel framework and the Livewire starter kit (MIT).
