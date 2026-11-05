#!/bin/sh
# Start of the app-api container in Octane mode (Dockerfile stage app-octane; docker/entrypoint.sh has built the caches).
# `exec`: php (artisan) is PID 1 and receives SIGTERM - Octane stops FrankenPHP after the requests in flight (compose
# stop_grace_period 30s). No --https: TLS is nginx's. The admin API of Caddy listens on the loopback only.

set -eu

exec php artisan octane:frankenphp \
    --host=0.0.0.0 \
    --port=8000 \
    --admin-host=127.0.0.1 \
    --admin-port=2019 \
    --workers="${OCTANE_WORKERS:-6}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
    --log-level="${OCTANE_LOG_LEVEL:-WARN}"
