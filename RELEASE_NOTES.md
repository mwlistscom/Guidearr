# Guidearr v1.23.6 — Ban list, maintenance controls, and admin tooling

This release is a big lift for the **admin panel**: a proper ban list, one-click maintenance jobs that
stream their progress, and a much more useful Feeds view — plus a fix for a provider bug that was
quietly 500-ing on some Xtream sign-ups.

## A real ban list

Until now, "banning" someone just meant setting their account to *banned* — which vanished the moment
the account was deleted, and did nothing to stop them signing up again. Now there's a proper
**ban list**, keyed on the email address:

- A ban **survives account deletion** and **blocks both re-registration and sign-in** with that address.
- It's enforced everywhere that matters: the main sign-in pipeline, registration, social (Google/Facebook)
  login, and the admin login.
- Manage it under **Users → Ban list** (with a reason and who set each ban). On the Users page the ban
  control is now a **toggle switch**, and when you delete a user you can **add their email to the ban list
  in the same step**.

Deleting a user is also safer: it's a clear confirmation dialog now, it **won't let you delete your own
account or the last admin**, and it warns you explicitly before removing any admin.

## Maintenance you can actually run

The admin **Maintenance** page gained a set of **Run now** controls for the background housekeeping jobs —
health check, vacuum, log trim, purge, the cold-provider reaper, and stuck-job reclaim:

- They run **in the background** and stream their output **live into a popup**, so a slow job (vacuuming
  large feed stores can take minutes) no longer freezes the request or trips a gateway timeout.
- The riskier jobs that **delete accounts or edit playlists** (prune-unverified, prune-missing) run a
  **dry run first** — you see exactly what *would* change, then click **Apply for real** to commit.

Every run, manual or scheduled, is now recorded in its own **`maintenance.log`** (kept 30 days) that shows
up under **Logs**, separate from the worker log.

## A sharper Feeds view

The **Job Queue** now tells you far more at a glance:

- The owner's **user number** and the provider's **next scheduled refresh** time.
- A clear **COLD / DISABLED** badge (with the row dimmed) for providers the inactivity reaper has parked —
  instead of a misleading "done".
- Per-row actions: **Run** (refresh now — which re-enables a cold provider), **Log** (that provider's
  recent run log), **Edit**, and **Delete**.

The **Users** and **Feeds → users** tables also gained a **playlist count**, a **sign-in-method icon**
(Google / Facebook / password), and a **last-touch** column — which updates whenever someone pulls their
m3u/xtream link, not just when they log in — so it's easy to see who's still active.

## Fixed

- **Adding an Xtream provider whose server reports a long timezone name** (for example
  `Africa/Casablanca`) no longer fails with a 500. The `timeshift` field was too small for many real
  timezone names; it's been widened and the incoming value is capped defensively.

## Upgrading

Pull the new version and apply migrations as usual (`php artisan migrate`) — this release adds the `bans`
table and widens `providers.timeshift`. Nothing needs configuring; the new admin features appear
automatically.
