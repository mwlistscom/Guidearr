# v1.23.25 — Sign-up no longer reveals which emails have an account

> **Security release.** This closes the last item from the account-security review behind
> v1.23.23 and v1.23.24. It also fixes sign-up errors never being shown.
>
> One visible change: **creating an account no longer signs you in.** You enter the emailed code
> as before, then sign in. No rebuild and no configuration change is needed.

---

## Security

### The sign-up form no longer tells anyone which addresses are registered

The sign-up form answered "The email has already been taken", so anyone could find out whether
an address had an account here by trying it, one address at a time.

Now a sign-up with an address that already has an account looks exactly like a new one. It gets
the same reply and the same "check your email" screen, and the code screen behaves the same way.
Behind the scenes nothing is created, and the address owner gets an email instead: someone
tried to sign up with their address, and they can sign in or reset their password if it was
them. To keep the form from being used to flood someone's inbox, at most three of these emails
go to one address per hour.

### Why sign-up no longer signs you in

Hiding the message wasn't enough on its own. A real sign-up used to leave the browser signed in
and a refused one didn't, so the difference was still visible. Now nobody is signed in by
signing up. The code screen still works as before, and once your code is accepted you sign in
as usual. Before, the verification step signed you out at that point anyway, so the number of
steps is unchanged.

---

## Fixed

### A failed sign-up now says why

A sign-up rejected for any reason (a password that broke the rules, passwords that didn't match,
a failed CAPTCHA) was showing the "check your email" screen as though it had worked, and no code
ever arrived. The page now shows the actual error so it can be corrected.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose exec app php artisan optimize:clear
```

No rebuild, no migration and no `.env` change. Anyone part-way through signing up when you
upgrade can still finish with the code they were sent.
