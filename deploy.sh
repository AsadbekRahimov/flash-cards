#!/usr/bin/env bash
# Reproducible production deploy for LexiFlow Pro.
# Usage: ./deploy.sh [--skip-migrate] [--rollback] [<commit-sha>]
#   <commit-sha> (or env DEPLOY_SHA) pins the deploy to exactly that commit (CI passes the SHA it
#   tested). A regular deploy only moves forward: a SHA that is not a descendant of the current HEAD
#   (e.g. an older CI run finishing after a newer one) is skipped with exit 0.
#   Without a SHA the script checks out the tip of origin/$DEPLOY_BRANCH (default master) detached, with a warning.
#   Rollback: ./deploy.sh --rollback <previous-sha> (or ROLLBACK=1), the only way to go backwards
#   (see docs/DEVOPS_GUIDE.md, "Откат").

set -euo pipefail

SKIP_MIGRATE=0
DEPLOY_SHA="${DEPLOY_SHA:-}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-master}"
ROLLBACK="${ROLLBACK:-0}"
for arg in "$@"; do
    case "$arg" in
        --skip-migrate) SKIP_MIGRATE=1 ;;
        --rollback) ROLLBACK=1 ;;
        -*) echo "Unknown flag: $arg" >&2; exit 1 ;;
        *) DEPLOY_SHA="$arg" ;;
    esac
done

if [ -n "$DEPLOY_SHA" ] && ! [[ "$DEPLOY_SHA" =~ ^[0-9a-fA-F]{7,40}$ ]]; then
    echo "ERROR: '$DEPLOY_SHA' is not a valid commit SHA" >&2
    exit 1
fi

COMPOSE="docker compose -f docker-compose.prod.yml --env-file .env"

if [ -n "$DEPLOY_SHA" ]; then
    echo "==> Checking out the exact commit ${DEPLOY_SHA}"
    git fetch origin
    if [ "$ROLLBACK" != "1" ] && git cat-file -e "${DEPLOY_SHA}^{commit}" 2>/dev/null \
        && ! git merge-base --is-ancestor HEAD "$DEPLOY_SHA"; then
        echo "SHA ${DEPLOY_SHA} is older than (or diverged from) the current HEAD $(git rev-parse --short HEAD): skipping. Use --rollback to go back deliberately."
        exit 0
    fi
    git checkout --detach "$DEPLOY_SHA"
    if [ "$(git rev-parse HEAD)" != "$(git rev-parse "${DEPLOY_SHA}^{commit}")" ]; then
        echo "ERROR: HEAD ($(git rev-parse HEAD)) is not the requested commit ${DEPLOY_SHA}; aborting before build" >&2
        exit 1
    fi
else
    echo "WARNING: no commit SHA given (argument or DEPLOY_SHA): deploying the tip of origin/${DEPLOY_BRANCH}," >&2
    echo "         which may not be the commit that passed CI." >&2
    git fetch origin
    git checkout --detach "origin/${DEPLOY_BRANCH}"
fi

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
BASE="https://${DOMAIN}"
CURL="curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-all-errors"

smoke_fail() {
    echo "ERROR: smoke check failed: $1" >&2
    echo "       Roll back: ./deploy.sh --rollback <previous-sha>  (see docs/DEVOPS_GUIDE.md, \"Откат\"; migrations must be backward compatible)" >&2
    exit 1
}

echo "==> Running HTTPS smoke check"
$CURL "${BASE}/up" >/dev/null || smoke_fail "${BASE}/up"

# /health/ready needs the scheduler and worker heartbeats (written once a minute), so give them time
# to appear after the containers restart: up to ~5 minutes.
echo "==> Waiting for /health/ready (DB, Redis, scheduler, queue workers)"
READY=0
for _ in $(seq 1 30); do
    if curl --fail --silent --max-time 10 "${BASE}/health/ready" >/dev/null; then
        READY=1
        break
    fi
    sleep 10
done
[ "$READY" -eq 1 ] || smoke_fail "${BASE}/health/ready did not become ready: $(curl --silent --max-time 10 "${BASE}/health/ready" || true)"

echo "==> Checking static assets"
for path in /twa/index.html /build/manifest.json; do
    $CURL --max-time 10 "${BASE}${path}" >/dev/null || smoke_fail "${BASE}${path}"
done

echo "==> Done: ${BASE} (commit $(git rev-parse --short HEAD))"
