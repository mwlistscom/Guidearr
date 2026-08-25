# v1.23.11 — Drags that land, playlists that open, providers that stay enabled

Three reliability fixes in the playlist editor and the refresh worker. One of them stopped an
affected playlist opening at all, so this is worth taking promptly.

---

## Fixed

### Dragging a channel while the editor is filtered now drops it where you let go

The **#** column counts the *filtered* list — filter on `ESPN` and the matches are numbered 1, 2, 3
no matter where they actually sit in the playlist — but the drag sent that number to the server as a
position in the **whole** playlist. Dropping a channel into the third visible slot moved it to row 3
of the entire list instead of in between the two channels it was dropped between.

A move is now anchored on the **row it was dropped against** rather than on a row number, so it lands
immediately after that channel wherever it lives. The same drag is now correct across pages and on a
grid sorted by a column, which had the same flaw.

**"Move to row #" means the row you can see.** With a filter active the number is read against the
filtered list, matching the **#** column the dialog prefills from. With no filter it still means a
position in the whole playlist, exactly as before.

### A playlist could fail to open at all if one channel name held a stray byte

Provider feeds are not reliably UTF-8, and the editor's channel grid is delivered as JSON — which
refuses to encode invalid text. A single bad byte in a single channel therefore returned a **500 for
the entire grid**, so the playlist showed nothing rather than one damaged name.

Two things caused those bytes, and both are fixed:

- Feeds published in **Windows-1252** are now decoded properly, so `AMC en Español`, `Pokémon` and
  `America's Funniest Home Videos` read correctly instead of breaking the page.
- Guidearr's own length caps on `tvg-id` and channel names **no longer cut a character in half** —
  they trimmed by bytes, which could leave two thirds of a dash behind.

Existing installs are repaired **on read**, so an affected playlist opens again immediately after
upgrading, without waiting for the next provider refresh.

### A brief upstream hiccup no longer disables a provider

A failed refresh went straight back on the queue with no delay, so the worker picked it up again
within milliseconds — and a provider that was briefly unreachable burned its entire error budget,
**four failures in about one second**, and was switched off. That is one failure counted four times,
not four attempts.

Retries now wait **1 minute, then 5, then 15**, so the budget spans about twenty minutes of genuinely
separate attempts and a provider that comes back in that window is never disabled at all. The
provider log shows when the next attempt is due.

Pressing **Run**, and the scheduled refresh, still start immediately — neither waits behind a
backoff. The delays are tunable with `FEED_RETRY_BACKOFF` (set it empty for the old behaviour).

---

## Upgrading

> **This release needs a database migration.** The retry backoff adds a `retry_after` column to the
> feed queue, and the worker queries it — so migrate before the new code handles a job.

```bash
git pull
docker compose exec app php artisan migrate
docker compose up -d worker
```

The worker restart matters: `feed:supervise` is a long-running process and keeps the old scheduling
until it is recreated. Its `feed:work` children are fresh per job, so the backoff itself applies
straight away, but the pool sizing does not.

**No image rebuild is required** — this release does not change the `Dockerfile`, and there are no
frontend assets to compile. A plain `git pull` plus the two commands above is enough.
