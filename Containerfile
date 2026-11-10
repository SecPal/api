# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0

ARG COMPOSER_IMAGE=docker.io/library/composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760
ARG FRANKENPHP_IMAGE=docker.io/dunglas/frankenphp:1.13.5-php8.4.27-alpine@sha256:2a0633266ba2c821ad3cabb88caa30792a81e2d6c3c3a0720906114c660ede08
FROM ${COMPOSER_IMAGE} AS composer
FROM ${FRANKENPHP_IMAGE} AS sqlite-build
ARG SOURCE_DATE_EPOCH=0
COPY docker/sqlite /build/sqlite
WORKDIR /build/sqlite
RUN ./build.sh

FROM ${FRANKENPHP_IMAGE} AS extensions
RUN --mount=type=bind,from=sqlite-build,source=/out,target=/tmp/secpal-sqlite,ro \
    apk add --no-cache --no-network --keys-dir=/tmp/secpal-sqlite/keys \
        --repositories-file=/dev/null --repository=/tmp/secpal-sqlite sqlite-libs=3.54.0-r0 \
    && install -D -m 0644 /tmp/secpal-sqlite/provenance.json /usr/share/secpal/sqlite-build.json
COPY docker/python/opentimestamps-requirements.txt /tmp/opentimestamps-requirements.txt
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
        sodium \
        xml \
        zip \
    && apk add --no-cache python3=3.14.10-r1 libcurl=8.23.0-r0 brotli-libs=1.2.1-r0 \
    && apk add --no-cache --virtual .python-build-deps py3-pip=26.1.2-r0 \
    && pip3 install --break-system-packages --no-cache-dir --only-binary=:all: \
        --require-hashes -r /tmp/opentimestamps-requirements.txt \
    && python3 -m pip check \
    && apk del --no-network .python-build-deps curl \
    && rm /tmp/opentimestamps-requirements.txt
FROM extensions AS dependencies
RUN apk add --no-cache unzip=6.0-r16
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
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
COPY scripts/ots-stamp-hash.py scripts/ots-upgrade.py scripts/ots-verify.py ./scripts/
COPY storage ./storage
COPY artisan LICENSE THIRD-PARTY-NOTICES.md ./
COPY LICENSES ./LICENSES
COPY docs/ADDRESS_DATA.md ./docs/ADDRESS_DATA.md
RUN rm -f bootstrap/cache/*.php && composer dump-autoload \
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

# The extension installer removes build dependencies; native smoke rejects retained headers.
RUN apk del --no-network tar \
    && addgroup -g "${APP_GID}" secpal \
    && adduser -D -u "${APP_UID}" -G secpal -h /home/secpal -s /sbin/nologin secpal \
    && if [ -n "$(getcap /usr/local/bin/frankenphp)" ]; then setcap -r /usr/local/bin/frankenphp; fi \
    && install -d -m 0750 -o secpal -g secpal /config/caddy /config/psysh /data/caddy

COPY --from=dependencies --chown=root:root /app /app
COPY --chown=root:root --chmod=0644 docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chown=root:root --chmod=0644 docker/php/conf.d/production.ini /usr/local/etc/php/conf.d/zz-secpal-production.ini
COPY --chown=root:root --chmod=0755 docker/healthchecks/http-live.sh /usr/local/bin/secpal-http-live

RUN cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" \
    && chmod 0644 /etc/frankenphp/Caddyfile \
    && chmod 0644 "${PHP_INI_DIR}/conf.d/zz-secpal-production.ini" \
    && chmod 0755 /usr/local/bin/secpal-http-live \
    && find /app -xdev -type d -exec chmod 0755 {} + \
    && find /app -xdev -type f -exec chmod 0644 {} + \
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
    && find storage bootstrap/cache -type f -exec chmod 0640 {} +

USER secpal

EXPOSE 8080

HEALTHCHECK NONE
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
