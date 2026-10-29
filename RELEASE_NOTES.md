# v1.23.28 — A blocked scanner stays blocked

> **Only affects you if you use the threat feed** (Admin → Config → Threat feed) with
> pfBlockerNG or another firewall. Upgrading is just `git pull`; no rebuild or restart is
> needed. Nothing else changes and no setting needs touching.

---

## Fixed

### The threat feed unblocked scanners once the firewall had blocked them

The feed lists addresses caught probing your install, for a firewall to block. It was rebuilt
from the nginx access log alone, and that log is trimmed when it gets large (15 MB → the newest
5 MB). Together, those made the feed undo itself:

1. A scanner is listed, and the firewall blocks it.
2. Blocked, it can't reach Guidearr, so it adds nothing more to the log.
3. When the log is trimmed, its old entries go, and the next rebuild leaves it off the list.
4. The firewall's next download of the list lets it back in.

On the install where this was found, the feed fell from 38 addresses to 5 in a day while those
scanners were still active.

**Listed addresses are now remembered**, in `storage/app/threat-feed-state.json`. Each stays
listed for **14 days after its last attack** (`THREAT_FEED_WINDOW_DAYS`), whether or not the
log still has its entries. If it attacks again, the 14 days start over. After 14 quiet days it
drops off, so nothing is blocked permanently.

The protections are unchanged and apply to remembered addresses too:

- an address that successfully pulls a playlist comes off the list;
- private and reserved addresses, and anything in `THREAT_FEED_ALLOWLIST`, are never listed;
- raising *List after N attacks* removes addresses below the new number;
- the list is still capped at 5,000 entries.

A new address still needs the usual number of attacks within what the log currently holds.

The record starts empty on upgrade, so addresses that had already dropped off are only listed
again if they attack again.

---

## Upgrading

```bash
cd Guidearr
git pull
```

That's all. The change is PHP code only, and the app reads it from your checkout directly.
The list rebuilds itself within the hour, or straight away with
`docker compose exec -u www-data app php artisan security:threat-feed`.
