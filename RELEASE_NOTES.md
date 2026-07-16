# Guidearr v1.23.1 — Last login shows a registration-date fallback

A small follow-up to v1.23.0's admin Users table.

## What changed

Login tracking started in v1.23.0, so every existing account had no recorded sign-in and the **Last
login** column read **"never"** across the board. Now, when no sign-in has been recorded yet, the
column falls back to the account's **registration date** instead:

- Shown **italic/muted** with a *"No sign-in recorded yet"* tooltip, so it's obviously a fallback and
  never looks like a fabricated login.
- **Sorting** falls back the same way — never-signed-in users order by when they joined, not all piled
  at the bottom.
- The moment someone actually signs in, their real timestamp is shown and sorted normally.

Display-only — no migration, no configuration.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear   # refresh the cached view
```

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
