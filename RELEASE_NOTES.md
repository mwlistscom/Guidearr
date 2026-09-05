# v1.23.19 — A guide panel that tells you when it fails

A small fix to the TV guide panels. Nothing to configure, and no change to how the guide works
when it works — only to what you see when it doesn't.

---

## Fixed

### A guide that failed to load sat on "Loading…" indefinitely

Opening the TV guide for a channel — from the editor, or from the programme list in the provider
grid — showed a spinner while it fetched. If that request failed at the network level rather than
returning an error, the panel simply stopped there. The spinner stayed up with no message, no
explanation, and no way to try again short of closing the panel and reopening it.

A restart of the containers while a guide panel was open was enough to cause it.

Both panels now say what went wrong and offer a **Retry**, since the usual cause is momentary.

### An error was reported as "No upcoming programmes."

The quieter half of the same problem, and the more misleading one.

When the server returned an error, the panel had nothing to read from it and fell through to the
same message it shows for a channel with no listings. So a genuine failure looked like a definite
answer: you were told the guide was empty, when in fact the request had not succeeded at all.

Errors now say they are errors, and include the status code. "No upcoming programmes." now means
exactly that — the channel has no listings.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

No migration, no configuration, nothing to undo.
