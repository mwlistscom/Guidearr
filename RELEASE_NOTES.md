# Guidearr v1.22.13 — Playlists stay in sync with their providers

Your playlists now keep up with their providers automatically, and the "blank playlist" race is gone.

## Highlights

### New provider channels flow into your playlists
When a provider finishes refreshing, the channels it **gained** are now added to every playlist built
from it — automatically. Placement is tidy:

- A new channel joins the **end of its own group's block**.
- A **brand-new group** (with its channels) is appended at the **end** of the list.

The update is purely additive — your ordering, renames, group flags and deletions are all preserved,
and nothing is duplicated. Previously, new provider channels never reached existing playlists.

### No more blank playlists
Creating a playlist from a provider that was still importing used to capture **0** channels and serve
empty forever. Now you can't create a playlist from a provider that is still updating or has no
channels — the create dialog marks those providers *updating…* / *no channels yet* and blocks it, with
the same guard enforced server-side. Manual playlists (no provider) are unaffected, and you'll be
asked to confirm when you create one, in case you forgot to pick a provider.

### Repair command
`php artisan playlists:backfill [--dry-run]` audits every playlist against its providers and additively
fills in any channels that were never seeded — handy for fixing playlists created before this release.
It's safe to re-run: no duplicates, and it never brings back channels you deleted.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose build worker && docker compose up -d worker   # daemon picks up the refresh-sync
docker compose exec app php artisan optimize:clear
# optional: reconcile existing playlists now (preview with --dry-run first)
docker compose exec app php artisan playlists:backfill --dry-run
```
No migration. The refresh-sync applies on each provider's next refresh.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
