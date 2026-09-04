# v1.23.14 — One compose file, and an upgrade that can change it

The last release moved the bundled proxy off an nginx carrying 18 open advisories — and could
not deliver it. This release fixes the reason why, and three bugs that were hiding behind the
same arrangement.

> **🛑 Copy `docker-compose.yml` aside BEFORE you pull — the pull overwrites it without warning.**
> Git refuses to clobber untracked files, but yours is *ignored*, and git treats ignored files as
> expendable. See **Upgrading**; if you have already pulled, nothing is lost while your containers
> are still running, and there is a one-command recovery.

---

## Why this release exists

`docker-compose.yml` was gitignored. That was reasonable on the face of it: the file held your
ports, bind addresses and database passwords. The tracked copy was `docker-compose.yml.example`,
which an install copies **once**, at setup, and never looks at again.

The consequence only became obvious last release. v1.23.13 moved the proxy from `nginx:1.27-alpine`
— 16 months old, on a branch that had stopped receiving fixes, inside the vulnerable range of
**18 advisories** — to 1.30.4, which clears all of them. And the only thing the release could do
was *ask each operator to edit their own file by hand*.

That is not a delivery mechanism. It is the same shape of problem v1.23.12 fixed for PHP
dependencies: a change that ships to GitHub and reaches nobody.

---

## Changed

### `docker-compose.yml` is tracked, and holds nothing that is yours

Everything install-specific now comes from `.env`, which Compose reads automatically and which
stays untracked:

| Variable | Default | What it was |
| --- | --- | --- |
| `TLS_PORT` | `7979` | hardcoded `7979:7979` |
| `HTTP_BIND` | `127.0.0.1` | hardcoded in the ports list |
| `HTTP_PORT` | `8080` | hardcoded in the ports list |
| `DB_LOCAL_BIND` / `DB_LOCAL_PORT` | `127.0.0.1` / `33060` | hardcoded |
| `DB_DATABASE` / `DB_USERNAME` | `tunarr` / `tunarr` | kept in step by hand, in two files |
| `DB_PASSWORD` / `DB_ROOT_PASSWORD` | *required* | kept in step by hand, in two files |

For anything else, create a **`docker-compose.override.yml`**. Compose merges it on top
automatically and it is never committed, so you never have to edit the tracked file and collide
with the next `git pull`. `docker-compose.yml.example` is gone — two sources of truth is precisely
what caused this.

**A missing `DB_PASSWORD` or `DB_ROOT_PASSWORD` now stops Compose**, naming the variable, instead
of quietly initialising a database with a blank password or starting an app that cannot log in to
the volume it already has.

---

## Fixed

### A fresh install could not start its database at all

`docker-compose.yml.example` referenced `${DB_ROOT_PASSWORD}`, and `setup.sh` never wrote it. So
`MYSQL_ROOT_PASSWORD` interpolated to an empty string and the MySQL image refused to start:

```
[ERROR] [Entrypoint]: Database is uninitialized and password option is not specified
    You need to specify one of the following as an environment variable:
    - MYSQL_ROOT_PASSWORD
```

Anyone following Quick start hit this at step 5. `setup.sh` generates one now.

### The HTTPS port you are asked for is now the port that gets published

`setup.sh` asks for an HTTPS port and used it only to build `APP_URL`, while the compose file
hardcoded `7979:7979`. Answering anything other than 7979 produced an install whose own URL
pointed at a port nothing was listening on.

### Database passwords are generated, not the word `secret`

They had to be hardcoded so the two files matched. Now that the compose file reads them from
`.env`, `setup.sh` generates 32 characters for each. Re-running `setup.sh --force` carries the
existing passwords over rather than generating new ones — a database volume keeps whatever it was
initialised with, and new values would just lock the app out.

---

## Upgrading

**Copy your compose file aside first.** Git refuses to clobber an *untracked* file — but yours is
*ignored*, because your `.gitignore` lists it, and git treats ignored files as expendable. The pull
replaces it silently, taking your ports, bind addresses and database passwords with it. There is no
prompt and no error.

```bash
cd Guidearr

cp docker-compose.yml docker-compose.yml.backup   # do this FIRST
git pull

./docker/migrate-compose.sh docker-compose.yml.backup   # copies your values into .env
docker compose config                                   # check BEFORE starting anything
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

### Already pulled without a copy?

Nothing is lost while your containers are still up. They were created from that file and still
carry its values, so they are a faithful record of it:

```bash
./docker/migrate-compose.sh --from-running
docker compose config
docker compose up -d --build
```

Do this **before** recreating anything. `docker compose` will refuse to run at all until `.env` is
complete — that is the missing-variable guard doing its job, not a broken install — and your
containers keep serving normally in the meantime.

`migrate-compose.sh` reads your old file and writes the ports, binds and credentials into `.env`.
It prints every change it makes without printing the secrets themselves, keeps a timestamped backup
of `.env`, and is safe to run twice.

`docker compose config` prints the fully resolved stack and fails loudly if anything required is
missing. **Compare its published ports against your backup before starting** — that one command is
what turns this into a safe upgrade.

Keep the backup until you are satisfied. To roll back, restore it over the tracked file — Compose
uses whatever is on disk.

**No database migration is required.** `--build` remains non-optional as of v1.23.12: it is what
installs the PHP dependencies and compiles the frontend.

---

## After this

Changes to the stack can finally be shipped. A future proxy bump, a new service or a corrected
default arrives with `git pull` like everything else, instead of being a paragraph in a release
note asking you to go and edit a file.
