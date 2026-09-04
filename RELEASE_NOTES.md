# v1.23.12 — Security updates, and the fix that makes them arrive

This release patches four dependencies with published advisories — and fixes the reason a
dependency patch has never actually reached an install before now.

> **This one needs `--build`.** `git pull` alone installs nothing. See **Upgrading**.

---

## Security

### Four dependencies updated

`composer audit` flagged **22 advisories** across four packages, all of them installed:

| Package | Was | Now | Why it matters |
| --- | --- | --- | --- |
| `guzzlehttp/guzzle` | 7.11.0 | 7.15.5 | 9 advisories, up to high — includes a noncanonical-host check bypass |
| `guzzlehttp/psr7` | 2.11.0 | 2.13.1 | CRLF injection when serializing an HTTP start line |
| `league/commonmark` | 2.8.2 | 2.10.0 | 10 advisories — several high-severity denial of service via crafted Markdown, plus a link-filter bypass |
| `livewire/livewire` | v4.3.1 | v4.4.3 | DOM-based XSS in client-side state handling |

Guzzle is what fetches every provider playlist and guide, so it handles remote input on every
refresh. `laravel/framework` itself was not flagged, and is deliberately left where it was — this
is a narrow security update, not a framework bump.

---

## Fixed

### A dependency update now actually reaches your install

This is the important part of the release, and it is why the section above is worth anything.

`vendor/` is not in git, and the image never ran `composer` at all — it only copied the composer
*binary* in. The documented upgrade is:

```bash
git pull
docker compose up -d --build
```

That pulled a **new `composer.lock`** and left the **old packages** sitting on disk, because
nothing ever installed them. Every security fix in every dependency, in every release so far,
shipped to GitHub and changed nothing on any running install. The four updates above would have
done exactly the same.

The image now installs the dependencies during the build. They cannot simply be left at
`vendor/` inside the image — compose bind-mounts `./` over `/var/www/html`, which hides
anything the image put there, the same trap the frontend assets hit in v1.23.9 — so they are
staged outside that path and copied into place when the container starts.

Two details worth knowing:

- **The install is keyed on `composer.lock`.** It runs when the lock file on disk differs from
  the one already installed, and does nothing when they match — so it costs nothing on a normal
  restart, and it leaves a local development install (dev dependencies and all) alone.
- **A `git pull` without `--build` now says so** instead of failing silently. If the code on
  disk asks for packages the image was not built with, the container logs
  `composer.lock does not match this image — run 'docker compose up -d --build'` and keeps the
  packages it has, rather than quietly pinning the old versions.

### A fresh clone can be built again

Quick start said to clone and run `docker compose up -d --build`. That could not work: the build
copied `vendor/livewire/flux/dist/flux.css` out of the build context, and `vendor/` is gitignored,
so a clean checkout failed on that line before it reached anything else — with a `not found` error
that pointed at a file the user had no obvious way to produce. The build takes its own copy now,
so a clone builds with no manual `composer install` first.

### Duplicate `Cache-Control` header on dynamic responses

A blanket `add_header Cache-Control "no-transform" always;` sat at server level in
`docker/nginx.conf`, and `add_header` can only ever *append* — it cannot replace a header the
application already set. Every session-authenticated response therefore carried `Cache-Control`
twice. Harmless to browsers, but it trips intrusion-detection heuristics for repeated response
headers on essentially every request. It has moved into `location /`, where it still covers static
assets — which set no `Cache-Control` of their own — without touching PHP responses.

### Legacy `/m3u/m3u.php` flood dropped at the proxy

An endpoint that has not existed for several major versions still attracts continuous automated
requests. nginx now closes those connections without a response instead of passing each one to PHP.

---

## Upgrading

> **`--build` is not optional in this release.** It is what installs the patched dependencies.
> `git pull` on its own leaves you on the vulnerable versions — that is the bug being fixed here,
> and it applies to the upgrade *into* this release as much as to the ones after it.

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

Confirm the dependencies actually landed — the app container logs a line when it installs them:

```bash
docker compose logs app | grep guidearr:
# guidearr: installed PHP dependencies into vendor/
```

If you have edited `docker/nginx.conf` (Quick start step 4 tells you to, for `server_name`),
`git pull` may report a conflict on it. Keep your `server_name` line and take the incoming
`Cache-Control` and `/m3u/m3u.php` changes.

**No database migration is strictly required** by this release, but the `migrate` above is
harmless and keeps an install that skipped v1.23.11 correct.
