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
  (no host crontab required when the scheduler runs in-container).

## Requirements
- Docker + Docker Compose
- A reverse proxy / TLS terminator in front (the stack listens on a local HTTPS port)

## Quick start
```bash
git clone https://github.com/mwlistscom/Guidearr.git
cd Guidearr
cp docker-compose.yml.example docker-compose.yml
cp .env.example .env
# Edit .env: set APP_URL, APP_TIMEZONE, and DB_PASSWORD / DB_ROOT_PASSWORD to strong values
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
# create your first admin user:
docker compose exec app php artisan tinker   # then User::create([...]) / or use the admin sync command
```
Then browse to your configured URL and sign in.

## Notes
- Set `APP_TIMEZONE` to your local zone (e.g. `America/Denver`) so each provider's
  refresh hour means your local time, not UTC.
- Run the queue worker and scheduler in-container (compose services running
  `php artisan feed:work` and `php artisan schedule:work`, each `restart: unless-stopped`)
  so refreshes and scheduled jobs run without a host crontab.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
