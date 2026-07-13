#!/bin/sh

set -eu

envsubst '$APP_DOMAIN' \
    < /etc/nginx/prod.conf.template \
    > /etc/nginx/conf.d/default.conf

while :; do
    sleep 6h
    nginx -s reload
done &

exec nginx -g 'daemon off;'
