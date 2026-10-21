# v1.23.24 — Account security, part two: social sign-in, sessions, secrets

> **Security release. Upgrade soon.** This closes the rest of the account-security issues
> found alongside v1.23.23. The most serious let someone who registered your email address
> before you did keep access to your account once you signed in with Google or Facebook.
>
> No rebuild and no configuration change is needed; `git pull` delivers all of it.

---

## Security

### Someone who registered your email first can no longer share your account

Anyone can create an account with an email address they don't own; they just can't verify it.
Until now, if the real owner later used **Continue with Google** (or Facebook) with that address,
Guidearr linked the sign-in to the unverified account and kept the password the other person
had chosen. From then on, both people could sign in to the same account.

Signing in with Google or Facebook to an unverified account now treats it as the address
owner's alone. Everything the unverified registrant set up is removed first: password,
two-factor, passkeys, connected Google/Facebook logins, the remember-me token and any open
sessions. If you want a password afterwards, use **Forgot password**.

Guidearr also refuses a Google sign-in when Google itself reports the address as unverified.

### Changing or resetting your password signs out your other sessions

Before, a password change left every other browser signed in, remember-me cookies included. So
changing a leaked password did nothing against someone who was already signed in with it. Now a
change or reset signs out every other session. The browser you change it from stays signed in.

### The admin Environment page no longer puts secrets in the page

`APP_KEY`, the database and mail passwords, the Turnstile secret and the other secret values
were sent to the browser inside masked fields. They were hidden on screen but readable in the
page source, by browser extensions, and in saved or cached copies of the page.

Secret fields now start empty, and saving one blank keeps its current value. The page labels
each one as *set* or *not set*. The trade-off: a secret can no longer be cleared to empty from
this page. Edit `.env` directly for that.

### Smaller fixes

- **"Forgot password" no longer reveals which addresses have an account.** It used to say
  "We can't find a user with that email address" for unknown addresses. Every request now gets
  the same reply.
- **A verification code stops working after 5 wrong guesses.** Request a new code to carry on.
  The usual wait between codes still applies.
- **Deleting a user now removes their playlist files too.** Before, only their provider data was
  cleaned up.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

No rebuild, no migration and no `.env` change. People already signed in stay signed in through
the upgrade.

If you ever ran `php artisan event:cache`, run `php artisan event:clear` or `event:cache` again.
Otherwise the new sign-in listener is not picked up, and a password reset can miss a session
that has been idle since it signed in. (`optimize:clear` above already does this.)
