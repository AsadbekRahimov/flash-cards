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

# One lock for deploy.sh and docker/postgres/upgrade.sh (.deploy.lock in the project directory): a deploy never overlaps
# a PostgreSQL data move, and two deploys never overlap each other, whatever started them. Taken before the checkout so
# the git state cannot be swapped under a running deploy either. `upgrade.sh --check` below is read-only and does not
# lock, so the check under this lock cannot block itself. Do not delete the lock file by hand.
LOCK_FILE="${DEPLOY_LOCK_FILE:-.deploy.lock}"
LOCK_WAIT="${DEPLOY_LOCK_WAIT:-300}"
command -v flock >/dev/null 2>&1 || { echo "ERROR: flock(1) is required (package util-linux)" >&2; exit 1; }
# Whoever runs first creates the file (root via sudo, the deploy user from CI): world-writable, so the other one can open
# it too - its content is informational only, the lock itself is flock(2) on the inode.
LOCK_CREATED=0
[ -e "$LOCK_FILE" ] || LOCK_CREATED=1
if ! exec 9>>"$LOCK_FILE"; then
    echo "ERROR: cannot open the lock file ${LOCK_FILE} (owner $(stat -c %U "$LOCK_FILE" 2>/dev/null || echo '?'), you are $(id -un))." >&2
    echo "       It is shared with docker/postgres/upgrade.sh: whoever created it (e.g. root running make prod-pg-upgrade) must make it" >&2
    echo "       writable for the deploy user:  sudo chmod 666 ${LOCK_FILE}" >&2
    exit 1
fi
[ "$LOCK_CREATED" -eq 0 ] || chmod 666 "$LOCK_FILE" 2>/dev/null || true
if ! flock -n 9; then
    echo "==> Waiting for the lock ${LOCK_FILE} — another deploy.sh or docker/postgres/upgrade.sh is running ($(head -n 1 "$LOCK_FILE" 2>/dev/null || true)); up to ${LOCK_WAIT}s"
    flock -w "$LOCK_WAIT" 9 || { echo "ERROR: ${LOCK_FILE} is still held after ${LOCK_WAIT}s; let the other deploy/upgrade finish, then run the deploy again" >&2; exit 1; }
fi
: > "$LOCK_FILE"
printf 'pid=%s started=%s command=deploy.sh %s\n' "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&9

# The running containers belong to the commit checked out now: docker-compose.prod.yml bind-mounts files of the working
# tree into them (docker/postgres/entrypoint-guard.sh into postgres, docker/nginx/prod.conf into nginx), and a restart of a
# container re-resolves those mounts. A deploy that stops after its checkout but before it touched the stack - a STOP of
# one of the checks below, a failed build - would leave a tree the containers do not fit; a rollback to a commit without
# the guard file would make every restart of postgres 18 fail (the mount source gone, Docker creates a directory there).
# So on such a failure the tree goes back to where it was. Two exceptions, on purpose: once the stack is touched the tree
# stays with it; and a STOP of `upgrade.sh --check` keeps the new checkout, because the way out it names (the upgrade,
# --redo / --keep-current) has to be run from that checkout.
PREV_HEAD="$(git rev-parse HEAD)"
STACK_TOUCHED=0
KEEP_CHECKOUT=0
restore_tree_on_failure() {
    local status=$1
    [ "$status" -ne 0 ] && [ "$STACK_TOUCHED" -eq 0 ] && [ "$KEEP_CHECKOUT" -eq 0 ] || return 0
    [ "$(git rev-parse HEAD 2>/dev/null)" != "$PREV_HEAD" ] || return 0
    echo "==> The deploy stopped before it touched the stack: checking out the previous commit ${PREV_HEAD:0:7} again, the one the running containers belong to" >&2
    git checkout --detach "$PREV_HEAD" >/dev/null 2>&1 \
        || echo "WARNING: could not check out ${PREV_HEAD:0:7}; the working tree stays at $(git rev-parse --short HEAD 2>/dev/null)" >&2
}
trap 'restore_tree_on_failure $?' EXIT

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

# PostgreSQL 18 keeps its data in a new volume and layout (docker/postgres/upgrade.sh, docs/DEVOPS_GUIDE.md §0,
# «Обновление PostgreSQL 16 → 18»). Never start 18 next to 16 data that has not been moved, and never let the
# application run on an empty database: the check runs BEFORE the build re-tags the images and before any `up`,
# and stops the deploy with the upgrade command. The running stack is left as it is.
if [ -f docker/postgres/upgrade.sh ]; then
    echo "==> Checking the PostgreSQL data volumes"
    # A STOP here is acted upon from this checkout (see restore_tree_on_failure), so the tree stays.
    bash docker/postgres/upgrade.sh --prod --check || { KEEP_CHECKOUT=1; exit 1; }
else
    # A rollback to a commit from before the PostgreSQL 18 layout: that compose still runs 16 on the old volume, read-write.
    echo "==> No docker/postgres/upgrade.sh in this commit (PostgreSQL 16 layout): skipping the data volume check"
    # Not onto an empty legacy volume, though. Once the old volume was deleted after the upgrade, compose recreates it empty,
    # PostgreSQL 16 would initialise an empty cluster, the migrations below would build an empty database and the application
    # would run on it: stop instead. `$PGDATA` is where the ≤16 images keep the cluster (/var/lib/postgresql/data); this is the
    # compose file of that old commit, so only its `postgres` service is assumed. The probe prints its finding, so a failure
    # of `compose run` itself (daemon, image, compose file) is told apart from an empty volume.
    echo "==> Checking that the legacy volume still holds a PostgreSQL cluster"
    LEGACY_PROBE="$($COMPOSE run --rm --no-deps -T --quiet-pull --entrypoint sh postgres -c \
        'if [ -s "$PGDATA/PG_VERSION" ]; then echo lexiflow-legacy-cluster=present; else echo lexiflow-legacy-cluster=missing; fi')" \
        || { echo "ERROR: could not inspect the legacy volume (docker compose run failed, see above); nothing was started" >&2; exit 1; }
    case "$LEGACY_PROBE" in
        *lexiflow-legacy-cluster=present*) ;;
        *lexiflow-legacy-cluster=missing*)
            echo "STOP: the legacy PostgreSQL volume holds no cluster (no PG_VERSION in the data directory): it was deleted after the" >&2
            echo "      upgrade and compose has just recreated it empty. Starting this commit would initialise an EMPTY database and run" >&2
            echo "      the application on it. Restore the PostgreSQL 16 data into that volume from a backup first, or deploy a commit" >&2
            echo "      with the PostgreSQL 18 layout again. Details: docs/DEVOPS_GUIDE.md §0, «Откат и его цена»." >&2
            exit 1 ;;
        *)
            echo "ERROR: could not inspect the legacy volume: unexpected output of the probe: ${LEGACY_PROBE}" >&2
            exit 1 ;;
    esac
    echo "    NOTE: this commit runs PostgreSQL 16 on the legacy volume READ-WRITE. If the stack had already moved to 18, the two copies"
    echo "    diverge from now on; the next forward deploy stops until you pick one of them (upgrade.sh --redo / --keep-current), and"
    echo "    the postgres container of the 18 compose refuses to start until then (docs/DEVOPS_GUIDE.md §0 «Откат и его цена»)."
fi

# Both Redis passwords are mandatory in production (docker-compose.prod.yml), and a .env that says `null`, `(null)`, `empty`
# or `(empty)` - what Laravel's env() reads as "no password", in any case of letters - is as bad as a missing one: compose's
# `:?` rejects only an EMPTY value, so it would start the stack, the Redis container would refuse the word and restart
# forever, and the application would come up without its cache (readiness 200 `degraded`, forever) or, for `redis`, not at
# all. Stop here, before anything is built or started. The value checked is the one compose will interpolate: the shell
# environment first, then .env (quotes and an inline comment stripped as compose strips them).
redis_password_of() {
    local name=$1 value=''
    if [ -v "$name" ]; then
        value="${!name}"
    else
        value="$(grep -E "^[[:space:]]*(export[[:space:]]+)?${name}=" .env 2>/dev/null | tail -n 1 | sed -E "s/^[[:space:]]*(export[[:space:]]+)?${name}=//")"
        case "$value" in
            \"*\") value="${value#\"}"; value="${value%\"}" ;;
            \'*\') value="${value#\'}"; value="${value%\'}" ;;
            *) value="$(printf '%s' "$value" | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//')" ;;
        esac
    fi
    printf '%s' "$value"
}
echo "==> Checking the Redis passwords"
for name in REDIS_PASSWORD REDIS_CACHE_PASSWORD; do
    value="$(redis_password_of "$name")"
    case "$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')" in
        ''|null|'(null)'|empty|'(empty)')
            echo "STOP: ${name} is empty or the word '${value}' (shell environment or .env): Laravel reads that as \"no password\", the" >&2
            echo "      Redis container refuses to start with it, and the application would run without its cache or not at all." >&2
            echo "      Set a real one in .env:  ${name}=\$(openssl rand -hex 32)   (docs/DEVOPS_GUIDE.md, \"Redis\")" >&2
            exit 1 ;;
    esac
done

echo "==> Building immutable application, nginx and pgbouncer images"
$COMPOSE build --pull app nginx pgbouncer

echo "==> Starting database, pgbouncer and both Redis instances"
STACK_TOUCHED=1
$COMPOSE up -d postgres pgbouncer redis redis-cache

echo "==> Checking the TLS certificate"
if ! $COMPOSE run --rm --no-deps --entrypoint sh certbot -c \
    'test -s "/etc/letsencrypt/live/$APP_DOMAIN/fullchain.pem"'; then
    echo "==> No certificate found; requesting the initial certificate"
    $COMPOSE stop nginx || true
    $COMPOSE run --rm --no-deps --publish 80:80 --entrypoint sh certbot -c \
        ': "${APP_DOMAIN:?Set APP_DOMAIN in .env}"; : "${CERTBOT_EMAIL:?Set CERTBOT_EMAIL in .env}"; exec certbot certonly --standalone --non-interactive --agree-tos --no-eff-email --email "$CERTBOT_EMAIL" -d "$APP_DOMAIN"'
fi

if [ "$SKIP_MIGRATE" -eq 0 ]; then
    # Straight to PostgreSQL, past pgbouncer (connection pgsql_direct = DB_DIRECT_HOST, config/database.php): a migration
    # may hold locks, run for minutes and need one session for all of it; the migrator makes that connection the default
    # while each migration runs, so Schema:: and DB:: inside migrations go there too.
    echo "==> Running migrations before switching application containers"
    $COMPOSE run --rm app php artisan migrate --force --database=pgsql_direct
fi

# courses.words_count is the `total` of GET /courses: bring it in line with the active words (idempotent and fast, it
# writes only the courses that drifted). Not on a rollback - the older code has no such command. A failure is not
# worth stopping the deploy for: it says how to run it by hand.
if [ "$ROLLBACK" != "1" ]; then
    echo "==> Recounting the words of the courses"
    $COMPOSE run --rm app php artisan courses:recount-words \
        || echo "WARNING: courses:recount-words failed; run it by hand: $COMPOSE exec app php artisan courses:recount-words" >&2
fi

echo "==> Starting / updating the production stack"
$COMPOSE up -d --remove-orphans

echo "==> Verifying the application containers"
$COMPOSE exec -T app php artisan about --only=environment
# The api container (APP_SURFACE=api, docs/DEVOPS_GUIDE.md §0 «Две поверхности приложения») must carry no panel routes and
# must have the TWA ones: a wrong APP_SURFACE would silently serve the full application to the learners.
API_ROUTES="$($COMPOSE exec -T app-api php artisan route:list --json)"
case "$API_ROUTES" in
    *'"api/twa/me"'*) ;;
    *) echo "ERROR: app-api has no route api/twa/me; its route list: ${API_ROUTES}" >&2; exit 1 ;;
esac
case "$API_ROUTES" in
    *'"filament.'*|*'"admin"'*|*'"admin/'*) echo "ERROR: app-api serves panel routes - APP_SURFACE of the app-api service is not 'api'" >&2; exit 1 ;;
esac

# Informational: the PostgreSQL settings the server really runs with (docker-compose.prod.yml, PG_* in .env). Never fails the deploy.
echo "==> PostgreSQL settings in effect"
$COMPOSE exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tA' <<'SQL' \
    || echo "WARNING: could not read the PostgreSQL settings (informational only)" >&2
SELECT name || '=' || setting || coalesce(unit, '') FROM pg_settings
WHERE name IN ('shared_buffers', 'effective_cache_size', 'work_mem', 'jit', 'random_page_cost', 'max_wal_size', 'synchronous_commit')
ORDER BY name;
SQL

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
# Through nginx to app-api: an unauthenticated TWA call is a 401 JSON from Laravel (anything else - 502 with app-api down,
# 404 from the wrong upstream, 500 - fails). `--retry-all-errors` does not retry a 401, so the loop is explicit.
API_CODE=''
for _ in $(seq 1 10); do
    API_CODE="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 "${BASE}/api/twa/me" || true)"
    [ "$API_CODE" = "401" ] && break
    sleep 2
done
[ "$API_CODE" = "401" ] || smoke_fail "${BASE}/api/twa/me answered ${API_CODE:-nothing}, expected 401 from the app-api container"

# /health/ready needs the scheduler and worker heartbeats (written once a minute), so give them time
# to appear after the containers restart: up to ~5 minutes.
echo "==> Waiting for /health/ready (DB, Redis, scheduler, queue workers)"
READY=0
READY_BODY=''
for _ in $(seq 1 30); do
    if READY_BODY="$(curl --fail --silent --max-time 10 "${BASE}/health/ready")"; then
        READY=1
        break
    fi
    sleep 10
done
[ "$READY" -eq 1 ] || smoke_fail "${BASE}/health/ready did not become ready: $(curl --silent --max-time 10 "${BASE}/health/ready" || true)"

# 200 with `degraded` is ready - the application serves - but without something it works around: the second Redis instance
# (the pure cache, docs/19 §4.1), with PostgreSQL carrying the load until it is back. The deploy is not blocked; the
# operator must see it in the output, not only in the monitor.
case "$READY_BODY" in
    *'"status":"degraded"'*|*'"status": "degraded"'*)
        echo "WARNING: /health/ready answers 200 but DEGRADED: ${READY_BODY}" >&2
        echo "         A dependency the application works without is down - usually redis-cache (the pure cache): the application" >&2
        echo "         computes from the database meanwhile. Check:  $COMPOSE ps redis-cache ; $COMPOSE logs --tail=50 redis-cache" >&2
        echo "         (docs/DEVOPS_GUIDE.md §15.2; an alert fires when it lasts longer than 10 minutes, §15.3)" >&2 ;;
esac

echo "==> Checking static assets"
for path in /twa/index.html /build/manifest.json; do
    $CURL --max-time 10 "${BASE}${path}" >/dev/null || smoke_fail "${BASE}${path}"
done

echo "==> Done: ${BASE} (commit $(git rev-parse --short HEAD))"
