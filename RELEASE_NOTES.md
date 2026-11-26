# v1.23.37 — Legacy Joomla rule also catches the apostrophe variant

> **nginx config only, follow-up to v1.23.35 and v1.23.36.** No image rebuild and no database
> change. The proxy config is bind-mounted, so after `git pull` the `web` container must be
> recreated for the change to take effect. See **Upgrading** below.

---

## Fixed

### Quote-appended Joomla replays were slipping past the rule

SQL-injection scanners replay the old Joomla URLs with a quote appended to the **path**:
`/index.php%27?option=com_content&view=category&id=2&Itemid=140`, `/%27?option=…`, and the raw
`'` forms. The rule pins the path to `/` or `/index.php`, so these missed it and reached Laravel
for a 404 render that the threat feed could not attribute to the sender.

The rule's path part now accepts one trailing apostrophe, raw or `%27`. The threat feed's match
mirrors it. Nothing else about the pin changes: an apostrophe on any other path, a doubled quote,
or a quoted path without `option=` still pass through to the application as before.

`LegacyJoomlaUrlsTest` carries both the caught and the pass-through shapes. Verified against the
real nginx binary before release.

## Also in this release

- The v1.23.34 changelog entry is corrected: the Symfony mailer STARTTLS advisories
  (CVE-2026-133751 / CVE-2026-133755) apply to installs relaying mail over port 587 with
  STARTTLS. Implicit TLS on port 465 was never affected. The packages were updated either way.
- A timing flake in the registration test suite is fixed (test only; nothing in an install changes).

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --force-recreate --no-deps web
```

`--no-deps` matters: without it compose also recreates the database container. If you are
coming from v1.23.33 or earlier, use `docker compose up -d --build` instead and expect the
database restart described in v1.23.34.

To check it took:

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8181/index.php%27?option=x&view=a'   # 410
```
