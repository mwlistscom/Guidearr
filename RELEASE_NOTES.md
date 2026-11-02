# v1.23.30 — PHP no longer prints warnings into responses or announces its version

> **⚠️ This release needs a rebuild AND a restart of the `web` container.** `git pull` alone
> changes nothing that is running. See **Upgrading** below; it's three commands.
>
> Every install is affected. No configuration change is needed and nothing is removed.

---

## Fixed

### PHP was running on its development defaults

The official `php:*-fpm` image ships no `php.ini`, and Guidearr's own `docker/php.ini` only set
the upload and memory limits. Everything else ran on PHP's compiled-in defaults, which are meant
for development: `display_errors=On`, `display_startup_errors=On`, `log_errors=Off` and
`expose_php=On`. Two visible effects:

- **PHP warnings were sent to the client.** A warning raised by PHP itself before Guidearr ran,
  for example a `multipart/form-data` POST with no boundary, or a request body larger than
  `post_max_size`, was printed into the response body. Because output had already started, the
  proper `405` or `413` could no longer be sent, and the client received a **`200`** containing
  the warning text. Scanners send exactly these requests.
- **Every response carried `X-Powered-By: PHP/8.4.x`**, telling anyone who asked which PHP
  version answered.

Nothing was exploitable through this, and the warnings seen in the wild contained no file paths,
but neither belongs on a public site.

`docker/php.ini` now sets `display_errors = Off`, `display_startup_errors = Off`,
`log_errors = On` and `expose_php = Off`. PHP's warnings go to the container's log instead
(`docker compose logs app`). nginx also strips `X-Powered-By` from PHP responses, so the header
is gone even on an install that has pulled this change but not yet rebuilt.

The same malformed requests now get the answers they should: `405` for a POST to a page that
takes none, `413` for a body nginx refuses.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose up -d --force-recreate --no-deps web
```

**Both steps are required:**

- **`--build`** installs the new `php.ini`. It lives in the image, so without it PHP keeps
  printing warnings into responses.
- **Recreating `web`** loads the nginx line that strips `X-Powered-By`. The nginx config is
  mounted into the container as a file, and after `git pull` the running container keeps
  reading the version it started with. Recreating `web` after `app` also makes nginx pick up
  the rebuilt app container's new address, which avoids a burst of `502 Bad Gateway` errors.

No migration and no `.env` change is needed. To check it took:
`docker compose exec app php-fpm -i | grep -E '^(display_errors|expose_php)'` should show `Off`.
