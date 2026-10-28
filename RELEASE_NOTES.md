# v1.23.27 — One scanner can no longer hold every PHP worker

> **⚠️ This release needs a rebuild AND a restart of the `web` container.** `git pull` alone
> changes nothing that is running. See **Upgrading** below; it's three commands.
>
> It stops a scanner hammering made-up URLs from slowing the site for everyone else. No
> configuration change is needed and nothing is removed.

---

## Fixed

### A scanner could keep every PHP worker busy

Automated scanners request hundreds of URLs that don't exist (`/sitemap.xml`, `/team`,
`/api-docs`, …), often well over 100 a second. nginx already refuses the obviously hostile ones
(`/.env`, path traversal) itself, but the rest reached Laravel for a "not found" page. That
takes about 45 ms each, and the PHP pool had only 5 workers. A few scanners at once kept all
five busy, and real visitors' pages and playlist fetches queued behind them. On the install
this was found on, PHP-FPM reported running out of workers about twenty times a day.

Two changes:

- **nginx now limits PHP requests per client address**: 5 per second with a burst of 30.
  Anything over the limit is answered `429 Too Many Requests` by nginx, which costs PHP
  nothing. Static files (stylesheets, scripts, images) are never counted. Real use stays far
  below the limit; the busiest real session seen peaked at 6 requests in one second.
- **The PHP-FPM pool grows from 5 workers to 10.** That's about 400 MB at the usual ~40 MB
  per worker.

**Private and loopback addresses are never limited.** That covers health checks and your
reverse proxy. It also matters if your proxy isn't listed in `docker/real-ip.conf`: then every
visitor appears as the proxy's private address, and limiting that one address would throttle
the whole site as if it were one visitor.

If you use the threat feed, nothing changes there. The hostile probes it counts are refused
before the limit applies, and a rate-limited scanner still produces plenty of entries to be
listed.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose up -d --force-recreate --no-deps web
```

**Both steps are required, and each one delivers a different half:**

- **`--build`** installs the larger PHP pool. It lives in the image, so without it PHP stays
  at 5 workers.
- **Recreating `web`** applies the rate limit. The nginx config is mounted into the container
  as a file, and after `git pull` the running container keeps reading the version it started
  with, so without the recreate there is no limit at all. Recreating `web` after `app` also
  makes nginx pick up the rebuilt app container's new address, which avoids a burst of
  `502 Bad Gateway` errors.

No migration and no `.env` change is needed.
