# Guidearr v1.22.14 — "(missing channel)" rows are pruned from playlists

Dead rows no longer pile up in your playlists. Provider refreshes now clear them out, and a new
command cleans up the ones that already accumulated.

## Highlights

### Where "(missing channel)" came from
A playlist row isn't a copy of a channel — it's a **pointer** at a channel in a provider's store, and
provider channels are keyed on their **URL**. So when a provider drops a channel, or simply rotates its
URL, the old channel is swept away after more than three missed refreshes and your playlist is left
pointing at nothing. That's the **"(missing channel)"** row with a blank URL.

Those rows were never served to your players — the serve path already skipped them — but they cluttered
the editor, and because playlist sync only ever **added** channels, they accumulated forever.

### Refreshes now remove as well as add
When a provider finishes refreshing, each attached playlist is reconciled against it: new channels are
added (as in v1.22.13) **and** orphaned pointers are dropped, in the same pass. Your ordering, renames,
group flags and deletions are all still preserved, and nothing is duplicated or resurrected.

### Cleanup command
`php artisan playlists:prune-missing [--dry-run]` clears orphaned pointers from every playlist right
now, instead of waiting for each provider's next refresh — useful once, just after upgrading.

```bash
docker compose exec app php artisan playlists:prune-missing --dry-run   # preview
docker compose exec app php artisan playlists:prune-missing             # apply
```

### A provider outage can't blank your playlists
Both the refresh sync and the command **skip any provider whose store is missing or currently empty** —
mid-import, or a fetch that failed. Without that guard, a provider that briefly returned zero channels
would look like "every channel is gone" and empty every playlist built from it. Pruning only ever
removes a pointer whose channel is genuinely absent from a healthy, populated provider store.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose build worker && docker compose up -d worker   # daemon picks up the reconcile
docker compose exec app php artisan optimize:clear
# clear orphans that already accumulated (preview with --dry-run first)
docker compose exec app php artisan playlists:prune-missing --dry-run
```
No migration. The worker must be rebuilt and recreated for the reconcile to take effect — until then
the old additive-only sync keeps running. The reconcile then applies on each provider's next refresh.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
