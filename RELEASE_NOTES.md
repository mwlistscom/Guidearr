# Guidearr v1.22.11 — Parallel feed workers (Worker Limit)

Refresh many providers at once instead of one at a time, with a simple limit you control from the
admin panel.

## Highlights

### Worker Limit — parallel provider refreshes
Set **Admin → Config → Worker limit** to run more than one provider refresh concurrently. A new
`feed:supervise` process keeps up to *N* `feed:work` children busy while providers are queued and
scales the pool back to zero when the backlog drains. The default of **1** is exactly today's
single-worker behavior; raise it to speed up a batch of providers all refreshing at once (e.g. the
daily refresh hour, or a bulk re-queue).

- **Live** — stored in the settings store, so a change takes effect within a few seconds with **no
  restart**.
- **Safe** — queue claims use `SELECT … FOR UPDATE SKIP LOCKED`, so workers never claim the same job,
  and each provider writes only its own store. On shutdown the supervisor drains gracefully; a child
  cut off mid-job is requeued and retried, so no work is lost.
- **Size it to your box** — each worker downloads and parses a feed independently, so pick a limit
  your spare CPU and memory can handle. Note this parallelises **many** providers at once — a single
  large feed is still one job on one worker.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose up -d worker            # picks up the new feed:supervise command
docker compose exec app php artisan optimize:clear
```
No migration. The Worker Limit defaults to 1, so the upgrade changes nothing until you raise it in
Admin → Config.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
