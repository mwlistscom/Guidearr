# v1.23.34 — Security: Fortify login-timing leak, passkey and Symfony libraries, MySQL 8.4.13.1, libxml2

> **This release needs an image rebuild and restarts your database once.**
> Upgrade with `docker compose up -d --build`, not a plain `up -d`. The PHP dependencies and
> the libxml2 fix live inside the image, so `git pull` alone leaves the vulnerable versions
> running. The MySQL image change recreates the `db` container, which takes about 50 seconds;
> Guidearr returns errors and playlists do not load until it is back. See **Upgrading** below.
>
> Every install is affected. No configuration change is needed and nothing is removed from
> your data.

---

## Fixed

### Login timing could reveal which email addresses have an account (Fortify)

`laravel/fortify` 1.37.2 → 1.44.0 fixes GHSA-72pr-vgpx-x986 (published 2026-11-25): the time a
failed login took differed between a registered and an unregistered address, and the advisory
names the custom `authenticateUsing()` callback that Guidearr uses. It is the same class of
problem as the registration enumeration closed in v1.23.25, on the login form this time.

### Passkey libraries: 40 advisories

`web-auth/webauthn-lib` 5.3.5 → 5.3.17, `web-auth/cose-lib` 4.5.2 → 4.8.2,
`spomky-labs/cbor-php` 3.2.3 → 3.4.2 and `spomky-labs/pki-framework` 1.4.2 → 1.6.3. Among the
findings: RSA signatures trivially forgeable under keys with public exponent 1, forged
packed/TPM attestations from PEM-encoded certificates, metadata trust-chain and REVOKED checks
skipped, username enumeration through decoy credentials, and CPU or memory exhaustion from
crafted CBOR and DER. Passkey sign-in uses these libraries directly. `laravel/passkeys`
0.2.1 → 0.3.0 came with them.

### Symfony 8.1.12, Laravel 13.44.1, Livewire 4.4.10, commonmark 2.10.10

- Symfony (2026-11-25): CVE-2026-133751 / CVE-2026-133755, the mailer's `require_tls` could be
  bypassed and replies injected by a man in the middle right after STARTTLS. Installs that
  relay reset and sign-up mail over port 587 with STARTTLS are the ones exposed; implicit TLS
  on 465 was not affected. Also fixed but not used by Guidearr: routing host placeholders,
  http-kernel inline fragments, and two serializer issues.
- Laravel 13.43.0 → 13.44.1: GHSA-c25c-x5r7-2v5m, resource exhaustion through extreme exponents
  in `multiple_of` and field-relative `gt`/`lt` rules. Guidearr does not use those rules.
- Livewire 4.4.8 → 4.4.10: GHSA-vw9g-2c4c-g767, a host-change bypass of persistent middleware
  on domain-constrained routes (none here), plus undisclosed fixes in 4.4.10.
- `league/commonmark` 2.10.7 → 2.10.10: three regular-expression denial-of-service advisories.
  Only admin-authored legal text is rendered with it.

`composer audit` is clean. Note that the Fortify, Laravel, Livewire and commonmark advisories
were **not** in the Packagist advisory feed at release time, so `composer audit` on v1.23.33
reports 47, not 51: the last four were found on the packages' own GitHub Security tabs.

### MySQL 8.4.13 → 8.4.13.1

Oracle's out-of-band *Critical Security Patch Update, November 2026* (2026-11-24). Eight MySQL
Server CVEs list 8.4.0–8.4.13 as affected, led by CVE-2026-114952 (client programs, CVSS 9.1)
and CVE-2026-92392 (bundled curl HTTP/2, 8.1); the rest need an authenticated database account.
Docker Hub re-pointed the `8.4.13` tag at the new build, so a host that already had `8.4.13`
cached would never have seen it. That is exactly why the tag is pinned and moved by hand.

### libxml2 in the PHP image

The official `php:8.4.28-fpm-alpine` image was last rebuilt on 2026-11-20 and ships
`libxml2 2.13.9-r2`. Alpine 3.24 has since released r3, r4 and r5, fixing CVE-2026-86140
(stack overflow in element validation, CVSS 8.0) and four 6.9 heap overflows in core parsing.
PHP parses provider-supplied XMLTV guides with libxml2, and anyone can point a provider at a
server they control. The Dockerfile now runs `apk upgrade` before building the extensions, so
every `--build` picks up Alpine's current fixes without waiting for the base image.

### nginx 1.30.7 → 1.30.8

Released 2026-11-23. Its one advisory, CVE-2026-132779 (TLS 1.3 early-data CPU exhaustion),
needs `ssl_early_data on`, which Guidearr's config never sets. Taken for the bundled
OpenSSL 3.5.9-r1 (five Low-severity CVEs of 2026-11-10).

## Added

### "Prune expired cache rows" maintenance task

Laravel's database cache driver never garbage-collects. An expired row is only removed when
that exact key is read again, and the per-IP rate-limiter buckets left by scanners never are.
On the reference install 2,015 of 2,016 cache rows were expired, the oldest by eleven weeks,
and the login-throttle keys among them carry the email address that was tried.

`cache:prune-expired` runs nightly and has a "Run now" button on the Maintenance tab. It
deletes only rows whose expiration has passed, and does nothing when your cache store is not
the database.

## Changed

- **"Prune missing channels" is gone from the Maintenance tab.** Every provider refresh
  already removes "(missing channel)" pointers, so the task has reported 0 since v1.22.14. The
  `playlists:prune-missing` artisan command remains for on-demand repair.
- CI actions updated (Dependabot). CI only.

## Upgrading

Back up the database first. MySQL upgrades its data files in place, and moving back to an
older build afterwards is not guaranteed to work:

```bash
cd Guidearr
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --events --databases "$MYSQL_DATABASE"' | gzip > guidearr-db-$(date +%F).sql.gz
zcat guidearr-db-$(date +%F).sql.gz | tail -1      # should print "-- Dump completed on ..."
```

Then upgrade. The rebuild reuses the cached PHP base image, so it takes a couple of minutes,
not the full extension compile:

```bash
git pull
docker compose up -d --build
```

To check it took:

```bash
docker compose exec db mysql --version                                   # 8.4.13 (the 8.4.13.1 build reports 8.4.13)
docker compose exec web nginx -v                                         # nginx/1.30.8
docker compose exec app composer show laravel/fortify | grep versions    # v1.44.0
docker compose exec app sh -c 'apk list -I 2>/dev/null | grep ^libxml2'  # 2.13.9-r5 or later
```

If the app container logs `composer.lock does not match this image` on start, the image was
not rebuilt: run `docker compose build app worker scheduler && docker compose up -d`.
