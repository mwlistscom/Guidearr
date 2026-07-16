# Guidearr v1.23.0 — Admin table upgrades & self-maintaining feeds

A friendlier admin, and feeds that quietly clean up after themselves.

## Highlights

### The admin Users table grew up
- New **Registered** and **Last login** columns, plus a **currently-logged-in** dot that lights up
  for anyone with an active session right now.
- **Sort** any column, **filter** by name/email/status (including *online only*), and page through
  **25 at a time**.

### Paginated feed tables
The **Feeds** job queue now paginates at **25/page**, and the Users list on that page is a sortable
grid with a **user ID** column. The Maintenance page's provider-activity table now shows each
provider's **owner** and user ID, so it's obvious whose feed each row belongs to.

### Feeds that stop working when nobody's watching
`providers:reap-cold` runs daily and **disables** any provider whose playlists haven't been served —
or edited — for **14 days** (which also covers providers with no playlist at all). This stops the
worker wasting cycles refreshing feeds nobody uses.

- **Nothing is deleted.** The feed store and all settings are kept.
- **It heals itself.** The next time you serve or edit one of that provider's playlists, it
  re-enables automatically.
- **It's careful.** A provider disabled by repeated fetch failures or by an admin is never touched,
  so nothing broken gets resurrected into a loop.

```bash
docker compose exec app php artisan providers:reap-cold --dry-run   # preview
```

### Abandoned signups clean themselves up
`users:prune-unverified` runs daily and deletes accounts that never verified their email within
**14 days** of registering. **Admins are always protected**, and accounts you create by hand (which
are verified on creation) are never affected.

```bash
docker compose exec app php artisan users:prune-unverified --dry-run   # preview
```

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan migrate            # adds last_login_at
docker compose up -d --force-recreate scheduler        # register the two new daily jobs
docker compose exec app php artisan optimize:clear
# preview the daily jobs before trusting the schedule:
docker compose exec app php artisan providers:reap-cold --dry-run
docker compose exec app php artisan users:prune-unverified --dry-run
```
One migration (`last_login_at`); no frontend rebuild.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
