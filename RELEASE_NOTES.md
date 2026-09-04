# v1.23.16 — A Secure session cookie, and what trusting a proxy costs

Two loose ends from the same security review that produced v1.23.15. One is a real fix that
needs nothing from you; the other is guidance, because the right answer depends on how your
install is wired and no default can know that.

> **Nothing to configure.** Pull, rebuild, and log in once to confirm — see **Upgrading**.

---

## Security

### The session cookie is now marked `Secure` on an HTTPS install

Laravel leaves this off unless `SESSION_SECURE_COOKIE` is set by hand, and almost every
Guidearr install terminates TLS at a proxy in front — HAProxy, Caddy, nginx, a tunnel. The app
itself therefore only ever sees plain HTTP arriving over the internal network, and has no way
to work out that the browser at the other end is on HTTPS.

The result: unless you had set that variable yourself, your session cookie went out **without
a `Secure` flag**, and a single stray `http://` request would put it on the wire in clear text.

It is derived from `APP_URL` now. If yours starts with `https://` — which it does if `setup.sh`
wrote it — the flag is set, with nothing for you to change.

**An install genuinely served over plain `http://` keeps working.** Forcing the flag there
would stop anyone logging in at all, which is a worse failure than the one being fixed.
`SESSION_SECURE_COOKIE` still overrides the default in either direction if you want to be
explicit.

---

## Changed

### `docker/nginx.conf` now says what trusting a proxy range actually costs

The proxy trust list covers the private ranges, and that is what makes the real client IP work
when Guidearr sits behind something. The part worth knowing: **anything that can open a
connection from inside a trusted range can send its own `X-Forwarded-For` and be believed.**
That decides what the playlist IP-lock sees, and which addresses the threat feed treats as a
customer rather than a scanner.

The right value is specific to your network, so this release does not change the default —
narrowing it centrally would break `real_ip` for anyone whose proxy sits on a range that got
removed. Instead the config now states the trade-off and gives you the two levers:

1. **Keep `HTTP_BIND` no wider than your proxy can reach.** The default, `127.0.0.1`, means
   nothing else can connect at all and the ranges cost you nothing. Widening it to a LAN
   address is what puts every other machine on that network inside the trusted set.
2. **If you have widened it, narrow the trust to the proxy itself** — `set_real_ip_from
   192.168.1.2;` — and drop the ranges you do not use.

`docker/nginx.conf` is tracked in git, so carry that change in a `docker-compose.override.yml`
that mounts your own copy over `/etc/nginx/conf.d/default.conf`, rather than editing the
tracked file and conflicting on the next `git pull`.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

No migration and no configuration change.

**Log in once afterwards.** The cookie change is the kind that is obvious in hindsight if it
goes wrong: if your `APP_URL` says `https://` but you actually reach Guidearr over plain
`http://`, the browser will now refuse to send the session cookie back and you will not be able
to log in. The fix in that case is to correct `APP_URL` to match how you really browse to it —
or set `SESSION_SECURE_COOKIE=false` in `.env` if you mean to stay on http — then
`docker compose restart app`.
