# syntax=docker/dockerfile:1
FROM node:24-bookworm-slim AS node
FROM composer:2 AS composer

FROM dunglas/frankenphp:1-php8.4-bookworm AS php
WORKDIR /app
RUN install-php-extensions pgsql pdo_pgsql opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

FROM php AS build
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY package.json package-lock.json .npmrc ./
RUN npm ci --include=dev --no-audit --no-fund

COPY . .
RUN mkdir -p storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --no-interaction --optimize \
    && composer check-platform-reqs --no-dev
RUN VITE_APP_NAME=PromptEdit npm run build

FROM php AS runtime
ENV APP_NAME=PromptEdit \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=pgsql \
    DB_SSLMODE=require \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    MAIL_MAILER=array

COPY . .
COPY --from=build /app/vendor ./vendor
COPY --from=build /app/public/build ./public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/production.ini /usr/local/etc/php/conf.d/99-promptedit.ini
COPY --chmod=755 docker/start.sh /usr/local/bin/promptedit-start

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy
USER www-data

EXPOSE 10000
HEALTHCHECK --start-period=60s CMD curl -fsS -o /dev/null "http://127.0.0.1:${PORT:-10000}/up" || exit 1
ENTRYPOINT ["/usr/local/bin/promptedit-start"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
