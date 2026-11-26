# v1.23.36 — Legacy Joomla replays now count against the per-IP rate limit

> **nginx config only, follow-up to v1.23.35.** No image rebuild and no database change. The
> proxy config is bind-mounted, so after `git pull` the `web` container must be recreated for
> the change to take effect. See **Upgrading** below.

---

## Fixed

### Joomla replays were never throttled

Since v1.23.31 nginx answers the old Joomla site's URLs with 410 Gone before PHP is involved.
That answer was a `return` inside a server-level `if`, and nginx runs those in its rewrite
phase, before the per-IP rate limit (`limit_req`) ever sees the request. So one address could
replay those URLs without limit. On the reference install a single scanner sent 2,392 of them
in two minutes and received no 429 at all. Cheap for nginx, but the budget that applies to
every other probe did not apply to these.

The `if` now rewrites such requests into an internal location that applies the same per-IP
limit as PHP requests (5 r/s, burst 30, shared bucket) and answers 410 through `try_files`,
which runs after the limit check. Over the budget they get 429 like any other probe. The
access log still records the original URL and status, so the threat feed's match is unchanged.

Verified in a throwaway nginx: 60 rapid probes from one public address produced 32 × 410 and
then 28 × 429; private and loopback clients remain exempt, as they are for PHP.
`LegacyJoomlaUrlsTest` pins the shape, including that no `return 410` remains anywhere in the
config.

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --force-recreate --no-deps web
```

`--no-deps` matters: without it compose also recreates the database container. If you are
coming from v1.23.33 or earlier, use `docker compose up -d --build` instead and expect the
database restart described in v1.23.34.

To check it took:

```bash
docker compose exec web grep -c '__joomla__' /etc/nginx/conf.d/default.conf   # 3
```
