# Guidearr v1.23.4 — Browsing counts as activity

v1.23.0 added an inactivity reaper: a provider nobody has used for **14 days** is disabled so
Guidearr stops downloading a feed nobody is watching. This release fixes what "used" means.

## Looking around now counts

Previously only *serving* a playlist or *editing* one marked things as active. If you logged in,
opened your playlist, checked a channel and logged out, none of that registered.

Now **viewing counts too**:

- opening a playlist marks it — and every provider behind it — as active;
- opening a provider marks that provider;
- just loading the **Playlists** or **Providers** list marks everything it shows.

And if you view a provider that had already been disabled for inactivity, it **turns back on
immediately** — no need to edit anything. Providers disabled by repeated fetch failures, or by an
admin, are still left alone.

## Providers with no playlist

A provider you added but never attached to a playlist has no serve traffic to keep it warm. If
nobody views or edits it for **14 days**, it is now disabled and stops refreshing — no more nightly
downloads for a feed nobody is using.

**Nothing is deleted.** Its channel data, settings and credentials are all kept. Open it — or just
load the Providers list — and it resumes on the next scheduled refresh.

## "Last activity" means *you*

Background jobs no longer count as activity. The refresh worker, the scheduler, and the URL /
credential migrators from v1.23.2–v1.23.3 used to be able to mark a provider as recently used, which
meant an abandoned provider could look busy forever and never be reaped. Only real user
activity — a view, an edit, or a playlist being served to your player — sets it now.

The **Maintenance** page's activity columns are correspondingly more honest about what has actually
been used.

## Good to know

- Activity is recorded at most **once per hour per item**, so a dashboard left open in a tab doesn't
  generate constant database writes.
- **Playlists are never disabled** by inactivity — only provider refreshing stops, so your playlist
  URLs keep serving throughout.
- Preview what would be disabled at any time:
  `docker compose exec app php artisan providers:reap-cold --dry-run`

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear   # refresh cached views/config
```
No migration and no frontend rebuild are required.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
