#!/bin/sh
# Guidearr container entrypoint.
#
# Installs the PHP dependencies and publishes the frontend assets that were built into the
# image, then hands over to the stock PHP entrypoint.
#
# Why this exists: the compose file bind-mounts ./ over /var/www/html, so anything the image
# places at public/build or vendor/ is hidden by the host directory at runtime. Both are
# staged outside that path at build time and copied in here, which is what makes
# `docker compose up -d --build` actually refresh them. Without it an upgraded install keeps
# whatever it happened to have: the previous stylesheet, so any new CSS class silently does
# nothing — and, until v1.23.12, the previous *packages*, so a dependency security fix
# shipped to git and reached nobody.
#
# Deliberately fail-open: a container must never refuse to start because it could not copy
# files. The worker and scheduler run as www-data and simply skip, since the app container
# runs as root and has already done it.

SRC=/opt/guidearr/build
DST=/var/www/html/public/build

VENDOR_SRC=/opt/guidearr/vendor
VENDOR_DST=/var/www/html/vendor
LOCK=/var/www/html/composer.lock

publish_vendor() {
    [ -d "$VENDOR_SRC" ] || return 0
    [ -f "$LOCK" ] || return 0

    # Only the app container (root) installs. Keeping a single writer is what stops the
    # worker and scheduler racing it during a simultaneous `up -d`.
    [ "$(id -u)" = "0" ] || return 0

    staged=$(cat "$VENDOR_SRC/.guidearr-lock" 2>/dev/null)
    live=$(sha256sum "$LOCK" 2>/dev/null | cut -d' ' -f1)
    [ -n "$staged" ] && [ -n "$live" ] || return 0

    # The code on disk asks for different packages than this image was built with, so the
    # staged vendor is the wrong set — installing it would quietly pin the old versions.
    # This is exactly the case a `git pull` without `--build` lands in, so say so loudly.
    if [ "$staged" != "$live" ]; then
        echo "guidearr: composer.lock does not match this image — run 'docker compose up -d --build' to install the dependencies it asks for" >&2
        return 0
    fi

    # Already the right set. Also leaves a local `composer install` (dev dependencies and
    # all) alone, as long as it came from this same lock file.
    [ "$(cat "$VENDOR_DST/.guidearr-lock" 2>/dev/null)" = "$live" ] && return 0

    [ -w /var/www/html ] || return 0

    tmp="/var/www/html/.vendor.incoming.$$"
    old="/var/www/html/.vendor.previous.$$"
    rm -rf "$tmp" "$old" 2>/dev/null

    # Copy first, then swap by rename: a request in flight sees either the whole old tree or
    # the whole new one, never a half-written vendor/.
    cp -a "$VENDOR_SRC" "$tmp" 2>/dev/null || { rm -rf "$tmp" 2>/dev/null; return 0; }

    if [ -d "$VENDOR_DST" ]; then
        mv "$VENDOR_DST" "$old" 2>/dev/null || { rm -rf "$tmp" 2>/dev/null; return 0; }
    fi

    if mv "$tmp" "$VENDOR_DST" 2>/dev/null; then
        chown -R www-data:www-data "$VENDOR_DST" 2>/dev/null
        # Package discovery is cached against the packages that were there a moment ago.
        # Laravel rebuilds both files on demand once they are gone.
        rm -f /var/www/html/bootstrap/cache/packages.php \
              /var/www/html/bootstrap/cache/services.php 2>/dev/null
        echo "guidearr: installed PHP dependencies into vendor/"
    else
        # Never leave the app with no vendor at all.
        [ -d "$old" ] && mv "$old" "$VENDOR_DST" 2>/dev/null
    fi

    rm -rf "$tmp" "$old" 2>/dev/null
    return 0
}

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

publish_vendor
publish_assets

exec docker-php-entrypoint "$@"
