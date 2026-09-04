#!/usr/bin/env bash
#
# Guidearr — one-time migration to the tracked docker-compose.yml.
#
# docker-compose.yml used to be gitignored, so every install carried its own copy and no
# upgrade could ever change it. It is tracked now, and the install-specific values it used
# to hold live in .env instead. This reads those values out of your old file and writes
# them into .env, so the tracked one produces exactly the stack you already had.
#
#   ./docker/migrate-compose.sh docker-compose.yml.backup
#
# Safe to run twice: it only fills in keys that are missing or different, prints every
# change it makes (never the secrets themselves), and keeps a backup of .env.
set -euo pipefail

OLD="${1:-}"
ENV_FILE="${ENV_FILE:-.env}"

if [ -z "$OLD" ] || [ ! -f "$OLD" ]; then
    cat >&2 <<USAGE
usage: $0 <path-to-your-old-docker-compose.yml>

Before pulling this release you were told to keep a copy, e.g.

    cp docker-compose.yml docker-compose.yml.backup

Point this at that copy. If you no longer have one, you can recover the values from the
running containers instead:

    docker compose port web 8080     # the plain-HTTP bind and port
    docker inspect guidearr-db-1 --format '{{range .Config.Env}}{{println .}}{{end}}' | grep MYSQL
USAGE
    exit 2
fi

[ -f "$ENV_FILE" ] || { echo "No $ENV_FILE here. Run this from the Guidearr directory." >&2; exit 2; }

# --- read a value out of the old compose file -------------------------------------
# Literal values only. A ${VAR} reference means it already came from .env, so there is
# nothing to carry over.
yaml_env() {
    local key="$1" v
    v=$(sed -n "s/^[[:space:]]*${key}:[[:space:]]*//p" "$OLD" | head -n1 | tr -d '"'"'"'\r')
    case "$v" in ''|'${'*) return 1 ;; *) printf '%s' "$v" ;; esac
}

# Ports are matched on the CONTAINER side (the last field), which is fixed by
# docker/nginx.conf and the mysql image — so it identifies the mapping regardless of what
# the host side was changed to. Handles both "host:container" and "bind:host:container".
port_field() {
    local container="$1" want="$2" line host bind
    line=$(grep -oE '"[0-9a-zA-Z_.:-]*:'"${container}"'"' "$OLD" | head -n1 | tr -d '"') || true
    [ -n "$line" ] || return 1
    case "$(printf '%s' "$line" | awk -F: '{print NF}')" in
        2) bind=""              ; host=$(printf '%s' "$line" | cut -d: -f1) ;;
        3) bind=$(printf '%s' "$line" | cut -d: -f1); host=$(printf '%s' "$line" | cut -d: -f2) ;;
        *) return 1 ;;
    esac
    case "$want" in
        bind) [ -n "$bind" ] && printf '%s' "$bind" || return 1 ;;
        host) printf '%s' "$host" ;;
    esac
}

# --- write a key into .env ---------------------------------------------------------
BACKED_UP=0
changed=0
set_env() {
    local key="$1" val="$2" secret="${3:-0}" current shown
    current=$(sed -n "s/^${key}=//p" "$ENV_FILE" | head -n1 | tr -d '"'"'"'\r')
    [ "$current" = "$val" ] && return 0

    if [ "$BACKED_UP" -eq 0 ]; then
        cp "$ENV_FILE" "$ENV_FILE.bak.$(date +%Y-%m-%d_%H%M%S)"
        BACKED_UP=1
    fi

    if grep -q "^${key}=" "$ENV_FILE"; then
        # The value can contain slashes and &, so do the substitution in awk, not sed.
        awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' \
            "$ENV_FILE" > "$ENV_FILE.tmp" && mv "$ENV_FILE.tmp" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$val" >> "$ENV_FILE"
    fi

    [ "$secret" = "1" ] && shown="(hidden)" || shown="$val"
    printf '  %-16s -> %s\n' "$key" "$shown"
    changed=$((changed + 1))
}

echo "Reading $OLD …"

if v=$(yaml_env MYSQL_PASSWORD);      then set_env DB_PASSWORD       "$v" 1; fi
if v=$(yaml_env MYSQL_ROOT_PASSWORD); then set_env DB_ROOT_PASSWORD  "$v" 1; fi
if v=$(yaml_env MYSQL_DATABASE);      then set_env DB_DATABASE       "$v"; fi
if v=$(yaml_env MYSQL_USER);          then set_env DB_USERNAME       "$v"; fi

if v=$(port_field 7979 host); then set_env TLS_PORT       "$v"; fi
if v=$(port_field 8080 bind); then set_env HTTP_BIND      "$v"; fi
if v=$(port_field 8080 host); then set_env HTTP_PORT      "$v"; fi
if v=$(port_field 3306 bind); then set_env DB_LOCAL_BIND  "$v"; fi
if v=$(port_field 3306 host); then set_env DB_LOCAL_PORT  "$v"; fi

echo
if [ "$changed" -eq 0 ]; then
    echo "Nothing to change — $ENV_FILE already matches $OLD."
else
    echo "Updated $changed value(s) in $ENV_FILE (previous copy kept alongside it)."
fi

# The app never connects as root, but the MySQL image refuses to initialise without a root
# password, and the tracked compose file now stops rather than starting without one.
if ! grep -q '^DB_ROOT_PASSWORD=.\+' "$ENV_FILE"; then
    cat >&2 <<'WARN'

DB_ROOT_PASSWORD is still empty. Your old compose file did not set one literally, so there
was nothing to copy. If your database volume already exists this value is never checked —
any non-empty string will do:

    echo "DB_ROOT_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)" >> .env
WARN
fi

cat <<'NEXT'

Check the result before starting anything — this prints the fully resolved stack, and
fails loudly if a required value is missing:

    docker compose config

Then bring it up:

    docker compose up -d --build
NEXT
