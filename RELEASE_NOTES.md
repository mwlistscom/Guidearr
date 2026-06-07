# Guidearr v1.22.5 — Distribution-ready web server & log tooling

Hardens the bundled nginx for public / reverse-proxied deployments and makes the
web-server logs first-class in the admin panel.

## Highlights
- **Production-ready `docker/nginx.conf`.** Out of the box it now:
  - promotes the real client IP from `X-Forwarded-For` for private-range reverse proxies
    (HAProxy / Traefik / Caddy / nginx) — logs and per-IP logic see the true visitor;
  - sends security headers (X-Frame-Options, X-Content-Type-Options, X-XSS-Protection,
    Referrer-Policy, Cache-Control);
  - blocks common query-string injection / exploit patterns (a lightweight WAF);
  - sets `server_tokens off` and enlarges fastcgi buffers (clears the
    "upstream response is buffered to a temporary file" warnings on large branding images).

  Behind a proxy on a **public** IP, add one line: `set_real_ip_from <proxy-ip>;`
  (the spot is commented inline).
- **nginx logs in the admin panel.** The bundled web server writes `nginx-access.log` and
  `nginx-error.log` into `storage/logs` (as well as container stdout/stderr), so they show
  up in **Admin → Logs** with the same tail / filter viewer and in the downloadable log
  bundle. They appear only when you're running Guidearr's own nginx.
- **Log bundle trimmed to the last 5 days.** The diagnostics bundle now includes only the
  most recent 5 days of each log (timestamp-aware across Laravel and nginx formats),
  keeping support bundles small and relevant.
- **Safer Clear.** Clearing is disabled for `nginx-*` logs (nginx holds them open; rotate
  on the host instead). Also folds in the v1.22.4 admin "Clear log file" action.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear
docker compose up -d --force-recreate web   # picks up the new nginx.conf and starts the file logs
```
No migration, no worker/scheduler restart. After the recreate, load a page and the nginx
logs appear under **Admin → Logs**.

## Notes
- The `nginx-*.log` files grow unbounded; for long-running installs add a host `logrotate`
  rule with `copytruncate` on `storage/logs/nginx-*.log`.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
