# Guidearr v1.23.8 — Branding overhaul, and uploads that no longer time out

Everything about brand images: uploading them, storing them, serving them, and what ships by
default. Plus the reason a logo upload could sit for five minutes and then fail.

> **⚠️ This release changes the `Dockerfile`.** A plain `git pull` is not enough — you need
> `docker compose up -d --build` or the upload fixes won't take effect. See *Upgrading* below.

---

## Uploading a logo could hang and end in a 504

The official PHP image ships **no `php.ini` at all** — only the inactive `-development` and
`-production` templates — so PHP ran on its compiled-in defaults. Four layers disagreed about how
big an upload may be:

| Layer | Limit |
|---|---|
| nginx `client_max_body_size` | 20 MB |
| **PHP `post_max_size`** | **8 MB** |
| **PHP `upload_max_filesize`** | **2 MB** |
| Laravel validation | 10 MB |

Over 8 MB, nginx accepted the body and PHP abandoned it without reading — so nginx waited out its
upstream timeout and the browser showed **504 Gateway Time-out after five minutes**. Between 2 MB
and 8 MB the file was silently discarded and the upload simply bounced back with no explanation.

Guidearr now ships a `php.ini` with the layers nesting properly — **20 MB ≥ 16 MB ≥ 12 MB ≥ 10 MB**
— with the app's own rule the tightest, so an oversized file produces a clear message at the one
layer that can explain it.

## Brand images are resized on upload

The app image now includes GD, and an uploaded icon or logo is capped at the largest size it is ever
displayed: **512 px for the icon, 1200 px for the logo**. A full-resolution export no longer becomes
a multi-megabyte download for every visitor.

It only ever shrinks. Aspect ratio and transparency are preserved, anything already within the cap
is stored byte-for-byte untouched, and animated GIFs are skipped because GD would keep only the
first frame. If the resize can't run — no GD, an unreadable file, an image too large to decode
within the memory limit — **the upload is kept exactly as it arrived rather than lost**.

## Brand assets stopped being re-downloaded on every page view

The icon and logo are served with `no-cache` so a fresh upload appears immediately — but nothing
ever compared the validator, so a browser's conditional request was answered with the entire image
every single time. They now carry an **ETag** and honour `If-None-Match` / `If-Modified-Since`, so
revalidating costs a **304 with an empty body**.

On one install those two files accounted for **792 MB in six days**, with not a single 304.

## The default logo had a checkerboard baked into it

The shipped `logo-default.png` had an image editor's transparency checkerboard **flattened into it
as real pixels** — every pixel fully opaque, 86% of them the alternating light greys that are only
meant to *represent* transparency. On the landing page that rendered as a grey checked slab behind
the wordmark.

The background is now genuinely transparent, the artwork is byte-for-byte identical, and the file is
67% smaller.

## New defaults, and a mark you can actually see

- **A new default logo and icon**, both properly transparent.
- **The brand mark is bigger and consistent.** It was 32 px in the app chrome and 30 px in the admin
  sidebar — of which padding and a border left only ~24 px of actual mark — so it was hard to make
  out *and* visibly different between the two pages. Both now render the icon on its own at
  **63 px**, with no tile, border or padding.
- **The Branding page tells you what size to upload**: a recommended size per asset, where and how
  large it is actually drawn, and the dimensions and weight of the file currently in use — with a
  warning when it is far bigger than it needs to be.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build      # required: the Dockerfile changed
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

Without `--build` you keep the old image, and the upload-size fix and image resizing will not be
active — uploads stay capped at 2 MB and large ones still 504.

There are no migrations specific to this release and no configuration to change.
