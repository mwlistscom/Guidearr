# Guidearr v1.22.6 — Automatic nginx log rotation

Builds on v1.22.5 (which surfaced the bundled web server's logs in Admin → Logs): those
nginx logs now rotate themselves, so storage can't fill on a long-running install — with
zero setup.

## What's new
- A small rotator (`docker/nginx-logrotate.sh`) ships in the stack and runs inside the
  `web` container via the nginx image's `/docker-entrypoint.d/`. It trims
  `nginx-access.log` / `nginx-error.log` to a recent tail once they grow past a size cap,
  checked daily. Because it runs as root in nginx's own container (the nginx logs are
  root-owned) and nginx writes with `O_APPEND`, the trim is clean — no host cron, no
  logrotate, nothing to configure.
- Tunables on the `web` service:
  - `NGINX_LOG_MAX_BYTES` — trim once a file passes this size (default 15 MB)
  - `NGINX_LOG_KEEP_BYTES` — how much tail to keep (default 5 MB)
  - `NGINX_LOG_INTERVAL` — seconds between checks (default 86400 = daily)
- `docker-compose.yml.example` mounts the rotator on the `web` service, so fresh installs
  get it automatically.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose up -d --force-recreate web   # picks up the rotator
```
No migration. If you already run a host-level logrotate for these files, you can keep it
instead — the in-container rotator is simply the no-setup default.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
