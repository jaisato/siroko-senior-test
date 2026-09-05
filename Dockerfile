# syntax=docker/dockerfile:1
#
# Targets:
#   dev   - compose default: bind-mounted source, Xdebug, Composer; runs as www-data.
#   prod  - self-contained image: application code, production vendor/, warmed
#           cache, opcache tuned, no Xdebug, no Composer; runs as www-data.
#
# The previous single-stage image installed Xdebug for everyone and copied no
# application code at all, so it could not run outside a bind mount.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
FROM composer:2 AS composer_bin

# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev librabbitmq-dev pkg-config \
 && pecl install amqp \
 && docker-php-ext-enable amqp \
 && docker-php-ext-install -j"$(nproc)" intl zip pdo_mysql opcache \
 && apt-get purge -y --auto-remove pkg-config \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

# Production php.ini as the baseline for every target; dev adds its overrides.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www

# ---------------------------------------------------------------------------
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && rm -rf /tmp/pear

COPY php/php-dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
COPY php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

# Align www-data with the host user so bind-mounted files stay writable.
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

USER www-data

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/app
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY app/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0

COPY --from=vendor --chown=www-data:www-data /var/www/app /var/www/app

WORKDIR /var/www/app
RUN php bin/console cache:warmup --env=prod --no-debug \
 && chown -R www-data:www-data var

USER www-data
