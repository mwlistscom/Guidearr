# Guidearr health & heartbeat

Two layers:

1. **`php artisan health:check`** — an in-app probe (DB, worker liveness, stuck
   queue jobs, refresh staleness). Exit 0 = healthy, 1 = issue. Output formats:
   `--format=human` (default), `--format=env`, `--format=json`.

2. **`health/heartbeat.sh`** — a **host** cron job (every 5 min) that runs the
   probe, checks container status + host CPU/mem/disk, **auto-restarts** wedged
   services, and **emails the admin** on problems — throttled to one alert per
   issue per `ALERT_COOLDOWN_SEC` (default 4h), plus a single "recovered" note.

The worker (`feed:work`) now writes `storage/app/health/worker.beat` every poll
and survives a transient DB outage (it logs + backs off instead of crash-looping),
so "DB blip" no longer means "silent multi-hour stall."

---

## 1. Deploy the app changes

Standard deploy (code-only — **no migration**):

```bash
# PowerShell (your PC)
scp "$env:USERPROFILE\Downloads\guidearr-v1.22.3-worker-resilience.tar.gz" jules@fidonet.corp.potvin.us:/home/jules/Guidearr/
```
```bash
# fidonet
cd /opt/Guidearr
sudo tar xzf /home/jules/Guidearr/guidearr-v1.22.3-worker-resilience.tar.gz -C /opt/Guidearr
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

## 2. Compose changes (DB healthcheck + ordered startup)

This is what stops the original crash loop at its root: the worker/scheduler will
not start until MySQL reports healthy. Merge into `docker-compose.yml`:

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

  app:
    # ...existing...
    depends_on:
      db:
        condition: service_healthy

  worker:
    # ...existing...
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy

  scheduler:
    # ...existing...
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
```

Apply:
```bash
# fidonet
cd /opt/Guidearr
docker compose up -d        # recreates services with the new depends_on/healthcheck
docker compose ps           # db should show (healthy); app/worker/scheduler running
```

> If `mysqladmin ping` needs auth on your image, use
> `["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -uroot -p\"$$MYSQL_ROOT_PASSWORD\" --silent"]`.

## 3. Install the heartbeat (host cron)

```bash
# fidonet
cd /opt/Guidearr/health
cp heartbeat.env.example heartbeat.env
$EDITOR heartbeat.env            # set SMTP_* and MAIL_TO at minimum
chmod +x heartbeat.sh

# smoke test (runs once; safe)
./heartbeat.sh && tail -n 20 heartbeat.log

# install cron — every 5 minutes
( crontab -l 2>/dev/null; echo '*/5 * * * * /opt/Guidearr/health/heartbeat.sh >/dev/null 2>&1' ) | crontab -
crontab -l | grep heartbeat
```

Keep the log from growing unbounded:
```bash
# fidonet — /etc/logrotate.d/guidearr-heartbeat
/opt/Guidearr/health/heartbeat.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

## Tuning (heartbeat.env)

| var | default | meaning |
|-----|---------|---------|
| `RESTARTABLE` | `worker scheduler app db` | services the heartbeat may auto-restart (drop `db` to page instead) |
| `ALERT_COOLDOWN_SEC` | `14400` | min seconds between repeat alerts for the same issue |
| `LOAD_WARN_PER_CORE` | `2.0` | 1-min loadavg per core before a CPU alert |
| `MEM_WARN_PCT` / `DISK_WARN_PCT` | `90` | host memory / disk thresholds |

App-side thresholds live in `.env` (read by `health:check`):
`HEALTH_WORKER_STALE` (180s), `HEALTH_REFRESH_MAX_AGE_HOURS` (26),
plus the new cURL stall-abort `FEED_LOW_SPEED_LIMIT` (1024 B/s) /
`FEED_LOW_SPEED_TIME` (60s).

## Manual checks

```bash
docker compose exec app php artisan health:check            # human table
docker compose exec -T app php artisan health:check --format=env
```
