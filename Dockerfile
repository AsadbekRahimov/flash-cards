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
# OPcache for the production image only (docs/19 C7; Dockerfile.dev does not have it): the code of the image never changes,
# a deploy recreates the container, so files are never revalidated.
COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini
# A lost OPcache setting must break the build, not production.
RUN set -eu; \
    info="$(php -i)"; \
    for setting in 'opcache.enable => On' 'opcache.validate_timestamps => Off' 'opcache.save_comments => On'; do \
        printf '%s\n' "$info" | grep -q "^$setting" || { echo "OPcache setting not applied: $setting" >&2; exit 1; }; \
    done

# php-fpm pool (prod image only): the image default is pm.max_children = 5, which saturates under load
# ("server reached pm.max_children"). The values come from the environment; these are the defaults, a
# PHP_FPM_* variable of the container (.env via compose env_file) overrides them. Sizing: docs/DEVOPS_GUIDE.md.
COPY docker/php/zz-lexiflow-pool.conf /usr/local/etc/php-fpm.d/zz-lexiflow-pool.conf
ENV PHP_FPM_MAX_CHILDREN=16 \
    PHP_FPM_START_SERVERS=4 \
    PHP_FPM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_MAX_SPARE_SERVERS=6 \
    PHP_FPM_MAX_REQUESTS=500
# A pool file that is not picked up must break the build, not production.
RUN set -eu; \
    conf="$(php-fpm -tt 2>&1)"; \
    for setting in 'pm = dynamic' 'pm.max_children = 16' 'pm.start_servers = 4' 'pm.min_spare_servers = 2' 'pm.max_spare_servers = 6' 'pm.max_requests = 500'; do \
        printf '%s\n' "$conf" | grep -q "$setting\$" || { echo "php-fpm pool setting not applied: $setting" >&2; exit 1; }; \
    done

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
