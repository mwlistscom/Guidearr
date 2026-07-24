# Guidearr v1.23.5 — A CAPTCHA on the sign-in page

The admin login and the sign-up page already sat behind a Cloudflare Turnstile CAPTCHA. The main
**sign-in page** was the one door still without one — and it's exactly the door automated
password-guessers were rattling. This release closes that gap.

## What changed

The public `/login` form now shows the same near-invisible **Turnstile** widget as the rest of the
app. A bot filling in email-and-password guesses is stopped by the CAPTCHA **before any password is
checked**, on top of the existing rate limit. If you sign in normally you'll barely notice it — the
widget usually verifies on its own with no puzzle to solve.

Signing in also feels a little clearer now: the button disables and shows a **"Signing in…"** hint
while the CAPTCHA is verified, so a slow moment doesn't tempt a second click.

## Turning it on

The CAPTCHA only activates when Turnstile keys are configured, so **nothing changes for installs
that haven't set them up** — no surprise CAPTCHA appears on your login page.

To enable it:

1. Create a free **Turnstile** widget at the Cloudflare dashboard (Turnstile → Add site) to get a
   **Site Key** and a **Secret Key**.
2. Set them in your environment (the admin **Environment** editor, or `.env`):
   ```
   TURNSTILE_SITE_KEY=your-site-key
   TURNSTILE_SECRET_KEY=your-secret-key
   ```
3. Reload — the widget appears on sign-in, sign-up and the admin login.

Leave the keys unset and Guidearr behaves exactly as before.

## Good to know

- Same protection already covered the **admin login** and **registration**; this brings the public
  login in line.
- The CAPTCHA is verified **once per sign-in**, and stacks on top of the login rate limit (a handful
  of attempts per minute per account).
- No new outbound dependency for your players or playlist URLs — this only affects the browser
  sign-in page.

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
