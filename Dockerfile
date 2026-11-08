# syntax=docker/dockerfile:1
# check=skip=SecretsUsedInArgOrEnv

# The check skipped above flags VITE_PUSHER_APP_KEY, the key the browser connects to Pusher with. It is public by design.
#
# One image for the whole site. The web server, the queue worker and the scheduler are the same image started with a different
# command (compose.production.yaml). docs/docker.md says how to build it, start it and keep it.
#
#   docker build -t metaforger .
#
# Four stages: PHP with the extensions the site needs, the PHP packages, the browser files, and what runs.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4
ARG NODE_IMAGE=node:24-alpine
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer-binary

# --- PHP and its extensions ---------------------------------------------------------------------------------------------------------
# bcmath (money), exif (sideways photos), gd with WebP (pictures), intl (the list of languages in Settings → Profile), the MySQL and
# PostgreSQL drivers, pcntl (without it a queue worker cannot finish its job when it is told to stop, nor enforce --timeout), phpredis
# and zip (account exports). OPcache and the rest of what README.md lists are in the PHP image already.
FROM ${PHP_IMAGE} AS php
RUN install-php-extensions bcmath exif gd intl pcntl pdo_mysql pdo_pgsql redis zip

# --- The PHP packages, without the test tools -----------------------------------------------------------------------------------------
FROM php AS vendor
ENV COMPOSER_ALLOW_SUPERUSER=1
# git is for the Discord companion, which Composer fetches from its repository; unzip for the packages it fetches as archives.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-binary /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --no-scripts --optimize --classmap-authoritative

# --- The browser files ----------------------------------------------------------------------------------------------------------------
# Vite builds these settings into the JavaScript (docs/configuration.md, "Built into the JavaScript"), so they are given when the image
# is built and a change to one needs a new image. The fonts are fetched from Bunny at this point and then served from the site itself.
FROM ${NODE_IMAGE} AS assets
ARG VITE_APP_NAME=MetaForger
ARG VITE_BROADCAST_DRIVER=pusher
ARG VITE_PUSHER_APP_KEY=
ARG VITE_PUSHER_APP_CLUSTER=mt1
ARG VITE_PUSHER_HOST=
ARG VITE_PUSHER_PORT=
ARG VITE_PUSHER_SCHEME=https
ARG VITE_PUSHER_FORCE_TLS=
ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_BROADCAST_DRIVER=$VITE_BROADCAST_DRIVER \
    VITE_PUSHER_APP_KEY=$VITE_PUSHER_APP_KEY \
    VITE_PUSHER_APP_CLUSTER=$VITE_PUSHER_APP_CLUSTER \
    VITE_PUSHER_HOST=$VITE_PUSHER_HOST \
    VITE_PUSHER_PORT=$VITE_PUSHER_PORT \
    VITE_PUSHER_SCHEME=$VITE_PUSHER_SCHEME \
    VITE_PUSHER_FORCE_TLS=$VITE_PUSHER_FORCE_TLS
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# vite.config.ts points the ziggy-js alias at the PHP package.
COPY --from=vendor /app/vendor/tightenco/ziggy vendor/tightenco/ziggy
RUN npm run build

# --- What runs ------------------------------------------------------------------------------------------------------------------------
FROM php AS runtime
# libcap2-bin is for setcap, below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build public/build
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-metaforger.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/metaforger-entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    SERVER_NAME=:80

# PHP's production settings, the folders PHP writes to, the link that serves public pictures, the packages Laravel finds by itself, and an
# empty .env: the site takes its settings from the environment it is started with (which wins over the file), and the installer wants a
# .env that it can find. All of it is done as root. The site runs as www-data, which may bind port 80 and may write to storage,
# bootstrap/cache and the two folders Caddy keeps its certificates and its configuration in.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan storage:link \
    && php artisan package:discover \
    && touch .env \
    && chmod +x /usr/local/bin/metaforger-entrypoint \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data .env storage bootstrap/cache /data /config

USER www-data

# The PHP image checks an address of the web server's own, which a worker and the scheduler do not have, so it would call them unhealthy.
# compose.production.yaml gives the web container a check of its own.
HEALTHCHECK NONE

VOLUME ["/app/storage", "/data", "/config"]
EXPOSE 80 443 443/udp

ENTRYPOINT ["metaforger-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
