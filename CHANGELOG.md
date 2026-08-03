# Changelog

All notable changes to **Guidearr** since v1.18. Newest first.

> **Tagged public releases:** v1.20.0, v1.22.3, v1.22.5, v1.22.6, v1.22.7, v1.22.8, v1.22.9, v1.22.10, v1.22.11, v1.22.12, v1.22.13, v1.22.14, v1.23.0, v1.23.1, v1.23.2, v1.23.3, v1.23.4, v1.23.5, v1.23.6, v1.23.7, v1.23.8 and v1.23.9.
> Intermediate entries (1.21.0–1.22.2, 1.22.4) were development iterations rolled into the next tagged release.

---

## v1.23.9 — Assets built into the image, and a feed pfBlockerNG can read · 2026-08-03

**Changed**
- **The threat feed is now bare addresses only** — no header, no `#` comments, one IP per line.
  pfBlockerNG's parser strips comments, but plenty of tools that read a URL list do not, and a
  stray comment is the difference between a working source and a silently empty one. The criteria,
  entry count and last-built time are on the **Admin → Config** page, where they can actually be
  read. An empty feed is now an empty document rather than a lone header.
- **Frontend assets are now built into the image.** They were compiled by hand and committed
  nowhere — `public/build` is gitignored, and neither the `Dockerfile` nor the documented upgrade
  path ran `npm run build`. An upgraded install therefore kept whatever stylesheet it already had,
  so any newly added CSS class silently did nothing. The image now compiles them during the build
  and publishes them into `public/build` when a container starts, which is what makes
  `docker compose up -d --build` refresh them. Node is not needed on the host, and there is no
  extra step to run.
- **The threat feed URL now ends in `.txt`.** pfBlockerNG works out a list's format from the URL
  and rejects one without a file extension. The address shown in **Admin → Config** carries the
  extension; the route ignores it, so the bare form still resolves and any install already
  configured against the old URL keeps working.

**Fixed**
- **The admin panel's wordmark no longer looks bolder than the dashboard's.** It was weight 800
  with tight tracking in pure white, beside a dashboard rendering the same name at weight 500. Both
  now match.
- **The brand mark was clipped top and bottom on the dashboard.** Flux wraps the logo in a 24 px
  box with `overflow-hidden`, inside a fixed-height row, so the larger mark was cut off — while the
  admin sidebar, which has no fixed row height, showed it whole. Both now render it the same way.

---

## v1.23.8 — Branding overhaul, and uploads that no longer time out · 2026-08-03

**Added**
- **Brand images are now resized on upload.** The app image ships with GD, and an uploaded icon or
  logo is capped at the largest size it is ever displayed (icon 512 px, logo 1200 px on the longest
  edge). Aspect ratio and transparency are preserved, files already within the cap are stored
  byte-for-byte untouched, and nothing is ever enlarged — so a full-resolution export no longer
  becomes a multi-megabyte download for every visitor.
  - Animated GIFs are left alone; GD would keep only the first frame.
  - If the resize can't run — no GD, an unreadable file, or an image too large to decode within the
    memory limit — the upload is kept exactly as it arrived rather than lost.
- **The Branding page tells you what size to upload.** Each asset shows a **recommended size**
  (icon **256 × 256**, logo **600 × 300**), says where and how large it is actually drawn, and
  reports the **dimensions and weight of the file currently in use** — with a warning when it is far
  bigger than it needs to be.

**Changed**
- **New default logo and icon**, replacing the placeholders — both properly transparent (logo 88%,
  icon 70%), at 1200 × 655 / 186 KB and 512 × 510 / 264 KB.
- **The brand mark is bigger, and the same everywhere.** It sat at 32 px in the app chrome and
  30 px in the admin sidebar — of which padding and a border left only ~24 px of actual mark — so it
  was hard to make out and visibly different between the two pages. Both now render the icon on its
  own at **63 px**: no tile, no border, no padding, so what you see is the mark rather than a box
  around it. The standalone legal/licence/docs headers moved 30 px → 40 px to match each other.

**Fixed**
- **Uploading a logo could hang and end in a 504.** The official PHP image ships **no `php.ini` at
  all**, so PHP ran on its compiled-in defaults: **2 MB** for a file and **8 MB** for a whole
  request, while nginx accepted 20 MB and the app's own validation advertised 10 MB. A body over
  8 MB made PHP abort without reading it, nginx sat waiting on the dead upstream, and the browser
  showed **504 Gateway Time-out after five minutes**. Between 2 MB and 8 MB the file was silently
  discarded instead, so the upload simply bounced back. Guidearr now ships a `php.ini` with the
  layers in agreement — nginx 20 MB ≥ post 16 MB ≥ upload 12 MB ≥ the app's 10 MB rule — so an
  oversized file gets a clear validation message rather than a timeout. `memory_limit` is raised to
  256 MB so the new upload resizing can run on a full-sized image.
- **The default logo showed a grey checkerboard on the landing page.** The shipped
  `logo-default.png` had an image editor's transparency checkerboard **flattened into it as real
  pixels** — every pixel fully opaque, 86% of them the alternating light greys that are supposed to
  *represent* transparency. The background is now genuinely transparent; the artwork is
  byte-for-byte identical (15,276 mark pixels before and after) and the file is 67% smaller. The
  unused duplicate `default.png` carried the same fault and was replaced too.
- **Brand assets are no longer re-downloaded on every page view.** They are served with `no-cache`
  so a fresh upload appears immediately, but nothing compared the validator — a browser's
  conditional request was answered with the whole image every time. They now carry an **ETag** and
  honour `If-None-Match` / `If-Modified-Since`, so revalidating costs a **304 with an empty body**
  instead of the full file. Uploads still appear instantly. On one install these two files accounted
  for **792 MB in six days**, without a single 304.

**Upgrading**
- This release changes the `Dockerfile` (it adds GD and a `php.ini`), so the usual
  `docker compose up -d --build` is required — a plain `git pull` is not enough for the upload
  fixes to take effect.

---

## v1.23.7 — Security hardening: mail relay, threat feed, auth rate limits · 2026-08-03

**Added**
- **Rate limits on the public auth endpoints.** Registration, password-reset requests and reset
  submissions had **no limit at all** — the sign-up form took 21 attempts in 60 seconds from a
  single host, and only the (optional) CAPTCHA stopped them. Sign-up is now capped at **5/minute
  and 15/hour** per address, reset submissions at **10/minute**, and reset-link requests at
  **5/minute** per address plus **5/hour per target account** — the last one matters because that
  endpoint mails an address the *requester* supplies, so leaving it open was a way to flood
  somebody else's inbox from your server.
- **Sign-in is now limited per address, not just per account.** The existing limit was keyed on
  email + address, which slows guessing at one person's password but does nothing about a host
  trying one common password against *many* accounts — every new address got its own budget.
  A **20/minute per address** limit runs alongside the original **5/minute per account**.
  Mistyping your own password a few times is unaffected.
- **Social sign-up is capped too.** Signing up with Google or Facebook is the one registration
  path with **no CAPTCHA** — a redirect back from the provider has no form for a human to solve a
  challenge in — and it had no limit either. One address may now auto-provision **10 new accounts
  per hour**, and the callback itself is capped at 30/minute against hammering. **Signing in to an
  existing account is never counted**, so returning users are unaffected no matter how many people
  share an office or carrier address.
- Every limit is tunable via `AUTH_LIMIT_*` in `.env` — raise them if your users share one
  corporate NAT address.
- **A threat feed your firewall can block from.** Guidearr can publish a plain-text list of the IP
  addresses caught probing it — the scanners constantly asking for `/.env`, `/wp-login.php`,
  `/.ssh/id_rsa` and the like — for **pfBlockerNG** (or anything that polls a URL list) to consume
  as a custom IPv4/IPv6 source. Switch it on under **Admin → Configuration → Threat feed**, which
  also shows the URL to copy and lets you set **how many attacks list an address** (default 20).
  - **The URL is secret and created for you.** There is nothing to run after an install or an
    upgrade: the address is generated on first view, the list builds itself on the first fetch,
    and it refreshes hourly from then on. You can replace the URL segment with your own; a wrong
    one returns 404, so the endpoint can't be found by guessing.
  - **It will not list your users.** Any host that has successfully pulled a playlist is excluded
    outright — a customer's player can share an address with a scanner, and cutting it off would
    stop their service. Private and reserved addresses are never listed either, so the reverse
    proxy and health checks can't be blocked by accident.
  - Only requests the app answered with a refusal count, matched against the exact status they
    returned, so an ordinary broken link is never mistaken for an attack.
  - **Nothing is banned permanently.** The list is rebuilt from scratch each time — no address is
    ever stored — so an address that stops probing drops off on the next rebuild and the list
    cannot grow without bound. The window is **14 days**, though log retention is usually the
    real limit: the access log is trimmed by size, so a busy install ages addresses out in days.
    Your firewall removes its rule on the next refresh of the list.

**Changed**
- **No mail server is bundled any more.** The `mailpit` service has been removed from
  `docker-compose.yml.example`. It published an **unauthenticated** web inbox on port `8025`,
  bound to every interface — so anyone who could reach the host could read every message the app
  sent, including **password-reset and email-verification links**. Guidearr now relays through
  **your** SMTP server instead. `setup.sh` asks for the relay host, port, and (optional)
  credentials, picking `smtps` automatically for port 465. Leave the host blank and mail is
  written to the Laravel log rather than delivered — you can fill it in later under
  **Admin → Environment**.
- **MySQL is bound to loopback.** `docker-compose.yml.example` published `33060:3306` on
  `0.0.0.0`, putting the database on the LAN of every install that followed it. It is now
  `127.0.0.1:33060:3306`. The app is unaffected — it reaches MySQL over the Compose network
  (`DB_HOST=db`); the published port only ever served local tooling. Use an SSH tunnel for
  remote access.

> **Upgrading:** these are changes to the *example* compose file — your own `docker-compose.yml`
> is not touched. To apply them, change the `db` port mapping to `127.0.0.1:33060:3306`, delete
> the `mailpit` service, point `MAIL_*` at a real relay (or set `MAIL_MAILER=log`), then
> `docker compose up -d db && docker compose rm -sf mailpit`.

---

## v1.23.6 — Ban list, maintenance controls, and admin tooling · 2026-07-27

**Added**
- **A real ban list.** Admins can ban an *email address*, not just disable an account. Bans live in
  their own list (with a reason and who set them), so they **survive account deletion** and **block
  re-registration and sign-in** with that address — enforced on the sign-in pipeline, registration,
  social login and the admin login. Manage it under **Users → Ban list**; the Users-page ban control
  is now a toggle switch kept in sync with the list, and deleting a user can add their email in one step.
- **On-demand maintenance controls.** The admin **Maintenance** page can now run the housekeeping jobs
  (health check, vacuum, log trim, purge, cold-provider reaper, stuck-job reclaim) on demand. They run
  in the **background** with progress **streamed live into a popup**, so a slow job like vacuuming the
  feed stores no longer ties up the request or times out (no more 504). The account-deleting /
  playlist-editing jobs (prune-unverified, prune-missing) offer a **dry run first** — you see exactly
  what would change, then click **Apply for real**.
- **A dedicated maintenance log.** Every maintenance run — manual or scheduled — is recorded in its own
  `maintenance.log` (kept 30 days) that appears under **Logs**, separate from the worker log.
- **Feeds Job Queue tools.** Each row now shows the owner's user number, the next scheduled refresh time,
  and a clear **COLD / DISABLED** badge (with the row dimmed) for providers the reaper has parked —
  instead of a stale "done". New per-row actions: **Run** (refresh now, re-enabling a cold provider),
  **Log** (view that provider's recent run log), Edit and Delete.

**Changed**
- The admin **Users** and **Feeds → users** tables gained a playlist count, a sign-in-method icon
  (Google / Facebook / password) and a **last-touch** column — updated even by an m3u/xtream download,
  not just a login — so it's easy to spot who's still active. Deleting a user is now a proper dialog
  (with an optional "also ban") that **refuses to delete your own account or the last admin**, and warns
  explicitly before deleting any admin.

**Fixed**
- **Adding an Xtream provider whose server reports a long timezone name** (e.g. `Africa/Casablanca`)
  no longer fails with a 500. The `timeshift` field was too small for many real zone names; it has been
  widened and the value is now capped defensively.

---

## v1.23.5 — CAPTCHA on the sign-in page · 2026-07-24

**Changed**
- **The main sign-in page now uses a Cloudflare Turnstile CAPTCHA**, matching the admin login and
  the sign-up page, which already had one. The public `/login` form was the last auth page without
  it, and the access logs showed automated password-guessing hitting exactly that form — the CAPTCHA
  now stops those bots before any password is checked, on top of the existing rate limit. Real users
  see the usual near-invisible Turnstile widget; nothing changes if you sign in normally.

**Note**
- The CAPTCHA only activates when Turnstile keys are configured, so unconfigured installs and the
  automated test suite are unaffected.

---

## v1.23.4 — Browsing counts as activity · 2026-07-20

**Changed**
- **Simply using the dashboard now keeps your providers active.** The inactivity reaper introduced
  in v1.23.0 only counted playlist *serves* and *edits*, so a user who logged in and looked around
  without changing anything still drifted toward being disabled after 14 days. **Viewing now counts
  too** — opening a playlist, opening a provider, or just loading the Playlists or Providers list
  marks everything it shows as active. Viewing a provider that was already disabled for inactivity
  **re-enables it on the spot**; providers disabled by repeated fetch failures or by an admin are
  still left alone.
- **A provider with no playlist attached now behaves predictably.** It has no serve traffic to keep
  it warm, so if nobody views or edits it for **14 days** it is disabled and stops being refreshed —
  no more nightly downloads for a feed nobody is using. **Nothing is deleted**: its channel data,
  settings and any playlists are kept, and opening it (or the Providers list) turns it straight back
  on.
- **"Last activity" now means real user activity only.** Background work — the refresh worker, the
  scheduler, and the URL/credential migrators added in v1.23.2–v1.23.3 — no longer marks a provider
  as recently used. Previously an automatic maintenance task could make an abandoned provider look
  active, so it was never reaped. The Maintenance page's activity columns are correspondingly more
  honest.

**Note**
- Activity is recorded at most **once per hour per item**, so a dashboard left open doesn't
  generate constant database writes.
- Playlists themselves are never disabled by inactivity — only providers stop refreshing, so your
  playlist URLs keep serving throughout.

---

## v1.23.3 — Change your M3U URL without breaking your playlists · 2026-07-20

**Added**
- **Editing an M3U provider's URL is now safe** — the same protection Xtream credentials got in
  v1.23.2. Previously, pointing a provider at a new M3U link re-imported every channel under a new
  internal ID, which **orphaned every playlist** that referenced them (channels showed as
  *"(missing channel)"*). Now, saving a new M3U URL:
  - **verifies the link is a real M3U**, then downloads it;
  - **matches it against your current channels** by `tvg-id` (falling back to name + group), so it
    still recognises the same provider even when it rotates the stream URL of every channel (e.g. a
    renewed subscription link);
  - only if **at least 70%** still match, **rewrites the matched channel URLs in place** — each
    channel keeps its ID, so attached playlists keep working (order, groups and enabled/disabled
    selections preserved) — and then imports the rest of the list normally;
  - **aborts with no changes** if too few match, leaving the URL and your playlists untouched.

  Progress is shown live in the update window. The match threshold is configurable via
  `M3U_URL_MATCH_THRESHOLD`.
- **Changing a Guide XML (XMLTV) URL now refreshes automatically.** It's verified as a real XML
  guide and then re-downloaded straight away, instead of waiting for the next scheduled run. Guide
  data has no playlist pointers, so nothing else is affected.
- **A provider's Type can no longer be changed once it exists** (previously only locked for Xtream).
  Switching type would strand the provider's channel/guide store.

**Fixed**
- **A freshly seeded playlist's channel order now follows its group order.** Groups were laid out in
  the provider's group order while the flat channel list was ordered alphabetically by group title,
  so the two disagreed — the group pane could start with *"WORLD CUP"* while the channel list started
  with *"ARABIC"*. Channels now seed in group-pane order (channels in an orphan group sort last, by
  ID within a group), and newly appended groups on refresh inherit the same ordering.

---

## v1.23.2 — Change Xtream credentials without breaking your playlists · 2026-07-17

**Added**
- **Editing an Xtream provider's URL, username, or password is now safe.** Previously, changing the
  credentials rewrote every channel's stream URL — which gave each channel a new internal ID and
  **orphaned every playlist** that pointed at it (channels showed as *"(missing channel)"*). Now,
  saving a credential change on an Xtream provider:
  - **validates the new login** before touching anything;
  - **downloads the channel list** with the new credentials and compares it to your current channels;
  - only if **at least 70%** still match, **rewrites the stored URLs in place** — each channel keeps
    its ID, so every attached playlist keeps working automatically (same order, same groups, same
    enabled/disabled selections, no re-import);
  - **aborts with no changes** if the login fails or too few channels match — the provider is left
    exactly as it was.

  A **progress window** shows each step live. Providers left with **duplicate channels** by older
  versions are consolidated automatically as part of the change. The provider **Type** is now locked
  once an Xtream provider exists, so it can't be switched to an incompatible type by accident. The
  match threshold defaults to 70% and is configurable via `XTREAM_CREDENTIAL_MATCH_THRESHOLD`.

---

## v1.23.1 — Last login shows a registration-date fallback · 2026-07-16

**Fixed**
- **The admin Users "Last login" column no longer reads "never" for everyone.** Because login
  tracking began in v1.23.0, existing accounts had no recorded sign-in. Rather than a bare *never*,
  the column now falls back to the account's **registration date** — shown italic/muted with a
  *"No sign-in recorded yet"* tooltip so it's clearly a fallback, not a fabricated login. Sorting
  falls back the same way, so never-signed-in users order by when they joined. Once a real sign-in is
  recorded, the actual timestamp is shown and sorted normally. Display-only; no migration.

---

## v1.23.0 — Admin table upgrades & self-maintaining feeds · 2026-07-16

**Added**
- **Richer admin Users table.** New **Registered** and **Last login** columns, plus a
  **currently-logged-in** dot (derived live from active sessions). Every column is sortable, you can
  filter by name/email/status (including *online only*), and the list paginates **25 at a time**. A
  new `last_login_at` is recorded on each successful sign-in.
- **Paginated feed tables.** The admin **Feeds** job queue now paginates at **25/page**, and the
  Users list on that page became a sortable grid with a **user ID** column, also 25/page.
- **Provider ownership at a glance.** The Maintenance page's provider-activity table now shows each
  provider's **owner name and user ID**.
- **`providers:reap-cold` — stop refreshing feeds nobody watches.** A daily job disables (never
  deletes) any provider with no playlist access or dashboard activity for **14 days**. All data is
  kept, and the provider **re-enables itself automatically** the next time one of its playlists is
  served or edited. Providers disabled by repeated fetch failures or by an admin are left alone.
  Preview with `php artisan providers:reap-cold --dry-run`.
- **`users:prune-unverified` — tidy up abandoned signups.** A daily job deletes accounts that never
  verified their email within **14 days** of registering. **Admins are always protected**, and
  manually created accounts (verified on creation) are never affected. Preview with
  `php artisan users:prune-unverified --dry-run`.

**Changed**
- **Editing a playlist now keeps its providers "warm."** Previously only *serving* a playlist
  marked its providers as recently used; now editing one in the dashboard does too, so actively
  curated playlists are never mistaken for cold by the new reaper.

**Upgrade note**
- After `git pull`: run `php artisan migrate` (adds `last_login_at`), then recreate the scheduler
  container so the two new daily jobs register (`docker compose up -d --force-recreate scheduler`).
  No frontend rebuild is required. Preview both new commands with `--dry-run` before relying on the
  daily schedule.

---

## v1.22.14 — "(missing channel)" rows are pruned from playlists · 2026-07-15

**Fixed**
- **"(missing channel)" rows with a blank URL no longer pile up in the editor.** A playlist row is a
  pointer `(provider_id, channel_id)` into a provider store, and provider channels are keyed on their
  **URL**. When a provider drops a channel — or simply rotates its URL — the old row is swept after
  more than three missed refreshes, and the playlist's pointer to it is orphaned. Those orphans
  rendered as **"(missing channel)"** with no URL. They never actually served anything (serve already
  skips them), but because playlist sync was purely **additive** they accumulated forever. Provider
  refreshes now remove them as well as add new channels.

**Added**
- **`playlists:prune-missing` maintenance command.** `php artisan playlists:prune-missing [ids…]
  [--dry-run]` clears orphaned pointers from all (or the named) playlists; `--dry-run` reports the
  count per playlist without writing. Use it to clean up orphans that accumulated before this release
  rather than waiting for each provider's next refresh.

**Changed**
- **Provider refresh sync now removes as well as adds.** `FeedWork`'s post-refresh playlist sync
  reconciles each attached playlist against the provider store, so orphaned pointers are dropped on
  the same pass that inserts new channels. Your ordering, renames, group flags and deletions are still
  preserved, and nothing is duplicated or resurrected. This is a deliberate move away from the
  strictly-additive sync introduced in v1.22.13.

**Safety**
- Both the command and the refresh sync **skip any provider whose store is missing or currently
  empty** (mid-import, or a failed fetch). Without that guard a provider that momentarily returned
  zero channels would look like "every channel is gone" and blank every playlist attached to it.
  Pruning keys off the *resolved missing* state — a pointer whose channel is absent from the provider
  store — never the raw empty-`url` column.

**Internal**
- `PlaylistStore`: extracted `deadPointerIds()`, added `missingPointerCount()` (dry-run) and
  `pointerProviderIds()`; the previously-unused `reconcileProvider()` now routes through the shared
  helper.

**Upgrade note**
- After `git pull`, rebuild and recreate the worker so the daemon picks up the reconcile —
  `docker compose build worker && docker compose up -d worker`. Until the worker restarts the old
  additive-only loop keeps running. The reconcile then applies on each provider's next refresh; to
  clear existing orphans immediately, run `docker compose exec app php artisan playlists:prune-missing`
  (add `--dry-run` first to preview).

---

## v1.22.13 — Playlists stay in sync with their providers · 2026-07-12

**Added**
- **Provider refreshes now flow into your playlists.** After a provider finishes refreshing, any
  **new** channels it gained are inserted into every playlist built from it — each new channel joins
  the **end of its group's block**, and a brand-new group (with its channels) is appended at the
  **end** of the list. The update is purely additive: your existing order, renames, group flags and
  deletions are all preserved, and nothing is duplicated. Previously, new provider channels never
  reached existing playlists at all.
- **`playlists:backfill` maintenance command.** `php artisan playlists:backfill [ids…] [--dry-run]`
  additively re-seeds playlists from their attached providers, adding any channels that were never
  seeded. `--dry-run` reports the gap per playlist without writing. Safe and idempotent (it relies on
  the `(provider_id, channel_id)` unique index) — it never duplicates, never resurrects a channel you
  deleted, and preserves ordering.

**Fixed**
- **Blank playlists from the "created too soon" race.** Creating a playlist from a provider that was
  still importing (or that had no channels yet) captured **0** channels and, because a playlist is
  never auto-rebuilt from an existing store, served empty forever. Creating a playlist from a provider
  that is still updating or empty is now blocked — both server-side and in the create dialog, where
  not-ready providers are shown as *updating…* / *no channels yet* and can't be selected. Manual
  playlists (no provider) are unaffected.

**Changed**
- **Confirmation when creating a manual playlist.** Pressing **Create** with no provider selected now
  asks you to confirm, in case you meant to pick a provider first.

**Internal**
- New `Provider::playlists()` relation and `PlaylistStore::insertNewFromProvider()`; five new tests
  covering the create-time guard, the refresh insert (into-group + new-group-at-end), and the
  additive backfill.

**Upgrade note**
- After `git pull`, rebuild and recreate the worker so the daemon picks up the refresh-sync:
  `docker compose build worker && docker compose up -d worker`. The new sync applies on each
  provider's next refresh; to reconcile existing playlists immediately, run
  `docker compose exec app php artisan playlists:backfill` (add `--dry-run` first to preview).

---

## v1.22.12 — Worker activity in the admin Logs page · 2026-07-06

**Added**
- **`worker.log` on the admin Logs page.** The background feed worker is a separate daemon whose
  output previously only reached the container's stdout (`docker logs`). It now writes lifecycle and
  one-line activity to a dedicated `worker` log channel (`storage/logs/worker.log`), which the Logs
  page lists automatically — so you can watch the worker from the admin without shell access. It
  records the supervisor start/stop, each spawn/scale decision (backlog, running, limit → workers
  started), per-job claim + `done in Ns` summaries, and failures (with the disable-after-N-errors
  event). Full per-provider detail still lives in the `feed_logs` table (**Admin → Feeds**), and
  worker exceptions still land in `laravel.log`. The file is clearable from the UI and included in
  the downloadable log bundle.

**Upgrade note**
- After `git pull`, restart the worker (`docker compose restart worker`) so the supervisor reloads
  and starts writing its lifecycle lines; per-job lines from worker children appear immediately.

---

## v1.22.11 — Parallel feed workers (Worker Limit) · 2026-07-06

**Added**
- **Configurable Worker Limit** (**Admin → Config → Worker limit**). Run more than one provider
  refresh at a time: a new `feed:supervise` process keeps up to *N* `feed:work` children busy while
  providers are queued and scales the pool back to zero when idle. Default **1** matches the classic
  single-worker behavior; the setting is stored in the settings JSON store and takes effect within a
  few seconds — no restart. Best for parallelising **many** providers refreshing at once (e.g. the
  daily refresh hour); a single large feed is still one job on one worker.

**Internal**
- Feed queue claims already used `SELECT … FOR UPDATE SKIP LOCKED`, so multiple workers never claim
  the same job and each provider writes only its own store. The supervisor owns the liveness
  heartbeat and orphan reclaim, and shuts down gracefully on `SIGTERM` — a child cut off mid-job is
  reset to `queued` and retried, so no work is lost.
- CI: bumped the pinned commit SHAs for the `actions/checkout` and `shivammathur/setup-php` GitHub
  Actions (Dependabot).

**Upgrade note**
- The `worker` container command changed to `php artisan feed:supervise`. After `git pull`, recreate
  it (`docker compose up -d worker`) so it picks up the new command. Cloners get it from the updated
  `docker-compose.yml.example`.

---

## v1.22.10 — Interactive admin recovery & social-account badges · 2026-07-06

**Changed**
- **Admin recovery is now interactive.** `php artisan admin:password` prompts for the email and a
  hidden password (nothing lands on the command line or in shell history), then **creates the admin
  if none exists** (e.g. it was deleted) or **resets** the matching account — re-activating and
  email-verifying it. This replaces the old `admin:sync` command together with the `ADMIN_EMAIL` /
  `ADMIN_PASSWORD` `.env` variables, which are **removed**.

**Security**
- The admin password is no longer kept in plain text in `.env`. Existing installs can delete the
  now-unused `ADMIN_EMAIL` / `ADMIN_PASSWORD` lines — the Environment page no longer lists them, and
  nothing reads them.

**Added**
- **Social-account badges in Admin → Users.** A small **G** (Google) or **F** (Facebook) badge next
  to a user's role marks a linked social identity, so social sign-ins are distinguishable at a glance.

**Fixed**
- The **Continue with Google / Facebook** button text is now white, so it stays readable on the dark
  sign-in and registration pages.

---

## v1.22.9 — Social sign-in (Google & Facebook) · 2026-07-06

**Added**
- **Sign in with Google and Facebook.** Optional OAuth social login (Laravel Socialite) —
  "Continue with Google / Facebook" on the login and registration pages. Accounts are found,
  created, or linked by the provider's verified email; new social users are created already
  email-verified. Requires `php artisan migrate` (adds `social_accounts`, makes `password` nullable).
- **Admin → Social** — configure it without touching `.env`: a per-provider **Enable** toggle,
  Client ID / Secret / Redirect inputs, and on-page setup instructions with the exact callback,
  data-deletion, and privacy URLs to register. Secrets are **encrypted at rest** in the settings
  store and injected into `config('services.*')` at boot.
- **Settings → Connected accounts** — link or disconnect Google/Facebook, and **set a password**
  for social-only accounts so they can also sign in by email (and safely disconnect a provider).
- **Data deletion** — a Facebook data-deletion callback (`/data-deletion/facebook`, required by
  Meta) plus a human-readable, editable **`/data-deletion`** instructions page (Admin → Legal); the
  privacy policy links to it.

**Security**
- Social login re-applies the login guards `Auth::login()` would otherwise bypass: a non-active
  account can't sign in, and a 2FA-enabled account must use password + 2FA.

**Internal**
- The test harness now relocates storage and clears the settings store **before** the app boots, so
  tests can never read or write real production data.

---

## v1.22.8 — Legal pages, email verification & log tooling · 2026-07-06

**Added**
- **Editable legal pages.** Public `/privacy`, `/terms` and `/cookies`, rendered from Markdown and
  editable in **Admin → Legal** (stored in the settings JSON store, with shipped defaults and a
  per-doc "reset to default"). Footer links on the landing and sign-in/registration pages. The
  default privacy policy includes a Google/Facebook sign-in section for the planned social-login
  release. Raw HTML in the source is stripped on render.
- **Email verification by code.** Sign-up email verification now uses a six-digit code (with a
  resend cooldown) instead of a link, plus an admin **Send test email** action on the Environment
  page to check SMTP settings before saving.

**Fixed**
- The Environment page's **Send test email** button no longer disappears when `.env` has no
  `MAIL_PASSWORD` row yet — you configure mail there, so the button is always available.

**Changed**
- The downloadable **log bundle** now also includes rotated siblings (`nginx-access.log.1`,
  `nginx-error.log.2.gz`, …) within the retention window, so a support bundle keeps the full
  recent history even right after a rotation. The viewer already lists every live `*.log`
  (`laravel`, `nginx-access`, `nginx-error`).

---

## v1.22.7 — Playlist reordering fixes & self-healing stores · 2026-07-06

**Fixed**
- **Group reordering now moves the whole group.** `moveGroupToRow` relocates all of a group's
  channels together to the target spot (front of the list for row 1), so a group sent to the top
  actually leads the exported playlist. Replaces the old "largest contiguous run + anchor" logic
  that could leave a group's channels mid-list when the flat order had drifted from the group pane.
- **Manual channel moves are authoritative.** Moving a channel (single or bulk) places it exactly
  where dropped — anywhere in the list, across group boundaries — while keeping its own group
  label. The serve/editor order stays flat on `position_order`; group order never overrides it.

**Added**
- **Self-healing playlist stores.** `Playlist::ensureStoreSeeded()` rebuilds a playlist's channel
  store from its attached providers when the store file is missing (only when missing — an empty
  store may be a deliberate delete-all), wired into the public serve path and the editor. A warning
  is logged when a provider-backed playlist serves zero channels, so a lost/emptied store surfaces
  in **Admin → Logs**.

**Changed**
- Removed the internal hostname from docs, examples and the admin config placeholder,
  replacing it with the generic `guidearr.example.com` and neutral "docker host" wording
  (`README.md`, `build/README.md`, `health/README.md`, `health/heartbeat.sh`,
  `resources/views/admin/config.blade.php`). The live `docker/nginx.conf` already used
  `server_name _;`, so it needed no change.

**Internal**
- The test suite now isolates its storage to a temp dir (`tests/TestCase::setUp()` →
  `useStoragePath`), so running it can never touch real playlist/provider SQLite stores.

---

## v1.22.6 — Automatic nginx log rotation · 2026-06-07

**Added**
- The bundled web server now keeps its own logs bounded automatically: `docker/nginx-logrotate.sh`
  runs inside the `web` container (via the nginx image's `/docker-entrypoint.d/`) and trims
  `nginx-access.log` / `nginx-error.log` to a recent tail once they pass a size cap — checked
  daily, no host cron or logrotate to set up. It runs as root in nginx's container (the logs are
  root-owned) and relies on nginx's `O_APPEND` writes so the in-place trim stays clean.
  Tunable: `NGINX_LOG_MAX_BYTES` (15 MB), `NGINX_LOG_KEEP_BYTES` (5 MB), `NGINX_LOG_INTERVAL` (daily).
  `docker-compose.yml.example` mounts it on the `web` service.

---

## v1.22.5 — Distribution-ready web server & log tooling · 2026-06-07

**Added**
- The bundled nginx now writes its access/error logs into `storage/logs` as
  `nginx-access.log` / `nginx-error.log` (alongside container stdout/stderr), so they
  appear in the **Admin → Logs** tail viewer and in the downloadable log bundle — but
  only when you're running Guidearr's own web server.
- The admin **log bundle** is trimmed to the **last 5 days** of every log (timestamp-aware
  across Laravel, nginx-access and nginx-error formats); `diagnostics.txt` notes the window.

**Changed**
- `docker/nginx.conf` is now distribution-ready: trusts private-range proxies for
  `real_ip` (X-Forwarded-For) so logs and per-IP logic see the true client behind a
  reverse proxy; sends security headers (X-Frame-Options, X-Content-Type-Options,
  X-XSS-Protection, Referrer-Policy, Cache-Control); adds a query-string WAF;
  sets `server_tokens off`; and enlarges fastcgi buffers (clears the
  "buffered to a temporary file" warnings on large branding images).
- Admin **Clear** is disabled/guarded for `nginx-*` logs — nginx holds them open and
  they're rotated on the host, not truncated from the app.

---

## v1.22.4 — Admin: clear a log file · 2026-06-07

**Added**
- **Clear** button on Admin → Logs truncates the selected `storage/logs/*.log` in place
  (keeps the file so logging continues). Path-traversal-guarded, admin-only.

---

## v1.22.3 — Worker resilience & health monitoring · 2026-06-06

**Added**
- `php artisan health:check` — internal probe for DB connectivity, worker liveness,
  stuck queue jobs and refresh staleness. `--format=human|env|json`; exits 1 on any issue.
- `health/heartbeat.sh` — host cron (every 5 min) that runs the probe, checks container
  status and host CPU/memory/disk, **auto-restarts** wedged services, and **emails the
  admin** — throttled to one alert per issue per 4 h, with a single "recovered" note.
- Worker liveness heartbeat written to `storage/app/health/worker.beat` every poll.
- Config: `FEED_LOW_SPEED_LIMIT`/`FEED_LOW_SPEED_TIME`, `HEALTH_WORKER_STALE`,
  `HEALTH_REFRESH_MAX_AGE_HOURS`.

**Changed / Fixed**
- `feed:work` now wraps its loop in `try/catch` with backoff: a transient DB error is a
  logged retry instead of a crash-loop, so a momentary database outage no longer becomes a
  silent multi-hour stall. *(Root cause of the missed scheduled refreshes.)*
- cURL **stall-abort** on Xtream + M3U downloads: a dead upstream is dropped in ~60 s
  instead of holding a worker for the full 20-minute timeout cap.
- `docker-compose.yml`: `db` healthcheck + `depends_on: { db: { condition: service_healthy } }`
  on app/worker/scheduler so the daemons never start ahead of MySQL.
- README rewritten to document the feed/scheduler/worker subsystem, guide enhancement,
  health monitoring and resilient startup (`health/README.md` covers heartbeat setup).

---

## v1.22.2 — Enhance Guide: read fresh channel names · 2026-06-05

**Fixed**
- The guide enhancer now reads the **live channel-list name** (updated in real time),
  overriding the XMLTV `<display-name>` which lags a day — so current/upcoming events
  (e.g. ESPN+ channels) are picked up instead of stale ones.
- Event titles are cut at the human-date marker and have trailing stream IDs stripped
  (`… Johnson 1395` → `… Johnson`).

---

## v1.22.1 — Enhance Guide: keep filler for ended events · 2026-06-05

**Fixed**
- Channels whose only embedded event has already ended keep their `No EVENT Today`
  filler so they stay visible in the guide; only live/upcoming events replace filler.
  (Fixes the blank-guide regression from v1.22.0.) Default synthetic duration raised to 180 min.

---

## v1.22.0 — Enhance Guide: replace filler with real event · 2026-06-05

**Changed**
- Replaced `No EVENT Today` filler rows with the synthesized event for channels that had
  no real programmes. *(Regression: all-ended event channels went blank — fixed in v1.22.1.)*

---

## v1.21.1 — Enhance Guide: richer logs · 2026-06-05

**Changed**
- Feed import logs now report examined / added / enhanced counts.

---

## v1.21.0 — Enhance Guide · 2026-06-05

**Added**
- Per-provider **Enhance Guide** toggle (on by default). Synthesizes EPG programmes for
  event / PPV channels that encode the event in the channel name (parsed as US Eastern)
  but ship no guide of their own.

---

## v1.20.0 — Reverse-proxy aware · 2026-06-05  *(public release)*

**Added / Changed**
- App is reverse-proxy aware out of the box: nginx serves TLS on `:7979` **and** plain
  HTTP on `:8080`, with `TrustProxies` configured for an upstream proxy/HAProxy.
- Corrected the `docker-compose.yml` / `.env` examples.

**Fixed**
- Playlist editor UX: scroll-jump on reorder and the reindex loader-flash.

---

## v1.19.1 — Reorder migration guard · 2026-06-05

**Fixed**
- Made the flat-ordering migration idempotent (safe to re-run without re-numbering).

---

## v1.19.0 — Flat per-channel ordering · 2026-06-05

**Changed**
- Reworked playlist ordering to a single flat per-channel sequence with **group as an
  attribute** rather than the sort key, plus group-move support. Channels now keep a
  stable position independent of their group.

---

## v1.18.0 — Bulk channel move · 2026-06-05

**Added**
- Multi-row **bulk channel move** with shift-range selection in the playlist editor.
