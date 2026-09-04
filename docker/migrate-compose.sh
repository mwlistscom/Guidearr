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
FROM_RUNNING=0
[ "$OLD" = "--from-running" ] && { FROM_RUNNING=1; OLD=""; }

if [ "$FROM_RUNNING" -eq 0 ] && { [ -z "$OLD" ] || [ ! -f "$OLD" ]; }; then
    cat >&2 <<USAGE
usage: $0 <path-to-your-old-docker-compose.yml>
       $0 --from-running

Your old docker-compose.yml was gitignored, and git replaces ignored files without
warning — so if you pulled before copying it aside, it is simply gone. That is recoverable:
the containers still running were started from it and still carry the values.

    $0 --from-running

If you did keep a copy, point this at it instead:

    $0 docker-compose.yml.backup
USAGE
    exit 2
fi

[ -f "$ENV_FILE" ] || { echo "No $ENV_FILE here. Run this from the Guidearr directory." >&2; exit 2; }

# --- recover from the containers themselves ---------------------------------------
# A running container keeps the environment and port bindings it was created with, so it
# is a faithful record of the compose file that started it — often the only one left.
if [ "$FROM_RUNNING" -eq 1 ]; then
    command -v docker >/dev/null 2>&1 || { echo "docker not found." >&2; exit 2; }

    db=$(docker ps --filter name=guidearr-db --format '{{.Names}}' | head -n1)
    web=$(docker ps --filter name=guidearr-web --format '{{.Names}}' | head -n1)

    if [ -z "$db" ] && [ -z "$web" ]; then
        echo "No running guidearr containers to read. Start them, or point this at a copy of the old file." >&2
        exit 2
    fi

    OLD=$(mktemp)
    trap 'rm -f "$OLD"' EXIT
    chmod 600 "$OLD"

    if [ -n "$db" ]; then
        docker inspect "$db" --format '{{range .Config.Env}}{{println .}}{{end}}' \
            | sed -n 's/^\(MYSQL_[A-Z_]*\)=\(.*\)$/      \1: \2/p' >> "$OLD"
    fi

    # Rendered as compose "bind:host:container" strings so the same parser handles both
    # sources. HostIp is empty when the mapping was written without a bind address. Both
    # containers are read: web carries 7979 and 8080, db carries 3306.
    for c in $web $db; do
        docker inspect "$c" --format \
          '{{range $p, $cs := .NetworkSettings.Ports}}{{range $cs}}{{if .HostIp}}      - "{{.HostIp}}:{{.HostPort}}:{{$p}}"{{else}}      - "{{.HostPort}}:{{$p}}"{{end}}{{"\n"}}{{end}}{{end}}' \
          | sed 's#/tcp"#"#' >> "$OLD"
    done

    echo "Recovered ${db:+$db }${web:+$web }settings from the running containers."
fi

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
