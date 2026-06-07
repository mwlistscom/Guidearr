#!/bin/sh
# Guidearr — keep the bundled nginx logs bounded so storage can't fill on a long-running
# install. Dropped into the stock nginx image's /docker-entrypoint.d/, so it starts
# automatically with the web container — no host cron, no logrotate, nothing for the
# operator to configure.
#
# Why in-container: the log files are written by nginx as root, so only a root process
# in the web container can trim them (the app container runs as www-data and can't).
# nginx opens its logs with O_APPEND, so truncating in place is safe — every new line
# goes to the new end of file (no sparse files, no reopen signal needed).
#
# Tunables (set in the web service environment):
#   NGINX_LOG_MAX_BYTES   trim once a file grows past this (default 15 MB)
#   NGINX_LOG_KEEP_BYTES  how much tail to keep when trimming   (default  5 MB)
#   NGINX_LOG_INTERVAL    seconds between checks                (default 86400 = daily)

_glr_max="${NGINX_LOG_MAX_BYTES:-15728640}"
_glr_keep="${NGINX_LOG_KEEP_BYTES:-5242880}"
_glr_every="${NGINX_LOG_INTERVAL:-86400}"
_glr_dir="/var/www/html/storage/logs"

_glr_trim_one() {
    f="$1"
    [ -f "$f" ] || return 0
    sz=$(wc -c < "$f" 2>/dev/null || echo 0)
    [ "$sz" -gt "$_glr_max" ] || return 0
    tmp="$f.trim.$$"
    if tail -c "$_glr_keep" "$f" > "$tmp" 2>/dev/null; then
        # Drop the (likely partial) first line so the file starts on a record boundary.
        sed -i '1d' "$tmp" 2>/dev/null || true
        cat "$tmp" > "$f"   # truncate-in-place; nginx (O_APPEND) keeps writing cleanly
    fi
    rm -f "$tmp"
}

_glr_loop() {
    while :; do
        _glr_trim_one "$_glr_dir/nginx-access.log"
        _glr_trim_one "$_glr_dir/nginx-error.log"
        sleep "$_glr_every"
    done
}

# Run a check now, then daily, in the background. Must NOT exit/exec (this file may be
# sourced by the image entrypoint, which then starts nginx itself).
( _glr_loop ) >/dev/null 2>&1 &
