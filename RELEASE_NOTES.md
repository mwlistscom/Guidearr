# v1.23.15 — Provider URLs can no longer point inwards

A security fix. Guidearr fetches the URL you give a provider, and until now nothing checked
where that URL pointed — only that it began with `http`. This closes that.

> **Nothing to run.** Pull, rebuild, done. The one case that needs attention is an install
> whose provider genuinely lives on the local network — see **Upgrading**.

---

## Security

### Provider URLs could reach the internal network (SSRF)

Adding or editing a provider makes **the server** fetch the URL, from inside your network.
Nothing constrained the destination, so any account could point a provider at:

- `http://127.0.0.1:9000/` — services on the container itself
- `http://db:3306/` — the database container
- `http://169.254.169.254/latest/meta-data/` — cloud instance metadata
- `http://192.168.1.1/` — anything on your LAN

and have Guidearr reach it on their behalf. `REGISTRATION_REQUIRES_APPROVAL` defaults to
`false`, so holding an account is not much of a barrier.

**It was not blind, either.** The validator reported back enough to make it useful:

| What happened | What the user was told |
| --- | --- |
| Nothing listening on that port | *"Could not fetch the URL (timeout, DNS, or connection refused)"* |
| Something listening | *"The URL did not look like a M3U…"* — **plus the size of the response** |
| The response began `#EXTM3U` or `<?xml` | Accepted, imported, and shown back as channels |

Those three outcomes are a working internal port scanner, with content disclosure whenever a
target happened to serve something playlist-shaped.

### What changed

Every outbound fetch — the M3U/XMLTV signature check, the Xtream login, the playlist download
and the guide download — now goes through one guard, and it does two things:

**It resolves the host and refuses private or reserved space**, checking *every* address the
name resolves to. A name answering with one public and one loopback record would otherwise
walk straight through.

**It re-checks every redirect hop.** This is the part that is easy to leave out, and the reason
a check on the typed-in URL alone proves nothing: an attacker runs the first server, so it can
simply answer `302 http://169.254.169.254/`. curl is no longer allowed to follow redirects by
itself, and is pinned to HTTP and HTTPS rather than relying on library defaults.

Guidearr also blocks three ranges that PHP's own private/reserved check misses — carrier-grade
NAT (`100.64.0.0/10`, which several VPN and container networks sit inside), `192.0.0.0/24` and
the benchmarking block.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

No migration, no configuration change, and nothing to undo.

### If one of your providers is on your own network

Some people legitimately run an M3U or XMLTV source on a NAS or another box at home. Those
providers will now fail with:

> Refusing to fetch a private or reserved address (nas.lan resolves to 192.168.1.20).

Allow that host back in `.env` — the narrow option first:

```ini
# Just these hosts, still blocking everything else internal
OUTBOUND_ALLOW_HOSTS=nas.lan,192.168.1.20
```

```ini
# Or drop the check entirely. Only sensible where every account is trusted.
OUTBOUND_ALLOW_PRIVATE=true
```

`OUTBOUND_MAX_REDIRECTS` (default 5) caps how many hops are followed; each one is checked
against the same rules.

Restart the app after editing `.env` so the new settings are read:

```bash
docker compose restart app worker scheduler
```

---

## A note on who this affects

If your Guidearr sits on a private network with only accounts you trust, this was never
especially exploitable. If registration is open — the default — or the instance is reachable
from the internet, it was: anyone who could sign up could use your server to look at things
inside your network that they could not reach themselves. Worth taking promptly in that case.
