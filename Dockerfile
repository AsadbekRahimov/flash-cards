FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        bash git curl icu-dev oniguruma-dev libzip-dev libpng-dev \
        postgresql-dev linux-headers autoconf g++ make \
    && docker-php-ext-install -j"$(nproc)" \
        pdo pdo_pgsql bcmath intl mbstring opcache pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -g ${GID} app \
    && adduser -D -u ${UID} -G app -s /bin/bash app

WORKDIR /var/www/html

USER app

CMD ["php-fpm"]
