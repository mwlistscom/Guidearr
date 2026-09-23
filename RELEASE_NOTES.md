# v1.23.21 — A provider delete that doesn't leave dead playlists behind

Deleting a provider used to quietly break playlists. It no longer does, and it tells you what it
is about to do first.

---

## Changed

### Deleting a provider now takes the playlists that depended on it

A playlist does not hold copies of channels. It holds *pointers* into the provider the channels
came from, plus your own work on top — the ordering, the renames, which groups are switched off.

So when a provider was deleted, its playlists did not fail. They stayed, still enabled, still
serving, but every channel that came from that provider became a *"(missing channel)"* row that
plays nothing. A playlist backed by only that one provider became an empty shell that still
answered its URL. Nothing anywhere said why, and the confirmation you had clicked said only
*"Delete provider?"* — it never mentioned playlists at all.

Now, a playlist whose **only** source was that provider is deleted along with it, channel-list file
included.

### A playlist another provider still feeds is never deleted

This is the more important half. If a playlist draws on two or three providers, deleting one of
them leaves the playlist alone. It keeps working, it keeps your ordering and renames and group
settings, and it simply loses the channels that came from the provider you removed — the same
thing that already happens when a provider drops a channel during a normal refresh.

Your curation of the *other* providers' channels is not something a provider delete has any
business throwing away.

A playlist that used the provider only as its **guide (EPG) source** is kept too. Losing a guide is
not losing your channels; the playlist carries on and the guide source is simply cleared.

### The confirmation is a real dialog that names everything

Instead of a browser pop-up, you get a dialog listing exactly what is going:

- the provider, by name
- every playlist that will be deleted with it
- every playlist that is **kept**, and what each one loses

The confirm button says what it will do — *"Delete provider + 2 playlists"* — rather than just
*OK*. If the check of what would be affected cannot be completed, the delete is refused outright
rather than falling back to a vague prompt. You are never asked to approve a deletion whose extent
is unknown.

### The playlist list refreshes afterwards

The playlists removed by a delete are shown on a different page, which the app can restore from its
cache — so a playlist that no longer existed could still appear in the list. It is now refreshed
after a delete, so what you see is what is actually there.

---

## Fixed

### A deleted provider no longer leaves dangling references behind

The link rows tying playlists to their providers, and a playlist's chosen guide source, had no
database constraint tidying them up. A deleted provider left both pointing at something that no
longer existed. Surviving playlists now have those cleared as part of the delete.

Existing installs are not affected retroactively and there is nothing to repair — this stops new
ones being created.

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

**This release does not change the image** — no `Dockerfile`, dependency or frontend-asset changes
are involved, so a plain `git pull` is genuinely enough here. The `--build` above is kept because it
is always safe and costs only a moment when there is nothing to rebuild.

If you have providers you have been avoiding deleting because you were not sure what would happen
to your playlists, the new dialog will tell you before anything is touched — and you can cancel.
