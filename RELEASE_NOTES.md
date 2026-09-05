# v1.23.20 — A provider that saves even when its server misbehaves

One fix, for a failure that looked like Guidearr rejecting perfectly good credentials.

---

## Fixed

### An Xtream server could stop its own provider being saved

When you add or edit an Xtream provider, Guidearr logs in to its `player_api` and keeps a couple
of details the server reports back — among them its timezone, used to line the guide up with your
local time.

That timezone was written into the database exactly as the server sent it, into a field sized for
a timezone. A server answering with something much longer than a timezone therefore broke the
save: the record could not be written, and you were shown a failure while your username and
password were perfectly fine. Nothing in the message pointed at the real cause, because the login
itself had succeeded.

It happened on the deployment this was found on, eight times in one day.

An unusable timezone is now simply discarded. The provider saves, the login result is unchanged,
and all that is lost is the guide-time offset — which is optional, and far cheaper to lose than
the provider itself.

Genuine timezones are untouched: the longest real one in existence is well within the limit, and
the limit is counted in characters rather than bytes, so a timezone written in a non-Latin script
is not thrown away for being "too long" when it is not.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

No migration, no configuration change.

If a provider of yours has been failing to save with a database error, it should save now. If one
saved *before* with an odd timezone, nothing about it changes — the next refresh simply re-reads
the value and discards it if it is still unusable.
