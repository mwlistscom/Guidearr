# v1.23.35 — Legacy Joomla rule catches injection probes with the payload in option=

> **Small follow-up to v1.23.34, nginx config only.** No image rebuild and no database change.
> The proxy config is bind-mounted, so after `git pull` the `web` container must be recreated
> for the new rule to take effect. See **Upgrading** below.

---

## Fixed

### Injection probes aimed at the old Joomla URLs were reaching PHP

v1.23.31 taught nginx to answer the old Joomla site's URLs (`/index.php?option=com_…`) with
410 Gone before PHP is involved, and the threat feed to count them. That rule matched only when
the `option=` value began with `com_`. Scanners that put a SQL or template-injection payload
straight into `option=`, alongside Joomla's `view=` and `Itemid=`, walked past it to Laravel,
which rendered the normal home page:

```
GET /index.php?option=1' AND 1=1 UNION SELECT NULL-- -&view=article&id=1&Itemid=140   → 200
GET /index.php?Itemid=162&option=${@var_dump(md5(1))};&view=article                    → 200
```

Nothing was evaluated (every response was the unchanged home page), but each one cost a full
PHP render and the threat feed could not count the sender. On the reference install 53 such
requests from 5 addresses got through in the rule's first two weeks.

nginx now answers **any** `option=` query on `/` or `/index.php` with 410. The threat feed counts
exactly the same shape at 410, path pin included, so a Guidearr route that happens to answer 410
with its own `option` parameter is never listed. Replayed against the reference install's log,
the new rule catches all 531 historic `option=` requests that had reached PHP and leaves every
existing 410 and every legitimate URL unchanged. `LegacyJoomlaUrlsTest` carries the live payload
shapes.

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --force-recreate --no-deps web
```

`--no-deps` matters: without it compose also recreates the database container. If you skipped
v1.23.34, use `docker compose up -d --build` instead and expect the database restart described in
that release.

To check it took:

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8181/index.php?option=x&view=article'   # 410
```
