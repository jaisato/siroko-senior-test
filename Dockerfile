# Etapa Composer para cachear dependencias si hiciera falta
FROM composer:2 AS composer_stage

# PHP 8.3 FPM
FROM php:8.3-fpm-bookworm

# Opcional: alinea UID/GID con tu usuario (1000 es común en WSL)
ARG UID=1000
ARG GID=1000

# Paquetes del sistema y extensiones PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libzip-dev libpng-dev libonig-dev libxml2-dev \
    libpq-dev libjpeg-dev libfreetype6-dev libssl-dev \
    librabbitmq-dev pkg-config \
 && pecl install amqp \
 && docker-php-ext-enable amqp \
 && docker-php-ext-install -j$(nproc) sockets \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) intl zip pdo_mysql opcache gd \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && rm -rf /var/lib/apt/lists/*

# Config PHP
COPY php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

# Composer
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

# Carpeta de la app
WORKDIR /var/www

# Permisos: ajusta www-data a tu UID/GID para evitar problemas de escritura en bind mounts
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

# Usa www-data en dev
USER www-data
