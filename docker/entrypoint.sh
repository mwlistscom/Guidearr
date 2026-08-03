#!/bin/sh
# Guidearr container entrypoint.
#
# Publishes the frontend assets that were compiled during the image build into
# public/build, then hands over to the stock PHP entrypoint.
#
# Why this exists: the compose file bind-mounts ./ over /var/www/html, so assets baked
# into the image at public/build are hidden by the host directory at runtime. They are
# staged outside that path at build time and copied in here, which is what makes
# `docker compose up -d --build` actually refresh them. Without it an upgraded install
# keeps whatever stylesheet it happened to have, and any new CSS class silently does
# nothing.
#
# Deliberately fail-open: a container must never refuse to start because assets could not
# be published. The worker and scheduler run as www-data and may not own public/build —
# they simply skip, since the app container has already done it.

SRC=/opt/guidearr/build
DST=/var/www/html/public/build

publish_assets() {
    [ -d "$SRC" ] || return 0

    # Same manifest means the same build; nothing to do.
    if cmp -s "$SRC/manifest.json" "$DST/manifest.json" 2>/dev/null; then
        return 0
    fi

    mkdir -p "$DST" 2>/dev/null || return 0
    [ -w "$DST" ] || return 0

    tmp="$DST/.incoming.$$"
    rm -rf "$tmp" 2>/dev/null
    mkdir -p "$tmp" 2>/dev/null || return 0

    # Copy into a staging dir first, so a half-written set is never served.
    if cp -a "$SRC/." "$tmp/" 2>/dev/null; then
        find "$DST" -mindepth 1 -maxdepth 1 ! -name '.incoming.*' -exec rm -rf {} + 2>/dev/null
        cp -a "$tmp/." "$DST/" 2>/dev/null
        chown -R www-data:www-data "$DST" 2>/dev/null
        echo "guidearr: published frontend assets to public/build"
    fi

    rm -rf "$tmp" 2>/dev/null
    return 0
}

publish_assets

exec docker-php-entrypoint "$@"
