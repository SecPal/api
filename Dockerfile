# syntax=docker/dockerfile:1.7
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.12.6-php8.4.23-bookworm@sha256:79b347211bfec90d6a1373c4956a7d3832c8248a2ff2d76bd0b677f37284d32f

FROM ${FRANKENPHP_IMAGE} AS extensions

# hadolint ignore=DL3008
RUN install-php-extensions \
        bcmath \
        curl \
        gettext \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        redis-6.3.0 \
        sodium \
        xml \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip \
    && pip3 install --break-system-packages --no-cache-dir opentimestamps-client==0.7.2 \
    && rm -rf /var/lib/apt/lists/*

FROM extensions AS dependencies

COPY --from=composer:2.10.2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist

COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY scripts/ots-stamp-hash.py scripts/ots-verify.py ./scripts/
COPY storage ./storage
COPY artisan LICENSE THIRD-PARTY-NOTICES.md ./
COPY LICENSES ./LICENSES

RUN composer dump-autoload \
        --classmap-authoritative \
        --no-dev \
        --no-interaction \
        --no-scripts \
    && php artisan package:discover --ansi

FROM extensions AS runtime

ARG APP_UID=10001
ARG APP_GID=10001

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

WORKDIR /app

RUN groupadd --gid "${APP_GID}" secpal \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home \
        --home-dir /home/secpal --shell /usr/sbin/nologin secpal \
    && if [ -n "$(getcap /usr/local/bin/frankenphp)" ]; then setcap -r /usr/local/bin/frankenphp; fi \
    && install -d -m 0750 -o secpal -g secpal /config/caddy /data/caddy

COPY --from=dependencies --chown=root:root /app /app
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php/conf.d/production.ini /usr/local/etc/php/conf.d/zz-secpal-production.ini
COPY docker/healthchecks/http-live.sh /usr/local/bin/secpal-http-live

RUN cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" \
    && install -d -m 0750 -o secpal -g secpal \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R secpal:secpal storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 0750 {} + \
    && find storage bootstrap/cache -type f -exec chmod 0640 {} + \
    && chmod 0755 /usr/local/bin/secpal-http-live

USER secpal

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
