#!/bin/sh

set -eu

mkdir -p \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

# APP_SURFACE (App\Support\Surface): `full` (default, the Dockerfile ENV) or `api` - the `app-api` container, which serves
# /api/* only and registers no Filament. The caches below are built in THIS container with its surface, so the api container
# gets api-only route and provider caches; bootstrap/cache is not a volume, nothing is shared between the two.
SURFACE="${APP_SURFACE:-full}"

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    if [ "$SURFACE" != "api" ]; then
        # Not on the api surface: Blade resolves `<x-filament::...>` tags to classes when it COMPILES a template, so
        # resources/views/filament/** cannot be compiled where Filament is not registered - and the api surface renders no
        # Blade of its own (JSON; the framework's error pages compile on first use). `filament:optimize` is a command of
        # Filament: cached panel components and Blade icons (docs/20 §1.12). Production only - in development the cache
        # would hide new resources/widgets; `make cache-clear` drops it.
        php artisan view:cache --no-interaction
        php artisan filament:optimize --no-interaction
    fi
    php artisan event:cache --no-interaction
fi

exec "$@"
