# v1.23.18 — Trust only the proxy in front of you

A small release, and a hardening step you have to opt into rather than one that arrives on its
own. Nothing changes on upgrade unless you choose to change it.

---

## Security

### The X-Forwarded-For trust list can now be narrowed without forking the config

When Guidearr runs behind a reverse proxy — the usual arrangement — nginx only sees the proxy's
address, so it takes the visitor's real IP from the `X-Forwarded-For` header the proxy sets. That
promoted address is what the playlist **IP-lock** matches on, and what the **threat feed** uses to
tell a customer apart from a scanner.

The catch is that **trusting a range means trusting everything inside it**. The shipped list covers
`10/8`, `172.16/12` and `192.168/16`, so any machine that can reach Guidearr from one of those
networks can send its own `X-Forwarded-For` and be believed. If your `HTTP_BIND` is anything wider
than `127.0.0.1`, that is your whole LAN.

**The defaults have not changed, and that is deliberate.** A default that is too narrow silently
breaks the real client IP for anyone whose proxy sits somewhere it did not guess — and that failure
is much harder to notice than an over-broad trust, because everything keeps working while every
visitor quietly appears to be the proxy.

What has changed is that narrowing it is now practical. The list lives in its own file,
`docker/real-ip.conf`, mounted separately — so you can replace **just that file** rather than
copying the whole of `docker/nginx.conf` and carrying a local change that conflicts on every
upgrade.

---

## Narrowing it, if you want to

Most installs do not need to. If `HTTP_BIND` is `127.0.0.1`, nothing but this host can connect and
the broad list costs you nothing. It is worth doing if you have widened that bind so a proxy on
another machine can reach Guidearr.

**1. Find the one address that actually needs trusting.** Do this from inside the container while
real traffic is arriving — the answer is not what you see from the host, which shows its own NAT
instead:

```bash
docker compose exec web sh -c 'netstat -tn | grep :8080'
```

```
172.18.0.6:8080     192.168.3.1:16061     TIME_WAIT
                    ^^^^^^^^^^^ your proxy
```

**2. Write that one address into a local file** — `docker/*.local.conf` is gitignored:

```bash
echo 'set_real_ip_from 192.168.3.1;' > docker/real-ip.local.conf
```

**3. Mount it** from `docker-compose.override.yml`, which Compose merges automatically and which is
never committed:

```yaml
services:
  web:
    volumes:
      - ./docker/real-ip.local.conf:/etc/nginx/real-ip.conf:ro
```

**4. Apply it.** A new mount needs the container recreated, not just reloaded — a few seconds:

```bash
docker compose up -d web
docker compose exec web cat /etc/nginx/real-ip.conf
```

### Check both directions afterwards

They fail in opposite ways, and only checking one will mislead you.

**A forged header should now be ignored.** From a machine that is *not* your proxy:

```bash
curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.99' http://<your-host>:<port>/login
docker compose logs web | tail -1
```

It should log that machine's own address, not `203.0.113.99`.

**Real visitors should still show their own IP.** Watch the log for ordinary traffic:

```bash
docker compose logs web --tail 20
```

If every request suddenly logs as your *proxy's* address, you have narrowed to the wrong one —
promotion has stopped, and the IP-lock and threat feed will both be working from a single address.
Widen it back and re-check step 1.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

No migration, and no behaviour change unless you follow the section above. `docker/real-ip.conf`
ships with exactly the same three ranges the config used before, so an install that does nothing
keeps working precisely as it did.
