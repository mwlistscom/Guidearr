# Guidearr v1.23.9 — Assets built into the image, and a feed pfBlockerNG can read

Frontend assets are now compiled during the image build, so upgrading actually gives you the
current stylesheet. The threat feed is reshaped into something firewalls accept without argument.

> **⚠️ `docker compose up -d --build` is required.** A plain `git pull` leaves you on the old
> image and the old stylesheet. See *Upgrading* below.

---

## Upgrading no longer leaves you on a stale stylesheet

`public/build` is gitignored, and neither the `Dockerfile` nor the documented upgrade path ever ran
`npm run build`. So an upgraded install kept whatever CSS it happened to have, and **any newly added
style silently did nothing** — a class the local stylesheet had never compiled simply had no effect.

The image now compiles the frontend during the build and publishes it into `public/build` when a
container starts. **Node is not needed on the host, and there is no extra step to run** — a normal
`docker compose up -d --build` is enough.

There is a wrinkle worth knowing about if you maintain a fork: assets cannot simply be left at
`public/build` inside the image, because compose bind-mounts `./` over `/var/www/html` and hides
them. They are staged outside that path and copied into place at start-up, and that publishing step
is deliberately fail-open — a container will never refuse to boot because assets could not be
copied.

## A threat feed pfBlockerNG accepts

Two changes, both from trying to actually add the feed to pfBlockerNG:

- **The URL now ends in `.txt`.** pfBlocker works out a list's format from the URL and rejects one
  with no file extension. The address shown in **Admin → Config** carries the extension. The route
  ignores it, so the bare form still resolves and **an install already configured against the old
  URL keeps working**.
- **The document is now bare addresses** — one IP per line, no header, no `#` comments. pfBlocker's
  parser strips comments, but plenty of tools that read a URL list do not, and a stray comment is
  the difference between a working source and one that silently imports nothing. Everything the
  header carried — the criteria, the entry count, when it was last built — is on the
  **Admin → Config** page, where it can actually be read.

An empty feed is now an empty document rather than a lone header, which a strict parser could treat
as malformed.

Settings that work: **Format `Auto`**, **State `On`**, **Source `URL`**, and any unique
Header/Label.

## Fixed

- **The brand mark was clipped top and bottom on the dashboard.** The component wraps the logo in a
  24 px box with `overflow-hidden` inside a fixed-height row, so a larger mark was cut off — while
  the admin sidebar, which has no fixed row height, showed it whole. Both now render it the same
  way.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build      # required: assets are compiled in the image
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

Without `--build` you keep the previous image, and therefore the previous stylesheet — the very
problem this release fixes.

No migrations are specific to this release and there is no configuration to change.
