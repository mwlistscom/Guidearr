# v1.23.23 — Account security: reset links, admin two-factor, bans

> 🛑 **Security release. Upgrade soon.** The worst of these let anyone take over any account,
> an admin's included, if the owner clicked one genuine-looking password-reset email.
>
> ⚠️ **Check `APP_URL` before you upgrade.** Links in emails and pages are now always built from
> it. If it is not the address your users actually type, their reset and verification links
> will point at the wrong place. See **Upgrading** below.

---

## Security

### Password-reset links can no longer be pointed at someone else's site

Guidearr built the links in its emails from the host name the request arrived with, and it
trusted forwarded headers sent by *any* client. So an attacker could ask for a password reset for
any account, with `X-Forwarded-Host` (or simply `Host`) set to a server they controlled. The
victim then received a **genuine** reset email from your install, and its link carried their
reset token to the attacker. One click was enough to lose the account.

Links are now always built from **`APP_URL`**, whatever the request says.

### Visitors can no longer choose their own IP address

The same trust let a visitor set `X-Forwarded-For` to anything, and Guidearr believed it. That
undid every per-address protection: the login, registration and password-reset rate limits, the
cap on social sign-ups, and the playlist **IP lock**.

Guidearr now reads only `X-Forwarded-Proto` from a proxy. The bundled nginx already works out
the real client address (from `docker/real-ip.conf`) before PHP sees the request, so nothing is
lost.

### The admin login now asks for your two-factor code

The admin login page checked only the password, then signed you in to the same session as the
normal login. An admin who had turned on two-factor was protected at `/login` but not at the
admin login, so a phished or reused password alone was a full admin session.

An admin with two-factor enabled now gets the same code challenge as `/login`, and lands on the
admin dashboard once the code is accepted. Admins without two-factor log in exactly as before.

### A ban now actually ends access

Account status was checked in exactly one place: the password login. A banned account therefore
kept any session it already had, was signed straight back in by its remember-me cookie, could
sign in with a passkey, and **its playlists kept serving**. With
`REGISTRATION_REQUIRES_APPROVAL` on, a pending account could reach the dashboard the same way.

Status and the email ban list are now checked:

- **on every request.** A banned or inactive session is signed out and its remember-me cookie
  stops working.
- **at passkey sign-in.**
- **when a playlist is served.** A banned owner's playlist answers exactly like an unknown one.

A pending account can still enter its email verification code, which is the one thing it is
signed in to do.

### Dependency security updates

- **PHP:** `symfony/http-foundation` 8.1.9 fixes CVE-2026-108082, a ReDoS in the
  `Cache-Control` request header that **any unauthenticated request can reach**. Also updated:
  `symfony/yaml`, `league/commonmark`, `league/flysystem` and `phpseclib/phpseclib`.
- **JavaScript build tools:** vite 8.1.6 and five smaller packages. These run only while the
  image is built and are never served. The compiled JavaScript is byte-identical to before.

`laravel/framework` stays at 13.13.0 on purpose. Its one advisory is an XSS on the debug page,
which a production install (`APP_DEBUG=false`) never shows.

---

## Upgrading

**1. Check `APP_URL` in your `.env`.** It must be the full address your users use, including
`https://`, for example `APP_URL=https://guidearr.example.com`. If you serve Guidearr under
more than one name, pick the one you want links to use.

**2. Pull and rebuild:**

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

`--build` is what installs the updated PHP dependencies. Without it the app keeps the old ones
and logs a warning that the image and `composer.lock` don't match.

**3. Check it worked.** Open the forgot-password page and confirm its links point at your
`APP_URL`.

No migration and no other configuration change.

Users who are active and not banned will notice nothing. Anyone who is banned or not yet approved
is signed out on their next request, which is the point.
