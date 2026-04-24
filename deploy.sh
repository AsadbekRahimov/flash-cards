#!/usr/bin/env bash
# Production deploy for LexiFlow Pro.
#
# Usage: ./deploy.sh [--skip-twa] [--skip-migrate]
#
# Assumes:
#   - Current host has .env configured (chmod 600).
#   - docker-compose.prod.yml is the active compose file.
#   - The app image is already built (via `make prod-build` or CI).
#
# This script is intentionally idempotent: running it twice in a row is safe.

set -euo pipefail

SKIP_TWA=0
SKIP_MIGRATE=0
for arg in "$@"; do
    case "$arg" in
        --skip-twa) SKIP_TWA=1 ;;
        --skip-migrate) SKIP_MIGRATE=1 ;;
        *) echo "Unknown flag: $arg" >&2; exit 1 ;;
    esac
done

COMPOSE="docker compose -f docker-compose.prod.yml --env-file .env"
EXEC_APP="$COMPOSE exec -T app"

echo "==> Pulling latest source"
git fetch --all --tags
git reset --hard "@{upstream}"

echo "==> Building app image"
$COMPOSE build --pull app

if [ "$SKIP_TWA" -eq 0 ]; then
    echo "==> Building TWA SPA (resources/twa → public/twa)"
    (cd resources/twa && npm ci --omit=dev && npm run build)
fi

echo "==> Starting / updating containers"
$COMPOSE up -d --remove-orphans

echo "==> Waiting for app to become healthy"
sleep 3

if [ "$SKIP_MIGRATE" -eq 0 ]; then
    echo "==> Running migrations"
    $EXEC_APP php artisan migrate --force
fi

echo "==> Caching config / routes / views"
$EXEC_APP php artisan config:cache
$EXEC_APP php artisan route:cache
$EXEC_APP php artisan view:cache
$EXEC_APP php artisan event:cache

echo "==> Restarting queue worker to pick up new code"
$COMPOSE restart queue scheduler

echo "==> Reloading nginx (if certs were renewed by certbot sidecar)"
$COMPOSE exec -T nginx nginx -s reload || true

echo "==> Done. Smoke-check: curl -sI https://\${APP_DOMAIN}/up"
