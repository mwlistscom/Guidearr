# v1.23.17 — Playlists that only contain what you put in them

A security fix for the served playlist, and two new maintenance tasks for clearing out accounts
and content nobody uses.

> **The weekly reaper starts running on its own after this upgrade.** It deletes permanently.
> Read **Upgrading** before the first Sunday.

---

## Security

### A hostile provider could add channels to your playlist

The m3u Guidearr serves is a line-based format, and three of the fields written into it — the
channel's display name, its group and its stream URL — were written with no sanitising at all.
Only the five quoted `#EXTINF` attributes were cleaned, and even those only had `"` removed.

So a channel named:

```
Sky Sports
#EXTINF:-1,Free Movies
http://attacker.example/evil.ts
```

did not show up as an oddly-named channel. It **added a channel** to the playlist, pointing
wherever the attacker chose — and it survived your curation, because you never chose it in the
first place and so never disabled it.

**This was reachable from your provider, not just from your own account.** Xtream channel names
arrive as JSON, where a newline is perfectly legal, so a hostile or compromised provider could put
entries into a subscriber's playlist. (An M3U source could not — that parser is line-based and
cannot carry a newline through in the first place.)

Every field is now forced onto a single line before it is written. The channel itself is kept
rather than thrown away: the name is cosmetic, and one oddly-named channel is a better outcome
than one that silently disappears.

**The EPG output was never affected.** It is written with `XMLWriter`, which escapes its own text.

**How much did this matter?** It needed a provider you subscribe to, and anyone who controls that
already controls what the channels you *did* add actually play. What it added was entries you
never picked. It was never a way into the site, or into anyone else's account.

---

## Added

### Two new maintenance tasks

Both appear on **Admin → Maintenance**, and both are destructive, so they get the same treatment as
the existing ones: preview first, then an explicit **Apply for real**.

**Prune idle accounts** — deletes accounts that registered, never set anything up, and have sat
that way for 30 days. "Never set anything up" means **no providers and no playlist with any
channels in it**. One provider, or one playlist with channels, protects the account. Admins are
never touched. Manual only — nothing happens until you run it.

**Reap stale playlists & providers** — permanently deletes playlists and providers nothing has
accessed for 60 days, and their stored data. **Runs weekly, on its own.**

This is the stage after the existing daily reaper, which only *disables* a provider after 14 days
and brings it straight back the moment anything uses it. Here the playlist or provider is gone.

Two things it will not do:

- **A provider still attached to a playlist you use is never deleted**, even if the provider itself
  looks idle. Your channels are pointers into that provider's data, so removing it would leave the
  playlist full of "(missing channel)" rows serving nothing.
- **A playlist created recently is never reaped**, even if nobody has opened it yet.

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

### Before the first Sunday

The weekly reaper deletes permanently, and a deleted playlist takes its ordering, group choices and
renames with it. None of that comes back.

Activity means *human* activity — opening the editor, or a player fetching the playlist — so
anything genuinely in use keeps itself alive without you doing anything. But it is worth one look:

```bash
docker compose exec app php artisan maintenance:reap-stale --dry-run
```

That changes nothing and prints exactly what a real run would remove, with the age of each item.
If 60 days is tighter than suits you, change `--days` on the schedule line in `routes/console.php`,
or remove the line to keep the task manual-only like the account prune.
