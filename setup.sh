#!/usr/bin/env bash
#
# Guidearr — interactive .env generator.
# Creates a ready-to-use .env with freshly generated secrets. No example file
# is committed to the repo; run this once after cloning.
#
#   ./setup.sh            # interactive
#   ./setup.sh --force    # overwrite an existing .env (a backup is kept)
#
set -euo pipefail

ENV_FILE=".env"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

if [ -f "$ENV_FILE" ] && [ "$FORCE" -ne 1 ]; then
    echo "A .env already exists. Re-run with --force to overwrite (a timestamped backup is kept)." >&2
    exit 1
fi
if [ -f "$ENV_FILE" ]; then
    cp "$ENV_FILE" "$ENV_FILE.bak.$(date +%Y-%m-%d_%H%M%S)"
fi

# --- helpers --------------------------------------------------------------
# alphanumeric only: safe in .env and won't trip Docker Compose interpolation.
# `|| true` swallows the SIGPIPE (141) that `head` closing the pipe produces,
# so it doesn't abort the script under `set -o pipefail`.
gen_secret() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom 2>/dev/null | head -c "${1:-32}" || true; }
gen_appkey() { printf 'base64:%s' "$(head -c 32 /dev/urandom | base64)"; }
ask() {
    local prompt="$1" def="${2:-}" ans=""
    if [ -n "$def" ]; then read -r -p "$prompt [$def]: " ans || true; echo "${ans:-$def}"
    else read -r -p "$prompt: " ans || true; echo "$ans"; fi
}
# Same as ask(), but doesn't echo what's typed — for secrets.
ask_secret() {
    local prompt="$1" ans=""
    read -r -s -p "$prompt: " ans || true; echo >&2; echo "$ans"
}

echo "=== Guidearr setup ==="
HOST=$(ask "Hostname the app is served from" "localhost")
PORT=$(ask "HTTPS port" "7979")

# docker-compose.yml interpolates these, so what is answered here is what actually gets
# published — the HTTPS port above included. It used to only reach APP_URL, which meant
# answering anything but 7979 produced a URL the stack did not listen on.
HTTP_BIND=$(ask "Bind address for the plain-HTTP port (for a reverse proxy in front)" "127.0.0.1")
HTTP_PORT=$(ask "Plain-HTTP port" "8080")

ADMIN_PATH=$(ask "Admin URL path segment" "admin")

# --- outgoing mail --------------------------------------------------------
# Guidearr relays through YOUR mail server. Nothing is bundled: a built-in mail
# catcher would hold password-reset and verification links in an unauthenticated
# inbox, which is a liability on any install that isn't a laptop.
echo
echo "--- Outgoing mail (password resets, email verification) ---"
echo "Guidearr sends through your own SMTP relay. Leave the host blank to skip:"
echo "mail is then written to the Laravel log and NOT delivered — you can set it"
echo "up later under Admin -> Environment."
MAIL_HOST=$(ask "SMTP relay host (blank = log only)" "")
if [ -n "$MAIL_HOST" ]; then
    MAIL_MAILER="smtp"
    MAIL_PORT=$(ask "SMTP port (465 = implicit TLS, 587 = STARTTLS)" "587")
    MAIL_USERNAME=$(ask "SMTP username (blank = none)" "")
    if [ -n "$MAIL_USERNAME" ]; then
        MAIL_PASSWORD=$(ask_secret "SMTP password")
    else
        MAIL_USERNAME="null"; MAIL_PASSWORD="null"
    fi
    # 465 is implicit TLS (smtps); 587/25 negotiate STARTTLS, which Laravel does
    # automatically when the scheme is left unset.
    if [ "$MAIL_PORT" = "465" ]; then MAIL_SCHEME="smtps"; else MAIL_SCHEME="null"; fi
else
    MAIL_MAILER="log"
    MAIL_HOST="127.0.0.1"; MAIL_PORT="587"; MAIL_SCHEME="null"
    MAIL_USERNAME="null"; MAIL_PASSWORD="null"
fi
MAIL_FROM_ADDRESS=$(ask "From address" "guidearr@${HOST}")

APP_URL="https://${HOST}:${PORT}"
APP_KEY=$(gen_appkey)

# docker-compose.yml reads all of these from this .env, so they no longer have to be kept
# in step by hand — and the passwords can be generated rather than being the word "secret"
# on every install in existence.
DB_DATABASE="tunarr"
DB_USERNAME="tunarr"

# A database volume is initialised ONCE, with whatever password it saw at the time. So on
# a --force rerun, carry the existing values over: generating new ones would leave the app
# unable to log in to the database it already has.
carry_over() {
    local key="$1" from="$2"
    [ -f "$from" ] || return 1
    sed -n "s/^${key}=//p" "$from" | head -n1 | tr -d '"'"'"'\r'
}
PRIOR_ENV=$(ls -1t "$ENV_FILE".bak.* 2>/dev/null | head -n1 || true)

DB_PASSWORD=$(carry_over DB_PASSWORD "$PRIOR_ENV" || true)
DB_ROOT_PASSWORD=$(carry_over DB_ROOT_PASSWORD "$PRIOR_ENV" || true)
[ -n "$DB_PASSWORD" ] || DB_PASSWORD=$(gen_secret 32)
# Never used by the app — it connects as DB_USERNAME. It exists because the MySQL image
# refuses to initialise without one, which is what used to stop a fresh install dead.
[ -n "$DB_ROOT_PASSWORD" ] || DB_ROOT_PASSWORD=$(gen_secret 32)

if [ -n "$PRIOR_ENV" ]; then
    echo "Carried the existing database passwords over from $PRIOR_ENV (the dbdata volume still expects them)." >&2
fi

TLS_PORT="${PORT}"

cat > "$ENV_FILE" <<EOF
APP_NAME=Guidearr
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=${APP_URL}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
# Used only to initialise the MySQL container — the app never connects as root. Changing
# it after the dbdata volume exists has no effect on the volume.
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}

# --- how the stack publishes itself --------------------------------------------
# docker-compose.yml interpolates these, so a port change here is a port change there.
# Keep TLS_PORT in step with the port in APP_URL above.
TLS_PORT=${TLS_PORT}
HTTP_BIND=${HTTP_BIND}
HTTP_PORT=${HTTP_PORT}
DB_LOCAL_BIND=127.0.0.1
DB_LOCAL_PORT=33060

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

# Outgoing mail relays through your own SMTP server. MAIL_MAILER=log writes mail
# to storage/logs instead of delivering it. Change these here or via Admin -> Environment.
MAIL_MAILER=${MAIL_MAILER}
MAIL_HOST=${MAIL_HOST}
MAIL_PORT=${MAIL_PORT}
MAIL_SCHEME=${MAIL_SCHEME}
MAIL_USERNAME="${MAIL_USERNAME}"
MAIL_PASSWORD="${MAIL_PASSWORD}"
MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS}
MAIL_FROM_NAME=Guidearr

# Admin panel URL segment (pick a hard-to-guess value to reduce probing).
# The admin account itself is created with `php artisan admin:password` (below) — no password in .env.
ADMIN_PATH=${ADMIN_PATH}
REGISTRATION_REQUIRES_APPROVAL=false

# Cloudflare Turnstile — leave blank to disable the CAPTCHA
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
EOF

chmod 640 "$ENV_FILE" 2>/dev/null || true

echo
echo "Wrote $ENV_FILE"
echo "  App URL    : ${APP_URL}"
echo "  Admin path : ${APP_URL}/${ADMIN_PATH}"
if [ "$MAIL_MAILER" = "log" ]; then
    echo "  Mail       : LOG ONLY — password-reset and verification emails will NOT be"
    echo "               delivered. Set MAIL_* under Admin -> Environment when ready."
else
    echo "  Mail       : relay via ${MAIL_HOST}:${MAIL_PORT}"
fi
echo
echo "Next:"
echo "  1) put TLS certs in ./certs (fullchain.pem + privkey.pem)"
echo "  2) set server_name in docker/nginx.conf to: ${HOST}"
echo "  3) docker compose up -d --build"
echo "  4) docker compose exec app php artisan migrate --force"
echo "  5) docker compose exec app php artisan admin:password   # create your admin — prompts for email + password"
