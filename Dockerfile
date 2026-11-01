FROM node:26-alpine AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

WORKDIR /build/resources/twa

COPY resources/twa/package.json resources/twa/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY resources/twa ./
RUN npm run build

FROM php:8.5-fpm-alpine AS app

ARG UID=1000
ARG PHPREDIS_VERSION=6.3.0
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# pdo, mbstring and OPcache are compiled into the official PHP 8.5 image (OPcache cannot even be built as an
# extension any more), so only what is really missing is built here.
# postgresql18-client: pg_dump of spatie/laravel-backup must not be older than the PostgreSQL 18 server.
RUN apk add --no-cache \
        bash curl icu-dev libzip-dev postgresql-dev postgresql18-client \
        freetype-dev libpng-dev \
        linux-headers autoconf g++ make \
    && docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql bcmath intl pcntl zip gd \
    # PECL occasionally answers "No releases available": retry before failing the build.
    && for attempt in 1 2 3 4; do pecl install -f redis-${PHPREDIS_VERSION} && break; [ "$attempt" = 4 ] && exit 1; sleep $((attempt * 15)); done \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/* /var/cache/apk/*

# A lost extension or a pg_dump of another major must break the build, not production.
RUN set -eu; \
    for ext in pdo_pgsql bcmath intl mbstring pcntl zip gd redis 'Zend OPcache'; do \
        php -m | grep -qix "$ext" || { echo "missing PHP extension: $ext" >&2; exit 1; }; \
    done
RUN pg_dump --version | grep -q ' 18\.' || { echo "pg_dump must be 18.x, got: $(pg_dump --version)" >&2; exit 1; }

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/zz-lexiflow.ini /usr/local/etc/php/conf.d/zz-lexiflow.ini

RUN addgroup -g "${GID}" app \
    && adduser -D -u "${UID}" -G app -s /bin/bash app

WORKDIR /var/www/html

COPY --chown=app:app . .
COPY --from=frontend --chown=app:app /build/public/build ./public/build
COPY --from=frontend --chown=app:app /build/public/twa ./public/twa

RUN mkdir -p \
        bootstrap/cache \
        storage/app/public/audio \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R app:app bootstrap/cache storage \
    && chmod +x docker/entrypoint.sh

USER app

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]

HEALTHCHECK --interval=10s --timeout=5s --retries=6 \
    CMD php-fpm -t || exit 1

FROM nginx:1.30-alpine AS nginx

COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx-entrypoint.sh /usr/local/bin/lexiflow-nginx-entrypoint

RUN chmod +x /usr/local/bin/lexiflow-nginx-entrypoint

ENTRYPOINT ["/usr/local/bin/lexiflow-nginx-entrypoint"]
