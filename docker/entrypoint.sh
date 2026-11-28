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
# Normalised the way Surface::current() reads it (trimmed, lower case), so `API` means the same here and in PHP.
SURFACE="$(printf '%s' "${APP_SURFACE:-full}" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"
SURFACE="${SURFACE:-full}"

if [ "${APP_ENV:-production}" = "production" ]; then
    # Compiled Blade views per container, not on the shared `app_storage` volume: every application container of a stack
    # (app, the queues, the scheduler, migrate) runs `view:cache` at start, which first empties the directory and then
    # compiles into it - on one shared directory one container deletes the half-written files of another (`rename(...): No
    # such file or directory`, the failed Coolify deploy of 28.11.2026). bootstrap/cache is not a volume and belongs to
    # `app`. Set before config:cache: the path is baked into the config cache, so php-fpm and Octane read it from there.
    # Production only - in development the views stay in storage/framework/views (the bind-mounted tree, Tailwind's @source).
    export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-/var/www/html/bootstrap/cache/views}"
    mkdir -p "$VIEW_COMPILED_PATH"
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    if [ "$SURFACE" != "api" ]; then
        # Not on the api surface: Blade resolves `<x-filament::...>` tags to classes when it COMPILES a template, so
        # resources/views/filament/** cannot be compiled where Filament is not registered - and the api surface renders no
        # Blade of its own (JSON; the framework's error pages compile on first use). The Filament optimize command is
        # Filament's own: cached panel components and Blade icons (docs/20 §1.12). Production only - in development the
        # cache would hide new resources/widgets; `make cache-clear` drops it.
        php artisan view:cache --no-interaction
        php artisan filament:optimize --no-interaction
    fi
    php artisan event:cache --no-interaction
fi

exec "$@"
