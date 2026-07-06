# Guidearr v1.22.9 — Social sign-in (Google & Facebook)

Adds optional **Sign in with Google and Facebook**, a place to configure it, account-connection
management, and the data-deletion pieces the providers require.

## Highlights

### Sign in with Google & Facebook
Optional social login (Laravel Socialite). When a provider is configured, a **Continue with
Google / Facebook** button appears on the login and registration pages. Signing in finds an
existing account, links to one with the same (provider-verified) email, or creates a new,
already-verified account. Social login also respects your security settings — a non-active account
can't sign in, and an account with two-factor enabled must use its password + 2FA.

### Admin → Social — configure it in the panel
A new **Social** page under Admin: per-provider **Enable** toggle, fields for the Client ID /
Secret / Redirect URI, and a **"How to set it up"** guide showing the exact callback, data-deletion
and privacy URLs to paste into the Google/Meta consoles (with copy buttons). **No `.env` editing** —
credentials are stored with the secrets **encrypted at rest**.

### Settings → Connected accounts
Users can **link or disconnect** Google/Facebook from their account, and **set a password** on a
social-only account so they can also sign in by email — and safely disconnect a provider.

### Data deletion
A Facebook **data-deletion callback** (required by Meta) and a human-readable, **editable**
**`/data-deletion`** instructions page (edit it in Admin → Legal). The default privacy policy links
to it.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan migrate            # adds social_accounts; makes password nullable
docker compose exec app php artisan optimize:clear
```
Social login stays **inert until you configure a provider** in Admin → Social, so the upgrade is
safe with nothing switched on. To enable it, register an OAuth app with Google and/or Meta, paste
the credentials on the Social page, and register the callback URL it shows you.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
