#!/usr/bin/env bash
#
# Guidearr host heartbeat
# -----------------------
# Runs on the DOCKER HOST, every 5 minutes via cron. Checks the
# stack and the host, auto-restarts wedged services, and emails the admin when
# something is wrong -- without spamming (one alert per issue per cooldown, plus
# a single "recovered" note when it clears).
#
# Checks:
#   - each compose service is running (and healthy if it has a healthcheck)
#   - DB connectivity, worker liveness, stuck queue jobs, refresh staleness
#     (via `php artisan health:check` inside the app container)
#   - host CPU load and memory (and disk on the project dir)
#
# Remediation (auto): restarts worker/scheduler/app (force-recreate) and starts
# db if it is down. CPU/mem/disk are alert-only.
#
# Install: see health/README.md
#
set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${HEARTBEAT_ENV:-$SELF_DIR/heartbeat.env}"
if [ ! -f "$ENV_FILE" ]; then
    echo "heartbeat: missing config $ENV_FILE (copy heartbeat.env.example to heartbeat.env)" >&2
    exit 2
fi
# shellcheck disable=SC1090
. "$ENV_FILE"

# ---- defaults (overridable in heartbeat.env) --------------------------------
: "${COMPOSE_DIR:=/opt/Guidearr}"
: "${COMPOSE:=docker compose}"
: "${SERVICES:=db app web worker scheduler}"
: "${RESTARTABLE:=worker scheduler app db}"
: "${LOAD_WARN_PER_CORE:=2.0}"
: "${MEM_WARN_PCT:=90}"
: "${DISK_WARN_PCT:=90}"
: "${ALERT_COOLDOWN_SEC:=14400}"   # 4h
: "${STATE_DIR:=$SELF_DIR/state}"
: "${LOG_FILE:=$SELF_DIR/heartbeat.log}"
: "${MAIL_FROM:=guidearr-health@localhost}"
: "${MAIL_TO:=${ADMIN_EMAIL:-root@localhost}}"
: "${SMTP_URL:=}"
: "${SMTP_USER:=}"
: "${SMTP_PASS:=}"
: "${SMTP_STARTTLS:=0}"

mkdir -p "$STATE_DIR"
ts()  { date '+%Y-%m-%d %H:%M:%S %Z'; }
log() { printf '%s  %s\n' "$(ts)" "$*" >> "$LOG_FILE"; }

# single-flight: never let two runs overlap
exec 9>"$STATE_DIR/.lock"
if ! flock -n 9; then
    log "another heartbeat run is in progress; skipping"
    exit 0
fi

cd "$COMPOSE_DIR" || { log "FATAL cannot cd to $COMPOSE_DIR"; exit 2; }

dc() { $COMPOSE "$@"; }

# ---- email ------------------------------------------------------------------
send_email() {
    local subject="$1" body="$2"
    if [ -n "$SMTP_URL" ]; then
        local opts=(--silent --show-error --url "$SMTP_URL" --mail-from "$MAIL_FROM" --mail-rcpt "$MAIL_TO")
        [ -n "$SMTP_USER" ] && opts+=(--user "$SMTP_USER:$SMTP_PASS")
        [ "$SMTP_STARTTLS" = "1" ] && opts+=(--ssl-reqd)
        {
            printf 'From: %s\r\n' "$MAIL_FROM"
            printf 'To: %s\r\n'   "$MAIL_TO"
            printf 'Subject: %s\r\n' "$subject"
            printf 'Date: %s\r\n'  "$(date -R)"
            printf '\r\n%s\r\n'    "$body"
        } | curl "${opts[@]}" --upload-file - >>"$LOG_FILE" 2>&1 \
            && log "email sent: $subject" \
            || log "email FAILED (curl/SMTP): $subject"
    elif command -v sendmail >/dev/null 2>&1; then
        printf 'To: %s\nSubject: %s\n\n%s\n' "$MAIL_TO" "$subject" "$body" | sendmail -t \
            && log "email sent via sendmail: $subject"
    else
        log "no mail transport (set SMTP_URL in heartbeat.env or install sendmail); would have sent: $subject"
    fi
}

# ---- check state ------------------------------------------------------------
declare -A ISSUE_MSG          # key -> human message
declare -A WANT_RESTART       # svc -> 1
ACTIONS=()

issue()        { ISSUE_MSG["$1"]="$2"; log "ISSUE [$1] $2"; }
want_restart() { case " $RESTARTABLE " in *" $1 "*) WANT_RESTART["$1"]=1 ;; esac; }

# ---- 1. container status ----------------------------------------------------
for svc in $SERVICES; do
    cid="$(dc ps -q "$svc" 2>/dev/null)"
    if [ -z "$cid" ]; then
        issue "svc_$svc" "container '$svc' is not running"
        want_restart "$svc"
        continue
    fi
    status="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null)"
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null)"
    if [ "$status" != "running" ] || { [ "$health" != "none" ] && [ "$health" != "healthy" ]; }; then
        issue "svc_$svc" "container '$svc' status=$status health=$health"
        want_restart "$svc"
    fi
done

# ---- 2. internal health probe (DB / worker / queue / refresh) ---------------
HC_OUT="$(dc exec -T app php artisan health:check --format=env 2>/dev/null)"
if [ -z "$HC_OUT" ]; then
    issue "app_probe" "health:check could not run (app container not responding)"
    want_restart "app"
else
    hc() { printf '%s\n' "$HC_OUT" | awk -F= -v k="$1" '$1==k{print $2; exit}'; }
    [ "$(hc db)" = "fail" ]        && { issue "db_conn" "MySQL not accepting connections (health:check db=fail)"; want_restart "db"; }
    case "$(hc worker)" in
        ok|"") : ;;
        *) issue "worker" "worker $(hc worker) (beat $(hc worker_beat_age)s old)"; want_restart "worker" ;;
    esac
    [ "$(hc queue)" = "stuck" ]    && { issue "queue" "$(hc stuck) job(s) stuck running past the orphan window"; want_restart "worker"; }
    [ "$(hc refresh)" = "stale" ]  && issue "refresh" "oldest enabled provider refreshed $(hc oldest_refresh_age_hours)h ago ($(hc oldest_refresh_provider))"
fi

# ---- 3. host CPU / memory / disk (alert-only) -------------------------------
read -r load1 _ < /proc/loadavg
cores="$(nproc 2>/dev/null || echo 1)"
per_core="$(awk -v l="$load1" -v c="$cores" 'BEGIN{printf "%.2f", l/c}')"
awk -v p="$per_core" -v t="$LOAD_WARN_PER_CORE" 'BEGIN{exit !(p+0 > t+0)}' \
    && issue "cpu" "load1=${load1} over ${cores} cores = ${per_core}/core (warn ${LOAD_WARN_PER_CORE})"

mem_total="$(awk '/^MemTotal:/{print $2}'     /proc/meminfo)"
mem_avail="$(awk '/^MemAvailable:/{print $2}' /proc/meminfo)"
mem_pct=0
[ "${mem_total:-0}" -gt 0 ] && mem_pct="$(awk -v t="$mem_total" -v a="$mem_avail" 'BEGIN{printf "%d", (t-a)*100/t}')"
[ "$mem_pct" -ge "$MEM_WARN_PCT" ] && issue "mem" "host memory ${mem_pct}% used (warn ${MEM_WARN_PCT}%)"

disk_pct="$(df -P "$COMPOSE_DIR" 2>/dev/null | awk 'NR==2{gsub(/%/,"",$5); print $5}')"
[ "${disk_pct:-0}" -ge "$DISK_WARN_PCT" ] && issue "disk" "disk ${disk_pct}% used on $COMPOSE_DIR (warn ${DISK_WARN_PCT}%)"

# ---- 4. remediation (each service at most once) -----------------------------
for svc in "${!WANT_RESTART[@]}"; do
    if [ "$svc" = "db" ]; then
        dc up -d db >>"$LOG_FILE" 2>&1            # start if down; don't recreate the DB casually
    else
        dc up -d --force-recreate "$svc" >>"$LOG_FILE" 2>&1
    fi
    ACTIONS+=("restarted $svc")
    log "REMEDIATION restarted $svc"
done

# ---- 5. alerting (throttled) + recovery -------------------------------------
now="$(date +%s)"
ACTIVE_FILE="$STATE_DIR/active.keys"
prev_active="$( [ -f "$ACTIVE_FILE" ] && cat "$ACTIVE_FILE" || true )"
issue_keys=("${!ISSUE_MSG[@]}")

if [ "${#issue_keys[@]}" -gt 0 ]; then
    due=0
    body=""
    for k in "${issue_keys[@]}"; do
        last=0; [ -f "$STATE_DIR/alert_$k.last" ] && last="$(cat "$STATE_DIR/alert_$k.last")"
        [ $(( now - last )) -ge "$ALERT_COOLDOWN_SEC" ] && due=1
        body+="- [$k] ${ISSUE_MSG[$k]}"$'\n'
    done

    if [ "$due" = "1" ]; then
        actions_txt=""
        [ "${#ACTIONS[@]}" -gt 0 ] && actions_txt=$'\nActions taken automatically:\n'"$(printf '  - %s\n' "${ACTIONS[@]}")"
        send_email "[Guidearr] health issues on $(hostname): ${issue_keys[*]}" \
"Guidearr heartbeat detected issue(s) at $(ts):

${body}${actions_txt}
Host:   $(hostname)
Load:   $(cat /proc/loadavg)
Memory: ${mem_pct}% used
Disk:   ${disk_pct:-?}% on ${COMPOSE_DIR}

(Repeat alerts for the same issue are suppressed for $((ALERT_COOLDOWN_SEC/3600))h.)"
        for k in "${issue_keys[@]}"; do echo "$now" > "$STATE_DIR/alert_$k.last"; done
    else
        log "issues present but all within cooldown: ${issue_keys[*]}"
    fi
    printf '%s\n' "${issue_keys[@]}" > "$ACTIVE_FILE"
else
    if [ -n "$prev_active" ]; then
        send_email "[Guidearr] recovered on $(hostname)" \
"All Guidearr health checks are passing again at $(ts).

Previously flagged: $(printf '%s ' $prev_active)"
        rm -f "$STATE_DIR"/alert_*.last     # reset cooldowns so a recurrence alerts immediately
    fi
    : > "$ACTIVE_FILE"
    log "all checks OK"
fi
