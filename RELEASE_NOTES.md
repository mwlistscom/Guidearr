# Guidearr v1.22.12 — Worker activity in the admin Logs page

See what the background feed worker is doing from the admin panel, without needing shell access.

## Highlights

### worker.log on the Logs page
The feed worker is a separate daemon, so its output used to reach only the container's stdout
(`docker logs`). It now writes to a dedicated **`worker.log`**, which appears automatically on
**Admin → Logs** alongside `laravel.log` and the nginx logs. It captures lifecycle and one-line
activity:

- **Supervisor** — start/stop, and each spawn/scale decision (`Backlog 5 queued, 0 running
  (limit 3) — started 3 worker(s).`).
- **Workers** — per-job **claim**, a **`done in Ns`** summary, and **failures** (including the
  disable-after-N-errors event).

The full per-provider detail still lives in **Admin → Feeds** (the `feed_logs` table), and worker
exceptions still land in `laravel.log`. `worker.log` is clearable from the UI and is included in the
downloadable log bundle.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose restart worker          # supervisor reloads and starts writing its lifecycle lines
docker compose exec app php artisan optimize:clear
```
No migration. Per-job lines from the worker children appear immediately; the supervisor's own
start/spawn lines begin after the restart.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
