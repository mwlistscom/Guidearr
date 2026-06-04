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

echo "=== Guidearr setup ==="
HOST=$(ask "Hostname the app is served from" "localhost")
PORT=$(ask "HTTPS port" "7979")

ADMIN_EMAIL=""
read -r -p "Admin email: " ADMIN_EMAIL || true
while [ -z "$ADMIN_EMAIL" ]; do
    read -r -p "Admin email (required): " ADMIN_EMAIL || { echo "No email provided; aborting." >&2; exit 1; }
done

ADMIN_PASSWORD=$(ask "Admin password (blank = generate a strong one)" "")
GEN_PW=0
if [ -z "$ADMIN_PASSWORD" ]; then ADMIN_PASSWORD=$(gen_secret 20); GEN_PW=1; fi

ADMIN_PATH=$(ask "Admin URL path segment" "admin")

APP_URL="https://${HOST}:${PORT}"
APP_KEY=$(gen_appkey)

# DB credentials are FIXED here to match docker-compose.yml (the mysql service
# is initialised with database/user/password = tunarr / tunarr / secret).
# Change them in BOTH places if you want different values on a fresh volume.
DB_DATABASE="tunarr"
DB_USERNAME="tunarr"
DB_PASSWORD="secret"

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

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

# Dev mail capture via the bundled Mailpit service (UI at http://${HOST}:8025).
# For real delivery, change these here or later via Admin -> Environment.
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_SCHEME=null
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=guidearr@${HOST}
MAIL_FROM_NAME=Guidearr

# Admin bootstrap (consumed by: php artisan admin:sync)
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
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
echo "  Admin email: ${ADMIN_EMAIL}"
if [ "$GEN_PW" -eq 1 ]; then
    echo "  Admin pass : ${ADMIN_PASSWORD}   <-- save this; you'll change it on first login"
fi
echo
echo "Next:"
echo "  1) put TLS certs in ./certs (fullchain.pem + privkey.pem)"
echo "  2) set server_name in docker/nginx.conf to: ${HOST}"
echo "  3) docker compose up -d --build"
echo "  4) docker compose exec app php artisan migrate --force"
echo "  5) docker compose exec app php artisan admin:sync"
