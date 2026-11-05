#!/bin/sh

set -eu

# APP_SERVER (fpm|octane) picks the snippet for /api/ (docker/nginx/snippets/api-*.conf); the snippets are not rendered.
: "${APP_SERVER:=fpm}"
case "$APP_SERVER" in
    fpm|octane) ;;
    *) echo "APP_SERVER must be fpm or octane, got '$APP_SERVER'" >&2; exit 1 ;;
esac
export APP_SERVER

envsubst '$APP_DOMAIN $APP_SERVER' \
    < /etc/nginx/prod.conf.template \
    > /etc/nginx/conf.d/default.conf

while :; do
    sleep 6h
    nginx -s reload
done &

exec nginx -g 'daemon off;'
