# Guidearr v1.23.7 — Security hardening

This release closes two ways a Guidearr install could expose itself, adds rate limits to every
public sign-in and sign-up route, and gives your firewall a list of the scanners hammering you.

> **⚠️ Two of these need a change to your own `docker-compose.yml` — see *Upgrading* below.**
> The compose file in this repo is an *example*; updating Guidearr does not touch yours.

---

## Your mail server, not ours

**The bundled `mailpit` service has been removed.** It published an **unauthenticated web inbox on
port `8025`, bound to every interface** — so on any install that followed the shipped compose file,
anyone who could reach the host could read every message Guidearr sent, **including password-reset
and email-verification links**. It was a development mail catcher that shipped to production.

Guidearr now relays through **your** SMTP server. `setup.sh` asks for the relay host, port and
optional credentials, choosing implicit TLS automatically for port 465. Leave the host blank and
mail is written to the Laravel log instead of being delivered — you can fill it in later under
**Admin → Environment**.

## The database is no longer on your LAN

The example compose file published MySQL on `0.0.0.0:33060`, putting the database on the local
network of every install that used it. It is now bound to `127.0.0.1`. Guidearr itself is
unaffected — it reaches MySQL over the Compose network, and the published port only ever served
local tooling. Use an SSH tunnel for remote access.

## A threat feed your firewall can block from

Any Guidearr on the public internet gets probed constantly for `/.env`, `/wp-login.php`,
`/.ssh/id_rsa` and the rest. Guidearr refuses all of it, but the noise is real — on one install
those probes were **74% of all requests**.

**Admin → Config → Threat feed** publishes the offending addresses as a plain-text list, one per
line, for **pfBlockerNG** (or anything that polls a URL) to block at the edge. Tick *Serve the
feed*, press **Copy**, and add it as a custom IPv4 source.

- **Nothing to run.** The secret URL is generated for you, the list builds itself on the first
  fetch and refreshes hourly. No command after an install or an upgrade.
- **Nothing is banned permanently.** The list is rebuilt from scratch every time and no address is
  ever stored, so a scanner that goes quiet drops off by itself and the list can't grow unbounded.
- **It will not list your users.** Any host that has successfully pulled a playlist is excluded
  outright — a customer's player can share an address with a scanner. Private and reserved
  addresses are never listed either, so your reverse proxy and health checks are safe.

*Worth knowing:* scanners rent cloud hosts and rotate them within a day or two, so a list of past
offenders blocks tomorrow's traffic only sometimes. Its dependable benefit is cutting log noise and
bandwidth. Use it alongside a curated blocklist, not instead of one.

## Rate limits on every public auth route

Registration, password-reset requests and reset submissions had **no limit at all** — one host hit
the sign-up form 21 times in 60 seconds, and only the optional CAPTCHA stopped it.

| Route | Limit |
|---|---|
| Sign-up | 5/min and 15/hour per address |
| Reset-link request | 5/min per address, 5/hour per target account |
| Reset submission | 10/min per address |
| Sign-in | 20/min per address, alongside the existing 5/min per account |
| Social sign-up | 10 new accounts/hour per address |

Two are worth calling out. **Sign-in was already limited, but per account** — which does nothing
about one host trying a common password against *many* accounts, since every address got its own
budget. And the **reset-link route mails an address the requester supplies**, so leaving it open was
a way to flood a third party's inbox from your server; it is now capped per target account as well
as per host.

**Signing up with Google or Facebook** is the one path with no CAPTCHA — a redirect back from the
provider has no form to solve a challenge in — so new-account creation there is capped per address.
**Signing in to an existing account is never counted**, so returning users are unaffected however
many people share an office or carrier address.

Every limit is tunable with `AUTH_LIMIT_*` in `.env`. Raise them if many genuine users share one
public IP.

## Also

- The sign-in, sign-up and password-reset screens now show **your** brand mark from
  **Admin → Branding**, instead of the framework's stock logo.

---

## Upgrading

Guidearr updates do not touch your `docker-compose.yml`. To pick up the two hardening changes:

```bash
# In docker-compose.yml:
#   1. db ports  ->  "127.0.0.1:33060:3306"
#   2. delete the whole `mailpit:` service block

docker compose up -d db
docker compose rm -sf mailpit          # if the container is still running
```

**Point `MAIL_*` at a real SMTP relay first** (or set `MAIL_MAILER=log`), otherwise password resets
and email verification stop working the moment Mailpit goes away.

Nothing else needs running — the threat feed and the rate limits are live as soon as you update.
