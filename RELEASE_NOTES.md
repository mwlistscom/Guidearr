# v1.23.33 — MySQL is pinned to 8.4.13, so its security releases reach every install

> **This release restarts your database once.** The first `docker compose up -d` after
> `git pull` recreates the `db` container, which takes about 40 seconds. Guidearr returns errors
> and playlists don't load until it is back. See **Upgrading** below. No image rebuild is needed.
>
> Every install is affected. No configuration change is needed and nothing is removed.

---

## Fixed

### MySQL security releases now arrive with an upgrade

The database ran from the floating tag `mysql:8.4`. Docker never re-pulls a tag it already has,
and `docker compose up -d --build` only rebuilds the services Guidearr builds itself. So an
install kept whichever 8.4 patch it happened to download first, however old, and none of
Oracle's quarterly MySQL security releases ever reached it. v1.23.32 fixed the same problem for
nginx.

The compose file now names an exact patch, **`mysql:8.4.13`** (October 2026). Docker pulls it
because it is new, the changed image recreates `db`, and MySQL upgrades its own data dictionary
as it starts. From now on each MySQL security release ships as a Guidearr release that moves the
pin forward, always within the 8.4 LTS series.

If your install was already on 8.4.13 (because you pulled `mysql:8.4` recently), nothing about
the database changes; the restart only happens because the tag's name changed.

If you set your own MySQL image in `docker-compose.override.yml`, the override still wins.

---

## Upgrading

Back up the database first if you don't have a recent backup. MySQL upgrades its data files in
place, and moving back to an older version afterwards is not guaranteed to work:

```bash
cd Guidearr
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --events --databases "$MYSQL_DATABASE"' | gzip > guidearr-db-$(date +%F).sql.gz
zcat guidearr-db-$(date +%F).sql.gz | tail -1      # should print "-- Dump completed on ..."
```

Then upgrade:

```bash
git pull
docker compose up -d
```

To check it took:

```bash
docker compose exec db mysql --version     # mysql  Ver 8.4.13 ...
```

If you skipped v1.23.32, use `docker compose up -d --build` instead; that release needs the
rebuild.
