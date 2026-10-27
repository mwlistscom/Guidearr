# v1.23.26 — Laravel and Livewire security updates; the database restarts after a reboot

> **Security release. ⚠️ It needs a rebuild:** `git pull` alone does **not** apply it. The
> patched packages are installed by the image, so run `docker compose up -d --build` (see
> **Upgrading** below).
>
> It updates Laravel and Livewire for four published advisories, one of them rated critical. It
> also fixes the database not coming back after the host reboots.

---

## Security

### Laravel 13.13.0 → 13.39.0, Livewire 4.4.3 → 4.4.8

| Advisory | Severity | What it is |
| --- | --- | --- |
| CVE-2026-114509 | critical | Laravel `TokenGuard`: authentication bypass via non-string API credentials |
| CVE-2026-114508 | medium | Laravel: relationship inclusion can trigger unauthorized data destruction |
| CVE-2026-102279 | low | Laravel: XSS on the debug error page |
| GHSA-mhf7-xp6r-6qrc | medium | Livewire: `#[Authorize]` bypass via reordered parameter keys |

**As far as we can tell, Guidearr was not exposed to any of these.** It uses session sign-in
only, with no API token guard. It has no JSON:API relationship includes and doesn't use
Livewire's `#[Authorize]`. The debug page only appears with `APP_DEBUG=true`, which a production
install shouldn't run with. These are patched anyway. That conclusion comes from reading the
code, not from a proof, and security scanners will keep flagging an install until the packages
are updated.

Laravel's own dependencies move up with it (Symfony 8.1 patch releases, Carbon, Guzzle and
others). Nothing outside Laravel and Livewire was updated, and `composer audit` now reports no
advisories.

---

## Fixed

### The database now comes back after a reboot

`docker-compose.yml` gave every service a restart policy except MySQL. So after the host
rebooted (or Docker restarted), the app, worker and proxy came back up but the database didn't.
Every page and every playlist URL then failed until someone ran `docker compose up -d` by hand.
This affects every install since v1.23.14, when the compose file was first tracked.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
```

**`--build` is required.** The new Laravel and Livewire are installed by the image. Without
`--build`, the app keeps the old, vulnerable packages. The next time the app container starts,
it logs a warning that the image's `composer.lock` doesn't match the checked-out one.

`up -d` also applies the database's new restart policy. The database restarts once, for a few
seconds. No migration and no `.env` change is needed.
