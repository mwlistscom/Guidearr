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
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
