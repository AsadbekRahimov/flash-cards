FROM node:20-alpine AS frontend

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

FROM php:8.3-fpm-alpine AS app

ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apk add --no-cache \
        bash curl icu-dev libzip-dev oniguruma-dev postgresql-dev postgresql-client \
        freetype-dev libpng-dev \
        linux-headers autoconf g++ make \
    && docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo pdo_pgsql bcmath intl mbstring opcache pcntl zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/* /var/cache/apk/*

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

FROM nginx:1.27-alpine AS nginx

COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx-entrypoint.sh /usr/local/bin/lexiflow-nginx-entrypoint

RUN chmod +x /usr/local/bin/lexiflow-nginx-entrypoint

ENTRYPOINT ["/usr/local/bin/lexiflow-nginx-entrypoint"]
