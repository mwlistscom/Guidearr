# v1.23.29 — Path-traversal probes count toward the threat feed

> **Only does anything if you use the threat feed AND add one rule to your reverse proxy.**
> Without that rule, nothing changes. Upgrading is `git pull` plus a restart of the `web`
> container; no rebuild.

---

## Fixed

### Path-traversal probes were charged to your proxy instead of the scanner

Scanners send paths such as `/x/../../.env`, `/..%2F..%2F.env` or `/.%2e/.%2e/proc/self/environ`.
nginx refuses these with `400 Bad Request` while it is still reading the first line of the
request. That is before it reads `X-Forwarded-For`, so behind a reverse proxy the access log
records **the proxy** as the client, with no user agent. The threat feed never lists a private
address, so these probes were never charged to anyone. On the install where this was found that
was about 3,300 probes per log.

Guidearr now recognises a marker path, `/__traversal__`:

- nginx answers it directly with a `404`, so no Laravel page is rendered for each probe;
- the threat feed counts it as an attack, like `/wp-login.php`.

**Your proxy has to send traversal requests to that path.** nginx then sees a normal request and
logs the real client. For HAProxy (pfSense or standalone), add to the frontend:

```
acl traversal path -m reg -i (^|/|%2f)(\.|%2e){2}(/|%2f|\z)
http-request set-header X-Original-URI %[url] if traversal
http-request set-path /__traversal__ if traversal
```

The original path is kept in `X-Original-URI`. A path like `/static../.env` is not a traversal
and is left alone.

If your proxy can't do this, or you expose nginx directly, nothing changes. With nginx exposed
directly, the real client is already logged on the `400` and those probes already count.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --force-recreate --no-deps web
```

**Recreating `web` is what loads the new nginx rule.** The nginx config is mounted into the
container as a file, and after `git pull` the running container keeps reading the version it
started with. If you skip it, the marker still counts toward the feed, but each probe is
answered by Laravel instead of nginx.

No rebuild, migration or `.env` change is needed.
