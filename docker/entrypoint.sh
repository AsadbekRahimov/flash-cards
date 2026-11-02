#!/bin/sh

set -eu

mkdir -p \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
    # Filament: cached panel components and Blade icons (docs/20 §1.12). Production only - in development the cache would
    # hide new resources/widgets; `make cache-clear` drops it.
    php artisan filament:optimize --no-interaction
    php artisan event:cache --no-interaction
fi

exec "$@"
