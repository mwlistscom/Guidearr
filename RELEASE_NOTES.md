# v1.23.22 — PHP 8.4.27: the php2shell fix

> 🛑 **Security release. Upgrade today, and you must rebuild the image — `git pull` alone does
> nothing for you here.** The fix is in PHP itself, which lives in the Docker image, not in the
> code a pull brings down. See **Upgrading** below.

---

## Security

### PHP is updated to 8.4.27 (CVE-2026-109618 / CVE-2026-109619)

On 21 October 2026 PHP released fixes for two vulnerabilities, rated **CVSS v4 9.2** and widely
referred to as *php2shell*. Together they allow **unauthenticated remote code execution**: anyone
who can reach your Guidearr can send it a specially crafted upload request and run code on the
server.

What makes these unusual is *where* they are. PHP reads an upload request (`multipart/form-data`)
**before any application code runs** — before Guidearr checks whether you are logged in, before
it even decides which page you asked for. So it does not matter that Guidearr's own code is
sound, or that the page in question requires a login. If the PHP underneath is vulnerable, the
install is vulnerable. Every version before 8.4.27 is affected, and exploitation was reported in
the wild before the fix was even published.

Guidearr's image now builds on **PHP 8.4.27**.

### The image now pins its PHP version, so a rebuild cannot quietly keep the old one

Until now the image was built on `php:8.4-fpm-alpine` — a *floating* tag that always means
"the newest 8.4". The catch is that Docker only re-downloads a floating tag when explicitly told
to. If your machine already had a copy, `docker compose up -d --build` rebuilt on **that** copy
and finished without complaint, leaving you on the old PHP while everything looked fine.

The image is now built on `php:8.4.27-fpm-alpine` exactly. Your machine will not have that one
cached, so the rebuild fetches it. From now on a PHP security fix arrives the same way any other
change does — with the pull and the rebuild.

### The upload-progress handler is switched off

One of the two exploit paths runs through PHP's *session upload progress* feature. Guidearr has
never used it, so it is now disabled in the image's PHP configuration
(`session.upload_progress.enabled = 0`) as permanent defence in depth.

---

## Changed

### The playlist list refreshes in your other tabs, too

v1.23.21 made the Playlists page refresh itself after deleting a provider took a playlist with
it — but only in the tab you deleted from. A Playlists page left open in a second tab went on
showing the deleted playlist until you reloaded it by hand. Every open tab now updates.

### Sign-up sends its form a different way

The registration form now submits as an ordinary form post instead of as an upload. Nothing about
signing up looks or behaves differently; it just no longer depends on the kind of request the
vulnerability above lives in.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

**`--build` is not optional this time — it *is* the fix.** Without it your containers keep
running the PHP they already had, and you remain exposed.

Then confirm PHP actually moved:

```bash
docker compose exec app php -v
# PHP 8.4.27 (cli) …
```

If that still says anything older, the rebuild did not happen: run `docker compose build --pull`
and `docker compose up -d` again.

No migration and no configuration change.

### If you cannot rebuild straight away

As a stopgap until you can, add this line to `docker/nginx.conf`, just after
`server_tokens off;`, and reload the proxy:

```nginx
    if ($http_content_type ~* "multipart") { return 415; }
```

```bash
docker compose exec web nginx -t && docker compose exec web nginx -s reload
```

It refuses every upload request before PHP sees it. The cost is that the admin **branding upload**
stops working while it is in place — nothing else in Guidearr uploads files. Take it back out
once `php -v` reports 8.4.27. Edit the file in place with a normal editor save; tools that replace
the file (`mv`, some `sed -i` variants) leave the running proxy reading the old copy.
