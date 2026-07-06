# Guidearr v1.22.10 — Interactive admin recovery & social-account badges

A security and quality-of-life release: the admin password is out of `.env`, admin recovery is now
a safe interactive command, and social sign-ins are easier to spot in the admin.

## Highlights

### Interactive admin recovery — no password in `.env`
Recover a locked-out or deleted admin with a single command:

```bash
docker compose exec app php artisan admin:password
```

It prompts for the email and a new password **entered hidden** (nothing on the command line or in
shell history), then **creates** the admin if none matches (e.g. it was deleted) or **resets** the
matching account — re-activating and email-verifying it as a side effect. This replaces the old
`admin:sync` command and the `ADMIN_EMAIL` / `ADMIN_PASSWORD` `.env` variables, which are **removed**
so the admin password is no longer stored in plain text.

### Social-account badges in Admin → Users
A small **G** (Google) or **F** (Facebook) badge next to a user's role marks a linked social
identity, so you can tell social sign-ins apart from email/password accounts at a glance.

### Readable social buttons
The **Continue with Google / Facebook** button text is now white, so it's legible on the dark
sign-in and registration pages.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear
```
No migration. After upgrading you can delete the now-unused `ADMIN_EMAIL` / `ADMIN_PASSWORD` lines
from your `.env` (they're dead — the Environment page no longer shows them). If you ever need to
reset or recreate the admin, run `php artisan admin:password`.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
