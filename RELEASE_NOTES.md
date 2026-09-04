# v1.23.13 — A patched proxy, and a vacuum that says what it is doing

A security update for the bundled nginx, and a fix for a maintenance job whose log made a
healthy run look like a hung one.

> **⚠️ The nginx update needs a one-line edit you have to make yourself.** `docker-compose.yml`
> is gitignored — it holds your ports, passwords and bind addresses — so `git pull` **cannot**
> update it. See **Upgrading**; without that edit you stay on the old proxy.

---

## Security

### The bundled proxy moves to nginx 1.30

`docker-compose.yml.example` pinned `nginx:1.27-alpine`, an image **16 months old** on a branch
that stopped receiving fixes. 1.27.5 falls inside the vulnerable range of **18 nginx advisories**;
**1.30.4 clears all of them.**

Most of those need modules this configuration does not use — slice, ssi, dav, mp4, mail, stream,
scgi/uwsgi, HTTP/3 — and the one rated *major* (a `map` + regex overflow) does not apply either,
since `docker/nginx.conf` has no `map`. **Two are reachable from the config Guidearr ships:**

- **CVE-2026-9256** and **CVE-2026-42945**, buffer overflows in `ngx_http_rewrite_module`.
  `docker/nginx.conf` runs nine regex `if ($query_string ~ …)` tests — that is this module —
  against an attacker-controlled query string on **every request**.

The old image also carried 16 months of unpatched Alpine 3.21: openssl 3.3.3, curl 8.12.1,
libxml2, expat, zlib and nghttp2 among them. `1.30-alpine` is Alpine 3.24, with two pending
package upgrades instead of twenty-two.

Stable (1.30.x) rather than mainline (1.31.x), which is the conventional choice for a production
reverse proxy; both clear every current advisory.

---

## Fixed

### `feed:vacuum` now tells you which store it is working on

It logged one line per store *after* finishing it, so while it worked it said nothing. `VACUUM`
on a multi-gigabyte provider store runs for minutes, and a log that only speaks after the fact
reads as a wedged job. From a real run:

```
[10:59:15] [2/36] provider_12.sqlite: 88.0KB -> 76.0KB (reclaimed 12.0KB)
[11:00:04] === BEGIN scheduled — Purge deleted-account stores ===
[11:04:08] [3/36] provider_13.sqlite: 1.5GB -> 828.7MB (reclaimed 740.0MB)
```

Nearly five minutes of silence, with an unrelated hourly task interleaved into the middle of it.
That run was healthy — it finished cleanly and reclaimed 970MB — but nothing in the log said so,
and confirming it meant inspecting the process directly.

Each store now announces itself, with its size, **before** the work starts, and reports how long
it took afterwards:

```
[3/36] provider_13.sqlite: vacuuming 1.5GB…
[3/36] provider_13.sqlite: done 1.5GB -> 828.7MB (reclaimed 740.0MB in 4m53s)
```

Failures say how long they ran before giving up, and the run summary carries a total elapsed time.
Nothing about what the command does to a store has changed — this is log output only.

---

## Changed

### Builds no longer ship the whole install to Docker

There was no `.dockerignore`, so every build sent the entire directory to the Docker daemon —
**2.4GB** on a normal install, of which **2.2GB was the provider and playlist stores** under
`storage/`, plus a `vendor/` the build stopped reading in v1.23.12. Your `.env` and TLS keys went
along with it.

None of that ever reached an image layer — the build copies only six paths — but there was no
reason to hand it over. The context is now a few kilobytes, and the image it produces is
identical. Rebuilds are noticeably quicker, particularly on an install with large feeds.

---

## Upgrading

**1. Update the code:**

```bash
cd Guidearr
git pull
```

**2. Move your proxy to the patched nginx — this is the manual bit.**

`docker-compose.yml` is yours and is not tracked in git, so the pull above cannot change it. Open
it, find the `web` service, and change one line:

```yaml
  web:
    image: nginx:1.27-alpine     # <- change this
    image: nginx:1.30-alpine     # <- to this
```

**3. Rebuild and restart:**

```bash
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

Confirm the proxy actually moved:

```bash
docker compose exec web nginx -v
# nginx version: nginx/1.30.4
```

Recreating `web` interrupts serving for a few seconds — it is the front door, so there is no way
around a brief gap. Everything else is untouched: `docker/nginx.conf` needs no changes, and the
config passes `nginx -t` on 1.30 exactly as it stands.

> `--build` remains non-optional, as of v1.23.12 — it is what installs PHP dependencies and
> compiles the frontend. `git pull` alone leaves both on their previous versions.

**No database migration is required** by this release.
