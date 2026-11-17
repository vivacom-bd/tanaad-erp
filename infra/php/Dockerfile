FROM php:8.5-fpm-alpine AS base

# opcache is built into PHP 8.5 core: docker-php-ext-install opcache fails with "cp: modules/*" missing.
# Redis goes through predis (REDIS_CLIENT=predis), so the phpredis extension is not built: it does not compile on PHP 8.5.
RUN apk add --no-cache libzip-dev icu-dev linux-headers $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql pcntl bcmath intl zip \
 && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/php/php.ini /usr/local/etc/php/conf.d/tanaad.ini

WORKDIR /var/www/api
