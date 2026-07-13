#!/usr/bin/env bash
# Reproducible production deploy for LexiFlow Pro.
# Usage: ./deploy.sh [--skip-migrate]

set -euo pipefail

SKIP_MIGRATE=0
for arg in "$@"; do
    case "$arg" in
        --skip-migrate) SKIP_MIGRATE=1 ;;
        *) echo "Unknown flag: $arg" >&2; exit 1 ;;
    esac
done

COMPOSE="docker compose -f docker-compose.prod.yml --env-file .env"

echo "==> Pulling latest source with fast-forward only"
git pull --ff-only

echo "==> Building immutable application and nginx images"
$COMPOSE build --pull app nginx

echo "==> Starting database and Redis"
$COMPOSE up -d postgres redis

echo "==> Checking the TLS certificate"
if ! $COMPOSE run --rm --no-deps --entrypoint sh certbot -c \
    'test -s "/etc/letsencrypt/live/$APP_DOMAIN/fullchain.pem"'; then
    echo "==> No certificate found; requesting the initial certificate"
    $COMPOSE stop nginx || true
    $COMPOSE run --rm --no-deps --publish 80:80 --entrypoint sh certbot -c \
        ': "${APP_DOMAIN:?Set APP_DOMAIN in .env}"; : "${CERTBOT_EMAIL:?Set CERTBOT_EMAIL in .env}"; exec certbot certonly --standalone --non-interactive --agree-tos --no-eff-email --email "$CERTBOT_EMAIL" -d "$APP_DOMAIN"'
fi

if [ "$SKIP_MIGRATE" -eq 0 ]; then
    echo "==> Running migrations before switching application containers"
    $COMPOSE run --rm app php artisan migrate --force
fi

echo "==> Starting / updating the production stack"
$COMPOSE up -d --remove-orphans

echo "==> Verifying the application container"
$COMPOSE exec -T app php artisan about --only=environment

DOMAIN="$($COMPOSE run --rm --no-deps --entrypoint sh certbot -c 'printf "%s" "$APP_DOMAIN"')"
echo "==> Running HTTPS smoke check"
curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-all-errors \
    "https://${DOMAIN}/up" >/dev/null

echo "==> Done: https://${DOMAIN}/up"
