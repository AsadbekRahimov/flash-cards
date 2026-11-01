#!/usr/bin/env bash
# PostgreSQL major upgrade for the LexiFlow Pro Docker stack: the 16 cluster in the legacy volume
# (key `pgdata`, mounted at /var/lib/postgresql/data) is dumped and restored into the 18+ layout
# (one volume mounted at /var/lib/postgresql, cluster in <major>/docker). See docs/DEVOPS_GUIDE.md §0,
# «Обновление PostgreSQL 16 → 18 (Docker)».
#
# Usage:
#   bash docker/postgres/upgrade.sh [--prod] [--check] [-- <docker compose options>]
#
#   (no flags)   dev stack: `docker compose` (docker-compose.yml + docker-compose.override.yml, .env)
#   --prod       production stack: `docker compose -f docker-compose.prod.yml --env-file .env`
#   --check      report the state of the volumes and exit 0 (safe to start) / 1 (the deploy must stop)
#                without changing anything; deploy.sh runs this before build/up
#   -- ...       pass custom `docker compose` options instead of the defaults (tests, odd layouts),
#                e.g. `-- -p mystack -f docker-compose.yml -f extra.yml --env-file .env.test`
#
# What the upgrade does (idempotent, re-runnable after any failure):
#   1. stops every service of the stack except postgres and redis (the writers), then the old postgres;
#   2. copies the legacy volume into a temporary volume and starts the matching official
#      postgres:<old major>-alpine on the COPY (the legacy volume itself is never written);
#   3. starts the stack's new postgres (18+ layout) and streams `pg_dump` (new client) into
#      `pg_restore --single-transaction` — the target is either fully restored or left empty;
#   4. compares row counts of every table, sequences, indexes and constraints between the two clusters;
#   5. runs `vacuumdb --analyze-in-stages`, writes a marker into the new volume, removes the temporaries;
#   6. starts the services it stopped.
#   The legacy volume stays as the rollback copy until the owner deletes it by hand.
#
# Environment:
#   PG_UPGRADE_SOURCE_IMAGE  image for the old cluster (default postgres:<old major>-alpine)
#   PG_UPGRADE_TIMEOUT       seconds to wait for a server to become ready (default 180)

set -Eeuo pipefail

LEGACY_VOLUME_KEY='pgdata'                # compose volume key used by the ≤16 stack
NEW_MOUNT='/var/lib/postgresql'           # 18+ images: single mount, cluster in <major>/docker
MARKER_DONE='lexiflow-upgrade.done'       # written into the root of the new volume after a verified upgrade
MARKER_WIP='lexiflow-upgrade.in-progress' # present while an upgrade runs or after it was interrupted
LABEL='lexiflow.pg-upgrade=1'             # every temporary container/volume this script creates
WAIT_TIMEOUT="${PG_UPGRADE_TIMEOUT:-180}"

MODE='dev'
CHECK_ONLY=0
COMPOSE=()
while [ $# -gt 0 ]; do
    case "$1" in
        --prod) MODE='prod' ;;
        --check) CHECK_ONLY=1 ;;
        -h|--help) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        --) shift; COMPOSE=(docker compose "$@"); MODE='custom'; break ;;
        *) echo "Unknown argument: $1 (see --help)" >&2; exit 2 ;;
    esac
    shift
done
if [ "${#COMPOSE[@]}" -eq 0 ]; then
    case "$MODE" in
        prod) COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env) ;;
        *) COMPOSE=(docker compose) ;;
    esac
fi
case "$MODE" in
    prod) UPGRADE_CMD='make prod-pg-upgrade   # = bash docker/postgres/upgrade.sh --prod' ;;
    dev) UPGRADE_CMD='make pg-upgrade   # = bash docker/postgres/upgrade.sh' ;;
    *) UPGRADE_CMD="bash docker/postgres/upgrade.sh -- ${COMPOSE[*]:2}" ;;
esac

log() { printf '==> %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 2; }
compose() { "${COMPOSE[@]}" "$@"; }

# ---------------------------------------------------------------------------------------------------
# docker compose config (canonical YAML) → project name, image, volumes, environment
# ---------------------------------------------------------------------------------------------------
CONFIG_YAML=''
load_config() {
    CONFIG_YAML="$(compose config 2>/dev/null)" || die "'${COMPOSE[*]} config' failed; run it by hand to see why"
    [ -n "$CONFIG_YAML" ] || die "'${COMPOSE[*]} config' printed nothing"
}

unquote() {
    local v="$1"
    case "$v" in
        \"*\") v="${v#\"}"; v="${v%\"}" ;;
        \'*\') v="${v#\'}"; v="${v%\'}" ;;
    esac
    printf '%s' "$v"
}

# Scalar at a mapping path of the compose config, e.g. `cfg_get services postgres image`.
cfg_get() {
    local val
    val="$(awk -v path="$*" '
        BEGIN { n = split(path, want, " "); depth = 0 }
        /^[[:space:]]*$/ { next }
        /^[[:space:]]*#/ { next }
        {
            match($0, /^ */); indent = RLENGTH / 2
            line = substr($0, RLENGTH + 1)
            if (indent < depth) exit
            if (line ~ /^- /) next
            key = line; sub(/:.*$/, "", key)
            if (indent == depth && key == want[depth + 1]) {
                depth++
                if (depth == n) { sub(/^[^:]*:[[:space:]]*/, "", line); print line; exit }
            }
        }' <<<"$CONFIG_YAML")"
    unquote "$val"
}

# Volume mounts of a service: one "source<TAB>target" line per mount (long or short compose syntax).
cfg_service_mounts() {
    awk -v svc="$1" '
        function flush() { if (src != "") print src "\t" tgt; src = ""; tgt = "" }
        /^[^ ]/ { top = $0; next }
        top == "services:" && /^  [^ ]/ { cur = $0; sub(/^  /, "", cur); sub(/:$/, "", cur); inlist = 0; next }
        top == "services:" && cur == svc && /^    volumes:$/ { inlist = 1; next }
        inlist && /^    [^ ]/ { inlist = 0 }
        inlist && /^      - / {
            flush()
            item = substr($0, 9)
            if (item ~ /^[^ :]+:\//) { split(item, a, ":"); src = a[1]; tgt = a[2] }
        }
        inlist && /^        source: / { src = $2 }
        inlist && /^        target: / { tgt = $2 }
        END { flush() }' <<<"$CONFIG_YAML"
}

PROJECT=''; IMAGE=''; IMAGE_MAJOR=''; NEW_KEY=''; NEW_VOL=''; DB_USER=''; DB_NAME=''
resolve_stack() {
    load_config
    PROJECT="$(cfg_get name)"
    if [ -z "$PROJECT" ]; then
        PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-\n' '_')}"
        warn "compose config has no top-level 'name'; assuming project '$PROJECT' (upgrade docker compose to be sure)"
    fi
    IMAGE="$(cfg_get services postgres image)"
    [ -n "$IMAGE" ] || die "service 'postgres' has no image in '${COMPOSE[*]} config'"
    DB_USER="$(cfg_get services postgres environment POSTGRES_USER)"
    DB_NAME="$(cfg_get services postgres environment POSTGRES_DB)"
    [ -n "$DB_USER" ] && [ -n "$DB_NAME" ] || die "POSTGRES_USER / POSTGRES_DB of service 'postgres' are not set (DB_USERNAME / DB_DATABASE in .env)"

    local src tgt
    while IFS=$'\t' read -r src tgt; do
        if [ "$tgt" = "$NEW_MOUNT" ]; then NEW_KEY="$src"; fi
        if [ "$tgt" = "$NEW_MOUNT/data" ]; then
            die "service 'postgres' still mounts '$src' at $NEW_MOUNT/data (the ≤16 layout); the 18+ stack mounts one volume at $NEW_MOUNT"
        fi
    done < <(cfg_service_mounts postgres)
    [ -n "$NEW_KEY" ] || die "service 'postgres' has no volume mounted at $NEW_MOUNT"
    NEW_VOL="$(cfg_get volumes "$NEW_KEY" name)"
    [ -n "$NEW_VOL" ] || die "cannot resolve the real name of compose volume '$NEW_KEY'"

    local re='(^|/)postgres:([0-9]+)([.-]|$)'
    if [[ "$IMAGE" =~ $re ]]; then
        IMAGE_MAJOR="${BASH_REMATCH[2]}"
    else
        IMAGE_MAJOR="$(docker run --rm --entrypoint /bin/sh "$IMAGE" -c 'printf %s "$PG_MAJOR"')" \
            || die "cannot determine PG_MAJOR of image $IMAGE"
    fi
    [ -n "$IMAGE_MAJOR" ] || die "cannot determine the PostgreSQL major of image $IMAGE"
}

# ---------------------------------------------------------------------------------------------------
# Volumes
# ---------------------------------------------------------------------------------------------------
volume_exists() { docker volume inspect "$1" >/dev/null 2>&1; }

# The ≤16 volume: created by compose as <project>_pgdata (found by its compose labels, then by name).
find_legacy_volume() {
    local found
    found="$(docker volume ls -q \
        --filter "label=com.docker.compose.project=$PROJECT" \
        --filter "label=com.docker.compose.volume=$LEGACY_VOLUME_KEY" | head -n 1)"
    if [ -z "$found" ] && volume_exists "${PROJECT}_${LEGACY_VOLUME_KEY}"; then
        found="${PROJECT}_${LEGACY_VOLUME_KEY}"
    fi
    printf '%s' "$found"
}

# Facts about a volume without starting PostgreSQL (read-only mount, one `cat`-only container):
#   root=<major>    PG_VERSION in the volume root  (≤16 layout mounted at /var/lib/postgresql/data)
#   data=<major>    PG_VERSION in ./data           (≤16 layout mounted at /var/lib/postgresql)
#   docker=<major>  PG_VERSION in ./<major>/docker (18+ layout)
#   done / wip      markers of this script; empty=1 when the volume has no files at all
inspect_volume() {
    docker run --rm --entrypoint /bin/sh -v "$1:/v:ro" "$IMAGE" -c '
        [ -s /v/PG_VERSION ] && printf "root=%s\n" "$(cat /v/PG_VERSION)"
        [ -s /v/data/PG_VERSION ] && printf "data=%s\n" "$(cat /v/data/PG_VERSION)"
        for f in /v/*/docker/PG_VERSION; do [ -s "$f" ] && printf "docker=%s\n" "$(cat "$f")"; done
        [ -s /v/'"$MARKER_DONE"' ] && printf "done=%s\n" "$(head -n 1 /v/'"$MARKER_DONE"')"
        [ -e /v/'"$MARKER_WIP"' ] && echo "wip=1"
        [ -z "$(ls -A /v)" ] && echo "empty=1"
        exit 0'
}
fact() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -n 1; }

OLD_VOL=''; OLD_MAJOR=''; OLD_FACTS=''
NEW_FACTS=''; NEW_MAJOR=''; NEW_EXISTS=0
inspect_stack() {
    OLD_VOL="$(find_legacy_volume)"
    if [ -n "$OLD_VOL" ] && [ "$OLD_VOL" != "$NEW_VOL" ]; then
        OLD_FACTS="$(inspect_volume "$OLD_VOL")"
        OLD_MAJOR="$(fact "$OLD_FACTS" root)"
    fi
    if volume_exists "$NEW_VOL"; then
        NEW_EXISTS=1
        NEW_FACTS="$(inspect_volume "$NEW_VOL")"
        NEW_MAJOR="$(fact "$NEW_FACTS" docker)"
    fi
}

describe_state() {
    info "project: $PROJECT   postgres image: $IMAGE (major $IMAGE_MAJOR)"
    if [ -z "$OLD_VOL" ]; then
        info "legacy volume (${LEGACY_VOLUME_KEY}): none"
    elif [ "$OLD_VOL" = "$NEW_VOL" ]; then
        info "legacy volume (${LEGACY_VOLUME_KEY}): $OLD_VOL — the same volume is mounted at $NEW_MOUNT"
    elif [ -n "$OLD_MAJOR" ]; then
        info "legacy volume (${LEGACY_VOLUME_KEY}): $OLD_VOL — PostgreSQL $OLD_MAJOR cluster (≤16 layout)"
    else
        info "legacy volume (${LEGACY_VOLUME_KEY}): $OLD_VOL — no cluster inside"
    fi
    local legacy_inside
    legacy_inside="$(fact "$NEW_FACTS" root)"
    [ -n "$legacy_inside" ] || legacy_inside="$(fact "$NEW_FACTS" data)"
    if [ "$NEW_EXISTS" -eq 0 ]; then
        info "current volume (${NEW_KEY}): $NEW_VOL — does not exist yet"
    elif [ -n "$NEW_MAJOR" ]; then
        info "current volume (${NEW_KEY}): $NEW_VOL — PostgreSQL $NEW_MAJOR cluster ($NEW_MAJOR/docker)"
    elif [ -n "$legacy_inside" ]; then
        info "current volume (${NEW_KEY}): $NEW_VOL — PostgreSQL $legacy_inside files in the ≤16 layout (unusable for 18+)"
    elif [ -n "$(fact "$NEW_FACTS" empty)" ]; then
        info "current volume (${NEW_KEY}): $NEW_VOL — empty"
    else
        info "current volume (${NEW_KEY}): $NEW_VOL — no cluster inside"
    fi
    local done; done="$(fact "$NEW_FACTS" done)"
    [ -z "$done" ] || info "upgrade marker: $done"
    [ -z "$(fact "$NEW_FACTS" wip)" ] || info "upgrade marker: IN PROGRESS / INTERRUPTED"
}

# ---------------------------------------------------------------------------------------------------
# --check: the guard used by deploy.sh (exit 0 = safe to start the stack, 1 = stop the deploy)
# ---------------------------------------------------------------------------------------------------
stop_deploy() {
    printf '\nSTOP: %s\n' "$1" >&2
    shift
    for line in "$@"; do printf '      %s\n' "$line" >&2; done
    printf '      Details: docs/DEVOPS_GUIDE.md, «Обновление PostgreSQL 16 → 18 (Docker)».\n' >&2
    exit 1
}

run_check() {
    resolve_stack
    inspect_stack
    log "PostgreSQL volumes of the stack"
    describe_state

    if [ -n "$(fact "$NEW_FACTS" wip)" ]; then
        stop_deploy "a PostgreSQL upgrade is in progress or was interrupted (marker $MARKER_WIP in volume $NEW_VOL)." \
            "Finish it before deploying:  $UPGRADE_CMD"
    fi
    if [ -n "$(fact "$NEW_FACTS" root)" ] || [ -n "$(fact "$NEW_FACTS" data)" ]; then
        stop_deploy "volume $NEW_VOL holds a cluster in the ≤16 layout; postgres $IMAGE_MAJOR refuses to start on it." \
            "The 18+ stack expects the cluster in $NEW_MOUNT/<major>/docker. Move the data with:  $UPGRADE_CMD"
    fi
    if [ -n "$OLD_MAJOR" ] && [ -z "$NEW_MAJOR" ]; then
        local where='does not exist'
        [ "$NEW_EXISTS" -eq 0 ] || where='is empty'
        stop_deploy "the PostgreSQL $OLD_MAJOR data in volume $OLD_VOL has not been moved to the 18+ layout (volume $NEW_VOL $where)." \
            "Starting $IMAGE now would initialise an EMPTY database and the application would run on it." \
            "Run the upgrade first (stops the app, copies $OLD_MAJOR → $IMAGE_MAJOR, verifies, starts the app again):" \
            "    $UPGRADE_CMD" \
            "then run the deploy again. The legacy volume is kept untouched as the rollback copy."
    fi
    if [ -n "$NEW_MAJOR" ] && [ "$NEW_MAJOR" != "$IMAGE_MAJOR" ]; then
        stop_deploy "volume $NEW_VOL holds a PostgreSQL $NEW_MAJOR cluster but the compose file asks for $IMAGE (major $IMAGE_MAJOR)." \
            "The image refuses to start on another major; a $NEW_MAJOR → $IMAGE_MAJOR upgrade is needed before deploying (see the docs)."
    fi

    if [ -n "$OLD_MAJOR" ] && [ -n "$NEW_MAJOR" ]; then
        log "OK: PostgreSQL $NEW_MAJOR data is in place; the legacy PostgreSQL $OLD_MAJOR volume $OLD_VOL is the rollback copy"
        info "Delete it only once the upgrade has proven itself:  docker volume rm $OLD_VOL"
    elif [ -n "$NEW_MAJOR" ]; then
        log "OK: PostgreSQL $NEW_MAJOR data is in place"
    else
        log "OK: no PostgreSQL data yet — a fresh install, the stack will initialise an empty cluster"
    fi
    exit 0
}

# ---------------------------------------------------------------------------------------------------
# Upgrade helpers
# ---------------------------------------------------------------------------------------------------
STEP='preflight'
SRC_IMAGE=''; SRC_ROLE=''; TMP_VOL=''; SOCK_VOL=''; SRC_CTR=''
WORK=''
STOPPED=()          # services stopped by this run (or a previous, interrupted run)
MARKED_WIP=0

cleanup_temp() {
    if [ -n "$SRC_CTR" ] && docker container inspect "$SRC_CTR" >/dev/null 2>&1; then
        if [ "$(docker container inspect "$SRC_CTR" --format '{{index .Config.Labels "lexiflow.pg-upgrade"}}')" = '1' ]; then
            docker rm -f "$SRC_CTR" >/dev/null 2>&1 || true
        fi
    fi
    local v
    for v in "$TMP_VOL" "$SOCK_VOL"; do
        [ -n "$v" ] || continue
        if volume_exists "$v" && [ "$(docker volume inspect "$v" --format '{{index .Labels "lexiflow.pg-upgrade"}}')" = '1' ]; then
            docker volume rm "$v" >/dev/null 2>&1 || true
        fi
    done
    [ -z "$WORK" ] || rm -rf "$WORK"
}

ERR_CMD=''; ERR_LINE=''
on_exit() {
    local status=$1
    if [ "$status" -ne 0 ] && [ "$CHECK_ONLY" -eq 0 ]; then
        {
            printf '\nFAILED during step "%s" (exit %s).\n' "$STEP" "$status"
            [ -z "$ERR_CMD" ] || printf '  Failed command (line %s): %s\n' "$ERR_LINE" "$ERR_CMD"
            [ -z "$OLD_VOL" ] || printf '  The legacy volume %s was not modified.\n' "$OLD_VOL"
            if [ "${#STOPPED[@]}" -gt 0 ]; then
                printf '  Still stopped: %s\n' "${STOPPED[*]}"
            fi
            if [ "$MARKED_WIP" -eq 1 ]; then
                printf '  Volume %s keeps the %s marker, so deploy.sh will refuse to start the stack until the upgrade completes.\n' "$NEW_VOL" "$MARKER_WIP"
            fi
            printf '  Fix the cause and run the same command again — it resumes safely:  %s\n' "$UPGRADE_CMD"
            printf '  To stay on PostgreSQL %s instead: restore the previous compose files (prod: bash deploy.sh --rollback <prev_sha>)\n' "${OLD_MAJOR:-16}"
            printf '  and start the stack; the legacy volume is picked up again.\n'
        } >&2
    fi
    cleanup_temp
}
trap 'ERR_CMD=$BASH_COMMAND; ERR_LINE=$LINENO' ERR
trap 'on_exit $?' EXIT

ensure_image() {
    docker image inspect "$1" >/dev/null 2>&1 || docker pull "$1"
}

# Services of the stack that are running now, minus the ones that may keep running.
running_writers() {
    local known running s keep
    known="$(compose config --services 2>/dev/null)"
    running="$(compose ps --services --status running 2>/dev/null || true)"
    for s in $running; do
        keep=0
        case "$s" in postgres|redis) keep=1 ;; esac
        [ "$keep" -eq 1 ] && continue
        grep -qx -- "$s" <<<"$known" || continue   # a profile-only service (vite, ngrok): not ours to stop
        printf '%s\n' "$s"
    done
}

pg_target() {  # run a PostgreSQL client program inside the stack's postgres container
    compose exec -T postgres "$@"
}
psql_target() {  # SQL on stdin against the target cluster
    pg_target psql -v ON_ERROR_STOP=1 -X -q -At -F ' ' -U "$DB_USER" "$@"
}
psql_source() {  # SQL on stdin against the temporary source cluster, via its unix socket
    docker exec -i "$SRC_CTR" psql -v ON_ERROR_STOP=1 -X -q -At -F ' ' -U "$SRC_ROLE" "$@"
}

wait_target_ready() {
    local i
    for i in $(seq 1 "$WAIT_TIMEOUT"); do
        if [ "$(pg_target cat /proc/1/comm 2>/dev/null || true)" = 'postgres' ] \
            && pg_target pg_isready -q -U "$DB_USER" 2>/dev/null \
            && printf 'SELECT 1' | psql_target -d postgres >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    compose logs --tail 30 postgres >&2 || true
    die "the stack's postgres did not become ready in ${WAIT_TIMEOUT}s"
}

wait_source_ready() {
    local i
    for i in $(seq 1 "$WAIT_TIMEOUT"); do
        if docker exec "$SRC_CTR" pg_isready -q -U "$SRC_ROLE" 2>/dev/null \
            && printf 'SELECT 1' | psql_source -d postgres >/dev/null 2>&1; then
            return 0
        fi
        if [ "$(docker container inspect "$SRC_CTR" --format '{{.State.Running}}' 2>/dev/null)" != 'true' ]; then
            docker logs --tail 30 "$SRC_CTR" >&2 || true
            die "the temporary PostgreSQL $OLD_MAJOR server exited"
        fi
        sleep 1
    done
    docker logs --tail 30 "$SRC_CTR" >&2 || true
    die "the temporary PostgreSQL $OLD_MAJOR server did not become ready in ${WAIT_TIMEOUT}s"
}

# Row counts of every user table, sequences, indexes and constraints — the fingerprint compared
# between the clusters. Exact counts (count(*)), not pg_class estimates.
SQL_FINGERPRINT=$(cat <<'SQL'
SELECT 'table', n.nspname, c.relname,
       (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I.%I', n.nspname, c.relname), false, true, '')))[1]::text
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%'
UNION ALL
SELECT 'sequence', schemaname, sequencename, coalesce(last_value::text, 'never-used') FROM pg_sequences
UNION ALL
SELECT 'index', schemaname, tablename, indexname
FROM pg_indexes WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
UNION ALL
SELECT 'constraint', n.nspname, c.conrelid::regclass::text, c.conname || ' ' || c.contype::text
FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND c.contype <> 'n'  -- 18+ lists NOT NULL in pg_constraint, 16 does not
ORDER BY 1, 2, 3, 4;
SQL
)
SQL_IS_EMPTY=$(cat <<'SQL'
SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p', 'S', 'v', 'm', 'f') AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%';
SQL
)

write_wip_marker() {
    {
        printf 'started=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf 'from=%s %s\n' "$OLD_MAJOR" "$OLD_VOL"
        printf 'to=%s %s\n' "$IMAGE_MAJOR" "$NEW_VOL"
        printf 'stopped_services=%s\n' "${STOPPED[*]:-}"
    } | pg_target /bin/sh -c "cat > $NEW_MOUNT/$MARKER_WIP && chmod 644 $NEW_MOUNT/$MARKER_WIP"
    MARKED_WIP=1
}

# ---------------------------------------------------------------------------------------------------
# Upgrade
# ---------------------------------------------------------------------------------------------------
run_upgrade() {
    resolve_stack
    inspect_stack
    log "PostgreSQL volumes of the stack"
    describe_state

    local done; done="$(fact "$NEW_FACTS" done)"
    if [ -n "$done" ]; then
        log "Nothing to do: the upgrade has already completed ($done)"
        [ -z "$OLD_MAJOR" ] || info "The legacy volume $OLD_VOL is the rollback copy; delete it when you no longer need it:  docker volume rm $OLD_VOL"
        exit 0
    fi
    if [ -n "$OLD_VOL" ] && [ "$OLD_VOL" = "$NEW_VOL" ]; then
        die "the legacy volume is mounted at $NEW_MOUNT; give the 18+ stack a new volume (see docker-compose*.yml)"
    fi
    if [ -n "$(fact "$NEW_FACTS" root)" ] || [ -n "$(fact "$NEW_FACTS" data)" ]; then
        die "volume $NEW_VOL contains a cluster in the ≤16 layout; this script only reads the legacy volume '$LEGACY_VOLUME_KEY'"
    fi
    if [ -z "$OLD_MAJOR" ]; then
        log "Nothing to upgrade: no PostgreSQL data in the legacy volume"
        if [ -n "$NEW_MAJOR" ]; then
            info "The stack already runs on PostgreSQL $NEW_MAJOR data in $NEW_VOL."
        else
            info "A fresh install: '${COMPOSE[*]} up -d' initialises an empty PostgreSQL $IMAGE_MAJOR cluster."
        fi
        exit 0
    fi
    if [ -n "$NEW_MAJOR" ] && [ "$NEW_MAJOR" != "$IMAGE_MAJOR" ]; then
        die "volume $NEW_VOL holds a PostgreSQL $NEW_MAJOR cluster but the compose file asks for major $IMAGE_MAJOR; not handled by this script"
    fi
    if [ "$OLD_MAJOR" -ge "$IMAGE_MAJOR" ]; then
        die "the legacy cluster is PostgreSQL $OLD_MAJOR, the image is major $IMAGE_MAJOR: downgrades are not supported"
    fi

    SRC_IMAGE="${PG_UPGRADE_SOURCE_IMAGE:-postgres:${OLD_MAJOR}-alpine}"
    TMP_VOL="${PROJECT}_pgupgrade_src"
    SOCK_VOL="${PROJECT}_pgupgrade_sock"
    SRC_CTR="${PROJECT}-pgupgrade-src"
    WORK="$(mktemp -d)"

    log "Plan: PostgreSQL $OLD_MAJOR ($OLD_VOL) → PostgreSQL $IMAGE_MAJOR ($NEW_VOL), database '$DB_NAME', role '$DB_USER'"
    info "source server: $SRC_IMAGE on a temporary copy of the legacy volume; target: the stack's postgres ($IMAGE)"

    STEP='pull images'
    ensure_image "$SRC_IMAGE"
    ensure_image "$IMAGE"

    STEP='check temporaries'
    if docker container inspect "$SRC_CTR" >/dev/null 2>&1; then
        if [ "$(docker container inspect "$SRC_CTR" --format '{{.State.Running}}')" = 'true' ]; then
            die "container $SRC_CTR is running: another upgrade seems to be in progress. If it is not, remove it:  docker rm -f $SRC_CTR"
        fi
        info "removing the leftovers of an interrupted run"
    fi
    cleanup_temp
    WORK="$(mktemp -d)"

    STEP='stop the application'
    local s previous=''
    while read -r s; do [ -z "$s" ] || STOPPED+=("$s"); done < <(running_writers)
    if [ "${#STOPPED[@]}" -gt 0 ]; then
        log "Stopping the services that use the database: ${STOPPED[*]}"
        compose stop "${STOPPED[@]}"
    else
        log "No application services are running"
    fi
    log "Stopping the stack's postgres"
    compose stop postgres
    local users
    users="$(docker ps --filter "volume=$OLD_VOL" --format '{{.Names}}' | tr '\n' ' ')"
    [ -z "${users// /}" ] || die "the legacy volume $OLD_VOL is still mounted by running container(s): $users — stop them first"

    STEP='start the new postgres'
    log "Starting the stack's postgres ($IMAGE) on volume $NEW_VOL"
    compose up -d --no-deps --wait postgres >/dev/null 2>&1 || compose up -d --no-deps postgres
    wait_target_ready
    local wip_prev
    wip_prev="$(printf 'cat %s/%s 2>/dev/null || true' "$NEW_MOUNT" "$MARKER_WIP" | pg_target /bin/sh)"
    previous="$(printf '%s\n' "$wip_prev" | sed -n 's/^stopped_services=//p')"
    for s in $previous; do
        case " ${STOPPED[*]:-} " in *" $s "*) ;; *) STOPPED+=("$s") ;; esac
    done
    write_wip_marker

    STEP='copy the legacy volume'
    log "Copying the legacy volume into the temporary volume $TMP_VOL (the original is mounted read-only)"
    docker volume create --label "$LABEL" "$TMP_VOL" >/dev/null
    docker volume create --label "$LABEL" "$SOCK_VOL" >/dev/null
    docker run --rm --entrypoint /bin/sh -v "$OLD_VOL:/src:ro" -v "$TMP_VOL:/dst" "$SRC_IMAGE" \
        -c 'cp -a /src/. /dst/ && rm -f /dst/postmaster.pid'

    STEP='start the source postgres'
    log "Starting PostgreSQL $OLD_MAJOR ($SRC_IMAGE) on the copy, unix socket only"
    docker run -d --name "$SRC_CTR" --label "$LABEL" --network none \
        -v "$TMP_VOL:/var/lib/postgresql/data" -v "$SOCK_VOL:/var/run/postgresql" \
        "$SRC_IMAGE" -c listen_addresses= >/dev/null
    SRC_ROLE="$DB_USER"
    wait_source_ready

    STEP='inspect the source'
    local src_dbs src_db
    src_dbs="$(printf "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY 1" | psql_source -d postgres)"
    if grep -qx -- "$DB_NAME" <<<"$src_dbs"; then
        src_db="$DB_NAME"
    elif [ "$(printf '%s\n' "$src_dbs" | grep -c .)" -eq 1 ]; then
        src_db="$src_dbs"
        warn "the legacy cluster has no database '$DB_NAME'; copying its only database '$src_db' into '$DB_NAME'"
    else
        die "the legacy cluster has no database '$DB_NAME' (found: $(tr '\n' ' ' <<<"$src_dbs")); set DB_DATABASE accordingly"
    fi
    local extra_dbs extra_roles
    extra_dbs="$(grep -vx -- "$src_db" <<<"$src_dbs" | tr '\n' ' ' || true)"
    [ -z "${extra_dbs// /}" ] || warn "other databases in the legacy cluster are NOT copied: $extra_dbs"
    extra_roles="$(printf "SELECT rolname FROM pg_roles WHERE rolname NOT LIKE 'pg\\\\_%%' AND rolname <> %s ORDER BY 1" "'$DB_USER'" | psql_source -d postgres | tr '\n' ' ')"
    [ -z "${extra_roles// /}" ] || warn "other roles in the legacy cluster are NOT copied (ownership and grants are rewritten to '$DB_USER'): $extra_roles"
    printf '%s' "$SQL_FINGERPRINT" | psql_source -d "$src_db" > "$WORK/source.txt"
    local src_tables src_rows
    src_tables="$(grep -c '^table ' "$WORK/source.txt" || true)"
    src_rows="$(awk '$1 == "table" { s += $4 } END { print s + 0 }' "$WORK/source.txt")"
    info "source database '$src_db': $src_tables tables, $src_rows rows, $(grep -c '^sequence ' "$WORK/source.txt" || true) sequences, $(grep -c '^index ' "$WORK/source.txt" || true) indexes"
    [ "$src_tables" -gt 0 ] || die "the source database '$src_db' has no tables; refusing to continue"

    STEP='prepare the target database'
    if [ "$(printf "SELECT 1 FROM pg_database WHERE datname = :'db'" | psql_target -d postgres -v db="$DB_NAME")" != '1' ]; then
        log "Creating database '$DB_NAME' owned by '$DB_USER'"
        pg_target createdb -U "$DB_USER" -O "$DB_USER" "$DB_NAME"
    fi
    local target_objects
    target_objects="$(printf '%s' "$SQL_IS_EMPTY" | psql_target -d "$DB_NAME")"
    if [ "$target_objects" = '0' ]; then
        STEP='dump and restore'
        log "Restoring '$src_db' into PostgreSQL $IMAGE_MAJOR (pg_dump $IMAGE_MAJOR → pg_restore --single-transaction)"
        docker run --rm --entrypoint pg_dump -v "$SOCK_VOL:/var/run/postgresql" "$IMAGE" \
            -h /var/run/postgresql -U "$SRC_ROLE" --format=custom --no-owner --no-privileges "$src_db" \
            | pg_target pg_restore -U "$DB_USER" -d "$DB_NAME" --single-transaction --exit-on-error --no-owner --no-privileges
    else
        log "Database '$DB_NAME' on PostgreSQL $IMAGE_MAJOR already has $target_objects objects (interrupted run?): verifying it instead of restoring"
    fi

    STEP='verify'
    printf '%s' "$SQL_FINGERPRINT" | psql_target -d "$DB_NAME" > "$WORK/target.txt"
    if ! diff -u "$WORK/source.txt" "$WORK/target.txt" > "$WORK/diff.txt"; then
        {
            printf 'Differences between PostgreSQL %s (source, -) and PostgreSQL %s (target, +):\n' "$OLD_MAJOR" "$IMAGE_MAJOR"
            head -n 60 "$WORK/diff.txt"
        } >&2
        die "database '$DB_NAME' on PostgreSQL $IMAGE_MAJOR does not match the legacy data. If it was created by mistake (e.g. migrations ran on the empty cluster), drop it and run this script again:  ${COMPOSE[*]} exec postgres dropdb -U $DB_USER $DB_NAME"
    fi
    log "Verified: $src_tables tables with $src_rows rows, sequences, indexes and constraints are identical"
    local enc
    enc="$(printf "SELECT pg_encoding_to_char(encoding) || ' ' || datcollate FROM pg_database WHERE datname = current_database()" | psql_target -d "$DB_NAME")"
    info "target encoding/collation: $enc (source: $(printf "SELECT pg_encoding_to_char(encoding) || ' ' || datcollate FROM pg_database WHERE datname = current_database()" | psql_source -d "$src_db"))"

    STEP='analyze'
    log "Collecting planner statistics (vacuumdb --analyze-in-stages)"
    pg_target vacuumdb --analyze-in-stages -q -U "$DB_USER" -d "$DB_NAME"

    STEP='finish'
    {
        printf 'upgraded %s → %s at %s: %s tables, %s rows, database %s, source volume %s\n' \
            "$OLD_MAJOR" "$IMAGE_MAJOR" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$src_tables" "$src_rows" "$DB_NAME" "$OLD_VOL"
        printf 'source_image=%s\ntarget_image=%s\n' "$SRC_IMAGE" "$IMAGE"
    } | pg_target /bin/sh -c "cat > $NEW_MOUNT/$MARKER_DONE && chmod 644 $NEW_MOUNT/$MARKER_DONE && rm -f $NEW_MOUNT/$MARKER_WIP"
    MARKED_WIP=0
    docker rm -f "$SRC_CTR" >/dev/null
    cleanup_temp

    STEP='start the application'
    if [ "${#STOPPED[@]}" -gt 0 ]; then
        log "Starting the services again: ${STOPPED[*]}"
        compose up -d --no-deps "${STOPPED[@]}"
    else
        info "No application services were running before; start the stack yourself: ${COMPOSE[*]} up -d"
    fi

    log "Done: PostgreSQL $IMAGE_MAJOR serves '$DB_NAME' from volume $NEW_VOL"
    info "The legacy volume $OLD_VOL is untouched and stays as the rollback copy."
    info "Delete it once the upgrade has proven itself:  docker volume rm $OLD_VOL"
}

if [ "$CHECK_ONLY" -eq 1 ]; then
    run_check
else
    run_upgrade
fi
