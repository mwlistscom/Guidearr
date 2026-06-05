# Guidearr v1.16.0

Guidearr is a self-hosted IPTV playlist and EPG manager. It ingests channel sources
(Xtream, M3U, and XMLTV providers), lets you build and organize playlists with a
drag-and-drop grid, and serves them back out as M3U, XMLTV (EPG), and STRM endpoints
for your player of choice. It runs as a small Docker stack (nginx + PHP-FPM + MySQL),
with an admin panel, two-factor auth, and per-playlist rate limiting.

This is the first public release.

## Features
- **Providers** — Xtream, M3U, and XMLTV sources with per-provider daily auto-refresh.
- **Playlists** — merge channels from multiple providers, reorder channels and groups,
  override names/logos/groups, and assign an EPG guide source.
- **Serving endpoints** — `/m3u`, `/epg`, and `/strm`, each keyed by a per-playlist cipher,
  with IP locking and a rolling unique-IP rate limit.
- **Admin panel** — user management (manual creation, authorize/ban), feed browser,
  config (serving links + rate limits), `.env` editor, branding, logs viewer with a
  downloadable diagnostics bundle, and a maintenance pane to prune unused playlists.
- **Status dashboard** — disk, memory, CPU load, and data-store size for capacity monitoring.
- **Scheduling** — provider refreshes fire automatically at each provider's chosen time
  via an in-container scheduler (no host crontab required).

## Requirements
- Docker + Docker Compose
- A reverse proxy / TLS terminator in front (the stack listens on a local HTTPS port)

## Quick start
```bash
git clone https://github.com/mwlistscom/Guidearr.git
cd Guidearr

# 1. Configuration
cp docker-compose.yml.example docker-compose.yml
cp .env.example .env
#    Edit .env and set at minimum:
#      APP_URL            your public URL
#      APP_TIMEZONE       e.g. America/Denver (so provider refresh hours mean local time)
#      DB_PASSWORD        a strong value
#      DB_ROOT_PASSWORD   a strong value
#      ADMIN_EMAIL        your first admin login
#      ADMIN_PASSWORD     a strong value (you'll be forced to change it on first login)

# 2. Bring up the stack
docker compose up -d --build

# 3. Initialise the app
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force

# 4. Create the bootstrap admin from ADMIN_EMAIL / ADMIN_PASSWORD
docker compose exec app php artisan admin:sync
```

Then browse to your `APP_URL` and sign in. (Lost the admin password later?
`docker compose exec app php artisan admin:sync --reset` re-applies it from `.env`.)

## Background processing
Two long-running processes keep things current:
- the **queue worker** (`php artisan feed:work`) — performs provider refreshes;
- the **scheduler** (`php artisan schedule:work`) — enqueues each provider when its
  daily refresh time arrives, and runs housekeeping.

Run them as Compose services with `restart: unless-stopped` so they survive reboots
(see `docker-compose.yml.example`). No host crontab is needed.

## Notes
- Set `APP_TIMEZONE` to your local zone, or provider refresh hours are interpreted as UTC.
- The optional Cloudflare **Turnstile** captcha activates when `TURNSTILE_SITE_KEY` /
  `TURNSTILE_SECRET_KEY` are set; leave them blank to disable.
- To point the app at an **external** MySQL/MariaDB, set `DB_HOST` (use
  `host.docker.internal` for a DB on the Docker host) and `DB_CONNECTION=mariadb`
  for MariaDB. Channel/guide data stays in local SQLite under `storage/app`.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
