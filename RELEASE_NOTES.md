# Guidearr v1.22.8 — Legal pages, email verification & log tooling

Adds editable legal pages and code-based sign-up verification, and rounds out the admin log tooling.

## Highlights

### Editable legal pages
New public **`/privacy`**, **`/terms`** and **`/cookies`** pages, written in Markdown and fully
editable in **Admin → Legal** — each ships a starter template you can customise for your service and
jurisdiction, or reset to the shipped default. Footer links appear on the landing page and the
sign-in / registration screens. The default privacy policy already includes a **Google / Facebook
sign-in** section, ready for the planned social-login release.

> The default texts are starter templates, not legal advice — review and adapt them (and add your
> real contact details) before treating them as live.

### Email verification by code
Sign-up email verification now sends a **six-digit code** (with a resend cooldown) instead of a link,
which is friendlier behind strict mail filters. The **Environment** page gains a **Send test email**
button so you can confirm your SMTP settings — host, port, credentials, from-address — before saving.

### Better admin log tooling
- The **Send test email** button no longer disappears when mail isn't configured yet — you set mail
  up on that page, so the button is always there.
- The downloadable **log bundle** now also includes recently-rotated log files
  (`nginx-access.log.1`, `.gz`, …), so a support bundle keeps the full recent history even right
  after a rotation. The Logs viewer already lists every live log (`laravel`, `nginx-access`,
  `nginx-error`).

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear
```
No migration. After upgrading, review your legal pages under **Admin → Legal** and drop in your
contact details.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
