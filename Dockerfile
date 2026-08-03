# ---- frontend assets -------------------------------------------------------------
# Built here so `docker compose up -d --build` genuinely refreshes them. They cannot just
# be left at public/build in the image: compose bind-mounts ./ over /var/www/html, which
# hides anything the image put there. They are staged at /opt/guidearr/build and copied
# into place by the entrypoint.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
# Tailwind scans these — see the @source lines in resources/css/app.css. flux.css is
# imported outright, so a missing copy fails the build rather than silently losing styles.
COPY vendor/livewire/flux/dist/flux.css ./vendor/livewire/flux/dist/flux.css
COPY vendor/livewire/flux/stubs ./vendor/livewire/flux/stubs
COPY vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
     ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
# The bunny() font plugin fetches the webfonts, so this stage needs network access.
RUN npm run build

# ---- application -----------------------------------------------------------------
FROM php:8.4-fpm-alpine
ENV COMPOSER_ALLOW_SUPERUSER=1
# gd (with png/jpeg/webp) is used to downscale uploaded branding images — an operator's
# 4000px logo would otherwise be served at full size to every visitor. No freetype: nothing
# renders text into an image.
RUN apk add --no-cache git unzip libzip-dev icu-dev oniguruma-dev linux-headers \
      libpng-dev libjpeg-turbo-dev libwebp-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql mbstring bcmath zip intl opcache pcntl gd \
 && apk del $PHPIZE_DEPS
# The base image ships no php.ini, so PHP would otherwise run on compiled-in defaults
# (2M uploads) that contradict both nginx and the app's own validation.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-guidearr.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=assets /app/public/build /opt/guidearr/build
COPY docker/entrypoint.sh /usr/local/bin/guidearr-entrypoint
RUN chmod +x /usr/local/bin/guidearr-entrypoint
WORKDIR /var/www/html
ENTRYPOINT ["guidearr-entrypoint"]
CMD ["php-fpm"]
