# v1.23.31 — Old Joomla URLs are answered 410 Gone and count toward the threat feed

> **This release needs a restart of the `web` container.** `git pull` alone changes nothing that
> is running. See **Upgrading** below; it's two commands. No image rebuild.
>
> Every install is affected, but only if bots are replaying Joomla URLs at it. No configuration
> change is needed and nothing is removed.

---

## Changed

### Stale Joomla URLs no longer reach PHP

Many Guidearr installs sit on a domain that used to run something else. Bots, and search
engines that never forgot, still replay those old URLs. On one install that was
`/index.php?option=com_virtuemart&…` and `/?Itemid=…&option=com_content`: about **2,000
requests in five days from 170 addresses**, most carrying a forged `X-Forwarded-For`, paced
just under the per-IP PHP rate limit.

Guidearr has no such route and answered every one with the home page. That is a `200` that costs
a full PHP render, and because nothing about it looks hostile to the threat feed, none of those
addresses were ever listed.

nginx now answers any `/` or `/index.php` request that carries an `option=com_…` query parameter
with **`410 Gone`** before PHP is reached. Guidearr never generates such a URL, so nothing
legitimate is affected; `?page=2`, playlist URLs and anything else pass through untouched. A
`410` also tells search engines to drop the link. The threat feed counts `option=com_` at `410`,
so hosts that keep replaying are listed once they pass your threshold. Exploit POSTs to those
URLs, which used to be `405`, are `410` now and still count.

One thing to know: a search engine following stale links gets `410`s too and is counted. With
the default threshold of 20 attacks and a feed window of 14 days that has not been close on the
install this was written for (the busiest crawler address had 11), and `410` is exactly what
makes a crawler stop asking. Glance at **Admin → Config → Threat feed** a week after upgrading.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --force-recreate --no-deps web
```

The rule lives in `docker/nginx.conf`, which is mounted into the `web` container as a file.
After `git pull` the running container keeps reading the version it started with, so it has to
be recreated; a reload alone is not enough. `--no-deps` keeps the database and app containers
out of it.

No rebuild, no migration and no `.env` change is needed. To check it took:
`curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8181/index.php?option=com_content'`
should print `410`.
