FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache libzip-dev icu-dev linux-headers $PHPIZE_DEPS \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl bcmath intl zip opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/php/php.ini /usr/local/etc/php/conf.d/tanaad.ini

WORKDIR /var/www/api
