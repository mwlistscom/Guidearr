# v1.23.32 — nginx 1.30.7 (NGINX2Root), PHP 8.4.28 and dependency security updates

> 🛑 **Security release. Upgrade promptly, and this one needs `--build`.** `git pull` alone
> changes nothing that is running. See **Upgrading** below; it's two commands.
>
> Every install is affected. nginx 1.30.6 and older carry CVE-2026-123007 ("NGINX2Root"), an
> HTTP/2 bug that allows unauthenticated remote code execution, with a public proof of concept.
> No configuration change is needed and nothing is removed.

---

## Fixed

### nginx 1.30.7, and the proxy image is now pinned

The `web` container moves to **nginx 1.30.7**, the stable release of 18 November 2026. It fixes:

- **CVE-2026-123007** ("NGINX2Root"): HTTP/2 flow control, unauthenticated remote code execution.
  A public proof of concept exists.
- **CVE-2026-123633**: HTTP/2 use-after-free.
- The resolver, HTTP/3 and TLS 1.3 fixes from the same release.

**Guidearr is exposed to the HTTP/2 bugs on both ports.** `http2 on` is set for the whole server
in `docker/nginx.conf`, so nginx accepts HTTP/2 on the TLS port (7979) **and** on the plain-HTTP
port meant for your reverse proxy (cleartext HTTP/2 with prior knowledge). Whether the internet
can send HTTP/2 straight through depends on your proxy, but anything that can reach either port
directly can.

The compose file also used the floating tag `nginx:1.30-alpine`. Docker never re-pulls a tag it
already has, and `--build` only rebuilds the services Guidearr builds itself, so an install kept
whichever 1.30 patch it happened to download first. The tag is now **`nginx:1.30.7-alpine`**.
Docker pulls it because it is new, and the changed image recreates `web`. That makes the nginx
version part of what an upgrade delivers, the same way the PHP image has been pinned since
v1.23.22.

### PHP 8.4.28

The app image moves from PHP 8.4.27 to **8.4.28**, the security release of 19 November 2026,
which includes:

- **CVE-2026-129289**: PHP-FPM request smuggling. This one is **not reachable** with Guidearr's
  nginx config, which does not keep FastCGI connections open between requests.
- **CVE-2026-115560**: session serializer.
- **CVE-2026-129496**: timezone cache use-after-free.

### Dependency security updates

`composer audit` reported 13 advisories. It reports none now. The updated packages:

- **Symfony** 8.1.x patches for `http-foundation`, `http-kernel`, `routing`, `mime`,
  `serializer` and `yaml` (11 advisories).
- **`laravel/framework`** v13.39.0 → v13.43.0 (CVE-2026-128083, low).
- **`phpseclib/phpseclib`** 3.0.57 → 3.0.59 (a denial of service through PBKDF iteration counts).

Their direct dependencies were updated too: patch and minor releases of guzzle, commonmark,
monolog and others. None of the advisories was found reachable in Guidearr: the serializer is
unused, trusted proxies only supply the scheme, and links are built from `APP_URL`. They are
updated anyway.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
```

That one `up` command does all of it. It rebuilds the app image on PHP 8.4.28 with the updated
packages, pulls `nginx:1.30.7-alpine`, and recreates every container whose image changed. Expect
a few seconds of downtime while the containers swap.

**Without `--build`** you keep the old PHP and the old packages. The app container logs a warning
that its installed `composer.lock` does not match the tree. nginx is still upgraded, because
the new tag is pulled either way.

No migration and no `.env` change is needed. To check it took:

```bash
docker compose exec web nginx -v     # nginx version: nginx/1.30.7
docker compose exec app php -v       # PHP 8.4.28 (cli) ...
```

If you cannot upgrade right away, removing the `http2 on;` line from `docker/nginx.conf` and
recreating `web` closes the two HTTP/2 bugs, but not the other nginx fixes. Upgrading is the real fix.
