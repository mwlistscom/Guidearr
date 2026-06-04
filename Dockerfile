FROM php:8.4-fpm-alpine
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apk add --no-cache git unzip libzip-dev icu-dev oniguruma-dev linux-headers $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql mbstring bcmath zip intl opcache pcntl \
 && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
