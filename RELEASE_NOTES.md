# Guidearr v1.22.6 — Web-server hardening & log tooling

Makes the bundled nginx production-ready for public / reverse-proxied deployments and turns
the web-server logs into a first-class, self-managing part of the admin panel. Rolls up
everything since v1.22.3 (v1.22.4–v1.22.6).

## Highlights

### Production-ready `docker/nginx.conf`
Out of the box, the bundled web server now:
- promotes the **real client IP** from `X-Forwarded-For` for private-range reverse proxies
  (HAProxy / Traefik / Caddy / nginx), so logs and per-IP logic see the true visitor;
- sends **security headers** (X-Frame-Options, X-Content-Type-Options, X-XSS-Protection,
  Referrer-Policy, Cache-Control);
- blocks common **query-string injection / exploit** patterns (a lightweight WAF);
- sets `server_tokens off` and enlarges fastcgi buffers (clears the
  "upstream response is buffered to a temporary file" warnings on large branding images).

Behind a proxy on a **public** IP, add one line — `set_real_ip_from <proxy-ip>;` (the spot
is commented inline).

### nginx logs in the admin panel
The web server writes `nginx-access.log` and `nginx-error.log` into `storage/logs` (as well
as container stdout/stderr), so they appear in **Admin → Logs** with the same tail/filter
viewer and in the downloadable log bundle — only when you run Guidearr's own nginx.

### Logs that manage themselves
- **Auto-rotation, zero setup.** `docker/nginx-logrotate.sh` runs inside the `web` container
  (via the nginx image's `/docker-entrypoint.d/`), trimming the nginx logs to a recent tail
  once they pass a size cap — daily, no host cron or logrotate. Tunable:
  `NGINX_LOG_MAX_BYTES` (15 MB), `NGINX_LOG_KEEP_BYTES` (5 MB), `NGINX_LOG_INTERVAL` (daily).
- **Bundle trimmed to the last 5 days** of every log (timestamp-aware across Laravel and
  nginx formats), so support bundles stay small and relevant.
- **Clear a log file** from the admin Logs page (truncates in place, keeps logging).
  Disabled for `nginx-*` logs, which the web server owns and rotates itself.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear
docker compose up -d --force-recreate web   # new nginx.conf, file logging, and the rotator
```
No migration, no worker/scheduler restart. After the recreate, load a page and the nginx
logs appear under **Admin → Logs**.

## Notes
- Public-IP proxy? Add it to `docker/nginx.conf`: `set_real_ip_from <your-proxy-ip>;`
- Prefer host-level rotation? Run your own `logrotate` for `storage/logs/nginx-*.log`
  instead — both are fine; the in-container rotator is just the no-setup default.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
