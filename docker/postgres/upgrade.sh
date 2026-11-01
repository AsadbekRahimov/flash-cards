#!/usr/bin/env bash
# PostgreSQL major upgrade for the LexiFlow Pro Docker stack: the 16 cluster in the legacy volume
# (key `pgdata`, mounted at /var/lib/postgresql/data) is dumped and restored into the 18+ layout
# (one volume mounted at /var/lib/postgresql, cluster in <major>/docker). See docs/DEVOPS_GUIDE.md §0,
# «Обновление PostgreSQL 16 → 18 (Docker)».
#
# Usage:
#   bash docker/postgres/upgrade.sh [--prod] [--check | --redo | --keep-current] [-- <docker compose options>]
#
#   (no flags)      dev stack: `docker compose` (docker-compose.yml + docker-compose.override.yml, .env)
#   --prod          production stack: `docker compose -f docker-compose.prod.yml --env-file .env`
#   --check         report the state of the volumes and exit 0 (safe to start the stack) / 1 (the deploy must stop)
#                   without changing anything and without taking the lock; deploy.sh runs this before build/up
#   --redo          the legacy cluster ran again after a completed upgrade (a rollback to 16): set the current
#                   database aside as <db>_stale_<time> (kept, not dropped) and copy the legacy data again.
#                   Refused while the legacy cluster is unchanged - nothing is stale then
#   --keep-current  the legacy cluster ran again, but the new-major data is the truth: keep it and record the new
#                   state of the legacy cluster; whatever 16 wrote after the upgrade stays only in the legacy volume
#   -- ...          pass custom `docker compose` options instead of the defaults (tests, odd layouts),
#                   e.g. `-- -p mystack -f docker-compose.yml -f extra.yml --env-file .env.test`
#
# What the upgrade does (idempotent, re-runnable after any failure):
#   1. takes the lock shared with deploy.sh (.deploy.lock in the project directory), stops every service of the stack
#      except postgres and redis (the writers), then the old postgres, and writes the in-progress marker with the list
#      of stopped services into the new volume (created by compose) - from here on `--check` stops every deploy;
#   2. fingerprints the legacy cluster (md5 of global/pg_control: it changes whenever that server runs again);
#   3. initialises the 18+ cluster on the new volume (the stack keeps the legacy volume mounted read-only as a guard,
#      so the plain entrypoint would refuse) with a pg_hba.conf gate that rejects every network connection: nobody can
#      use the new server before the data is in - neither the application nor `php artisan migrate`;
#   4. copies the legacy volume into a temporary volume and starts the matching official postgres:<old major>-alpine
#      on the COPY (the legacy volume itself is never written); streams `pg_dump` (new client) into
#      `pg_restore --single-transaction` - the target is either fully restored or left empty;
#   5. compares row counts of every table, sequences, indexes and constraints between the two clusters;
#   6. runs `vacuumdb --analyze-in-stages`, opens the gate, writes the done marker (with the fingerprint) into the new
#      volume, removes the temporaries, starts the services it stopped and removes the in-progress marker.
#   The legacy volume stays as the rollback copy until the owner deletes it by hand.
#
# Environment:
#   PG_UPGRADE_SOURCE_IMAGE  image for the old cluster (default postgres:<old major>-alpine)
#   PG_UPGRADE_TIMEOUT       seconds to wait for a server to become ready (default 180)
#   DEPLOY_LOCK_FILE         lock file shared with deploy.sh (default .deploy.lock in the project directory)
#   DEPLOY_LOCK_WAIT         seconds to wait for that lock before giving up (default 300)
#   PG_UPGRADE_FAIL_AT       tests only: the name of the step at which to fail on purpose

set -Eeuo pipefail

LEGACY_VOLUME_KEY='pgdata'                # compose volume key used by the ≤16 stack
NEW_MOUNT='/var/lib/postgresql'           # 18+ images: single mount, cluster in <major>/docker
MARKER_DONE='lexiflow-upgrade.done'       # written into the root of the new volume after a verified upgrade
MARKER_WIP='lexiflow-upgrade.in-progress' # present while an upgrade runs or after it was interrupted
GATE_RULE='host all all all reject'       # first line of pg_hba.conf while the move is unverified
GATE_NOTE='lexiflow-upgrade gate: network connections are rejected until docker/postgres/upgrade.sh has verified the data move'
LABEL='lexiflow.pg-upgrade=1'             # every temporary container/volume this script creates
WAIT_TIMEOUT="${PG_UPGRADE_TIMEOUT:-180}"
LOCK_FILE="${DEPLOY_LOCK_FILE:-.deploy.lock}"
LOCK_WAIT="${DEPLOY_LOCK_WAIT:-300}"

MODE='dev'
CHECK_ONLY=0
REDO=0
KEEP_CURRENT=0
COMPOSE=()
while [ $# -gt 0 ]; do
    case "$1" in
        --prod) MODE='prod' ;;
        --check) CHECK_ONLY=1 ;;
        --redo) REDO=1 ;;
        --keep-current) KEEP_CURRENT=1 ;;
        -h|--help) sed -n '2,44p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        --) shift; COMPOSE=(docker compose "$@"); MODE='custom'; break ;;
        *) echo "Unknown argument: $1 (see --help)" >&2; exit 2 ;;
    esac
    shift
done
if [ "$REDO" -eq 1 ] && [ "$KEEP_CURRENT" -eq 1 ]; then
    echo '--redo and --keep-current exclude each other (see --help)' >&2; exit 2
fi
if [ "$CHECK_ONLY" -eq 1 ] && [ "$((REDO + KEEP_CURRENT))" -ne 0 ]; then
    echo '--check changes nothing; drop --redo / --keep-current (see --help)' >&2; exit 2
fi
if [ "${#COMPOSE[@]}" -eq 0 ]; then
    case "$MODE" in
        prod) COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env) ;;
        *) COMPOSE=(docker compose) ;;
    esac
fi
case "$MODE" in
    prod) SCRIPT_CMD='bash docker/postgres/upgrade.sh --prod'; UPGRADE_CMD="make prod-pg-upgrade   # = $SCRIPT_CMD" ;;
    dev) SCRIPT_CMD='bash docker/postgres/upgrade.sh'; UPGRADE_CMD="make pg-upgrade   # = $SCRIPT_CMD" ;;
    *) SCRIPT_CMD="bash docker/postgres/upgrade.sh -- ${COMPOSE[*]:2}"; UPGRADE_CMD="$SCRIPT_CMD" ;;
esac
SAME_CMD="$SCRIPT_CMD"   # this very invocation, for "run it again" hints
[ "$REDO" -eq 0 ] || SAME_CMD="$SAME_CMD --redo"
[ "$KEEP_CURRENT" -eq 0 ] || SAME_CMD="$SAME_CMD --keep-current"

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

# The ≤16 volume: still declared in compose (mounted read-only as a guard), so its real name comes from the
# config; otherwise it is found by its compose labels, then by the default name <project>_pgdata.
find_legacy_volume() {
    local found
    found="$(cfg_get volumes "$LEGACY_VOLUME_KEY" name)"
    if [ -n "$found" ] && ! volume_exists "$found"; then
        found=''
    fi
    if [ -z "$found" ]; then
        found="$(docker volume ls -q \
            --filter "label=com.docker.compose.project=$PROJECT" \
            --filter "label=com.docker.compose.volume=$LEGACY_VOLUME_KEY" | head -n 1)"
    fi
    if [ -z "$found" ] && volume_exists "${PROJECT}_${LEGACY_VOLUME_KEY}"; then
        found="${PROJECT}_${LEGACY_VOLUME_KEY}"
    fi
    printf '%s' "$found"
}

# Facts about a volume without starting PostgreSQL (read-only mount, one `cat`-only container):
#   root=<major>         PG_VERSION in the volume root  (≤16 layout mounted at /var/lib/postgresql/data)
#   data=<major>         PG_VERSION in ./data           (≤16 layout mounted at /var/lib/postgresql)
#   docker=<major>       PG_VERSION in ./<major>/docker (18+ layout)
#   pg_control_md5=<md5> fingerprint of a ≤16 cluster: global/pg_control changes whenever that server runs
#   done=<summary>       first line of the done marker; done_md5 / done_services: fields of that marker
#   wip=1                in-progress marker; wip_services: the services it stopped
#   gate=1               the pg_hba.conf gate of an unverified 18+ cluster is in place
#   empty=1              the volume has no files at all
inspect_volume() {
    docker run --rm --entrypoint /bin/sh -v "$1:/v:ro" "$IMAGE" -c '
        [ -s /v/PG_VERSION ] && printf "root=%s\n" "$(cat /v/PG_VERSION)"
        [ -s /v/data/PG_VERSION ] && printf "data=%s\n" "$(cat /v/data/PG_VERSION)"
        for f in /v/*/docker/PG_VERSION; do [ -s "$f" ] && printf "docker=%s\n" "$(cat "$f")"; done
        [ -s /v/global/pg_control ] && printf "pg_control_md5=%s\n" "$(md5sum < /v/global/pg_control | cut -d" " -f1)"
        if [ -s /v/'"$MARKER_DONE"' ]; then
            printf "done=%s\n" "$(head -n 1 /v/'"$MARKER_DONE"')"
            sed -n "s/^legacy_pg_control_md5=/done_md5=/p; s/^stopped_services=/done_services=/p" /v/'"$MARKER_DONE"'
        fi
        if [ -e /v/'"$MARKER_WIP"' ]; then
            echo "wip=1"
            sed -n "s/^stopped_services=/wip_services=/p" /v/'"$MARKER_WIP"'
        fi
        for f in /v/*/docker/pg_hba.conf; do grep -qx "'"$GATE_RULE"'" "$f" 2>/dev/null && echo "gate=1"; done
        [ -z "$(ls -A /v)" ] && echo "empty=1"
        exit 0'
}
fact() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -n 1; }

OLD_VOL=''; OLD_MAJOR=''; OLD_FACTS=''; OLD_MD5=''
NEW_FACTS=''; NEW_MAJOR=''; NEW_EXISTS=0; DONE_LINE=''; DONE_MD5=''; WIP=''; GATE=''
inspect_stack() {
    OLD_VOL="$(find_legacy_volume)"
    if [ -n "$OLD_VOL" ] && [ "$OLD_VOL" != "$NEW_VOL" ]; then
        OLD_FACTS="$(inspect_volume "$OLD_VOL")"
        OLD_MAJOR="$(fact "$OLD_FACTS" root)"
        OLD_MD5="$(fact "$OLD_FACTS" pg_control_md5)"
    fi
    if volume_exists "$NEW_VOL"; then
        NEW_EXISTS=1
        NEW_FACTS="$(inspect_volume "$NEW_VOL")"
        NEW_MAJOR="$(fact "$NEW_FACTS" docker)"
        DONE_LINE="$(fact "$NEW_FACTS" done)"
        DONE_MD5="$(fact "$NEW_FACTS" done_md5)"
        WIP="$(fact "$NEW_FACTS" wip)"
        GATE="$(fact "$NEW_FACTS" gate)"
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
    [ -z "$DONE_LINE" ] || info "upgrade marker: $DONE_LINE"
    if [ -n "$WIP" ]; then
        if [ -n "$DONE_LINE" ]; then
            info "upgrade marker: IN PROGRESS — the data move is complete, only the start of the application services is pending"
        else
            info "upgrade marker: IN PROGRESS / INTERRUPTED"
        fi
    fi
    [ -z "$GATE" ] || info "network gate: CLOSED — PostgreSQL $NEW_MAJOR rejects every network connection until the move is verified"
    if [ -n "$DONE_LINE" ] && [ -n "$OLD_MAJOR" ]; then
        if [ -z "$DONE_MD5" ]; then
            info "legacy fingerprint: not recorded (the marker was written by an older upgrade.sh)"
        elif [ "$DONE_MD5" = "$OLD_MD5" ]; then
            info "legacy fingerprint: unchanged since the upgrade (PostgreSQL $OLD_MAJOR has not run on $OLD_VOL since)"
        else
            info "legacy fingerprint: CHANGED since the upgrade (pg_control md5 $DONE_MD5 → ${OLD_MD5:-missing}): PostgreSQL $OLD_MAJOR ran on $OLD_VOL"
        fi
    fi
}

# The legacy volume is mounted read-only into the postgres container as a guard, so `docker volume rm` alone says
# "volume is in use"; the container has to go first. Compose then creates an empty volume of that name - harmless.
legacy_delete_hint() {
    info "Delete it only once the upgrade has proven itself. The postgres container holds it (read-only guard mount), so:"
    info "    ${COMPOSE[*]} rm -sf postgres && docker volume rm $OLD_VOL && ${COMPOSE[*]} up -d"
    info "    (compose creates an empty $OLD_VOL again — harmless; the application reconnects within seconds)"
}

# What to do when the legacy cluster ran again after a completed upgrade. One line per argument of stop_*.
stale_commands() {
    printf '%s\n' \
        "Decide which data is the truth — there is no merge:" \
        "  $SCRIPT_CMD" \
        "      shows what changed on PostgreSQL $OLD_MAJOR (committed transactions, or just a start)" \
        "  $SCRIPT_CMD --redo" \
        "      PostgreSQL $OLD_MAJOR is the truth: the PostgreSQL $IMAGE_MAJOR database is set aside as ${DB_NAME}_stale_<time> (kept) and the" \
        "      $OLD_MAJOR data is copied again; what the application wrote on $IMAGE_MAJOR after the upgrade then lives only in that copy" \
        "  $SCRIPT_CMD --keep-current" \
        "      PostgreSQL $IMAGE_MAJOR is the truth: its data is kept and the new state of $OLD_MAJOR is recorded; what $OLD_MAJOR wrote" \
        "      after the upgrade stays only in the legacy volume $OLD_VOL" \
        "then run the deploy again."
}

# ---------------------------------------------------------------------------------------------------
# --check: the guard used by deploy.sh (exit 0 = safe to start the stack, 1 = stop the deploy)
# ---------------------------------------------------------------------------------------------------
stop_with() {
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

    if [ -n "$WIP" ] && [ -z "$DONE_LINE" ]; then
        local what='no cluster has been initialised yet'
        [ -z "$NEW_MAJOR" ] || what="the PostgreSQL $NEW_MAJOR cluster there is empty or only partly restored"
        stop_with "a PostgreSQL upgrade is in progress or was interrupted (marker $MARKER_WIP in volume $NEW_VOL; $what)." \
            "Finish it before deploying:  $UPGRADE_CMD"
    fi
    if [ -n "$(fact "$NEW_FACTS" root)" ] || [ -n "$(fact "$NEW_FACTS" data)" ]; then
        stop_with "volume $NEW_VOL holds a cluster in the ≤16 layout; postgres $IMAGE_MAJOR refuses to start on it." \
            "The 18+ stack expects the cluster in $NEW_MOUNT/<major>/docker. Move the data with:  $UPGRADE_CMD"
    fi
    if [ -n "$OLD_MAJOR" ] && [ -z "$NEW_MAJOR" ]; then
        local where='does not exist'
        [ "$NEW_EXISTS" -eq 0 ] || where='has no cluster yet'
        stop_with "the PostgreSQL $OLD_MAJOR data in volume $OLD_VOL has not been moved to the 18+ layout (volume $NEW_VOL $where)." \
            "Starting $IMAGE now would initialise an EMPTY database and the application would run on it." \
            "Run the upgrade first (stops the app, copies $OLD_MAJOR → $IMAGE_MAJOR, verifies, starts the app again):" \
            "    $UPGRADE_CMD" \
            "then run the deploy again. The legacy volume is kept untouched as the rollback copy."
    fi
    if [ -n "$OLD_MAJOR" ] && [ -n "$NEW_MAJOR" ] && [ -z "$DONE_LINE" ]; then
        stop_with "volume $NEW_VOL holds a PostgreSQL $NEW_MAJOR cluster, but the move of the PostgreSQL $OLD_MAJOR data from $OLD_VOL was never completed (no $MARKER_DONE marker: an interrupted upgrade, or a cluster initialised by hand)." \
            "Its database is empty or incomplete; the application would start on it, look healthy and write there." \
            "Finish the move (restores what is missing, verifies, starts the app again):  $UPGRADE_CMD" \
            "then run the deploy again."
    fi
    if [ -n "$DONE_LINE" ] && [ -z "$NEW_MAJOR" ]; then
        stop_with "volume $NEW_VOL carries the $MARKER_DONE marker but no PostgreSQL cluster: the data directory was removed by hand." \
            "Restore the volume from a backup; if an empty cluster is really wanted, remove the marker deliberately:" \
            "    ${COMPOSE[*]} run --rm --no-deps --entrypoint rm postgres $NEW_MOUNT/$MARKER_DONE"
    fi
    if [ -n "$NEW_MAJOR" ] && [ "$NEW_MAJOR" != "$IMAGE_MAJOR" ]; then
        stop_with "volume $NEW_VOL holds a PostgreSQL $NEW_MAJOR cluster but the compose file asks for $IMAGE (major $IMAGE_MAJOR)." \
            "The image refuses to start on another major; a $NEW_MAJOR → $IMAGE_MAJOR upgrade is needed before deploying (see the docs)."
    fi
    if [ -n "$OLD_MAJOR" ] && [ -n "$DONE_LINE" ]; then
        if [ -z "$DONE_MD5" ]; then
            stop_with "the $MARKER_DONE marker in $NEW_VOL records no fingerprint of the legacy cluster (written by an older upgrade.sh), so a rollback to PostgreSQL $OLD_MAJOR after the upgrade cannot be ruled out." \
                "If the PostgreSQL $NEW_MAJOR data is the current data, record the fingerprint once:  $SCRIPT_CMD --keep-current" \
                "then run the deploy again."
        elif [ "$DONE_MD5" != "$OLD_MD5" ]; then
            local lines=() line
            while IFS= read -r line; do lines+=("$line"); done < <(stale_commands)
            stop_with "the PostgreSQL $OLD_MAJOR cluster in volume $OLD_VOL ran again after the upgrade (a rollback to $OLD_MAJOR?), so the PostgreSQL $NEW_MAJOR data in $NEW_VOL may be STALE: it holds the state of «$DONE_LINE»." \
                "${lines[@]}"
        fi
    fi

    if [ -n "$OLD_MAJOR" ] && [ -n "$NEW_MAJOR" ]; then
        log "OK: PostgreSQL $NEW_MAJOR data is in place; the legacy PostgreSQL $OLD_MAJOR volume $OLD_VOL is the rollback copy"
        legacy_delete_hint
    elif [ -n "$NEW_MAJOR" ]; then
        log "OK: PostgreSQL $NEW_MAJOR data is in place"
    else
        log "OK: no PostgreSQL data yet — a fresh install, the stack will initialise an empty cluster"
    fi
    [ -z "$WIP" ] || info "The last upgrade run did not get to start the application services; the deploy starts the whole stack anyway ($UPGRADE_CMD would only start them)."
    exit 0
}

# ---------------------------------------------------------------------------------------------------
# Upgrade helpers
# ---------------------------------------------------------------------------------------------------
STEP='preflight'
IN_PROGRESS=0       # 1 once the stack has been touched (services stopped): a failure then needs the resume hints
LOCKED=0
SRC_IMAGE=''; SRC_ROLE=''; TMP_VOL=''; SOCK_VOL=''; SRC_CTR=''
WORK=''
STOPPED=()          # services stopped by this run (or a previous, interrupted run)
MARKED_WIP=0
LEGACY_MD5=''
STALE_DB=''
VERDICT=''

step() {
    STEP="$1"
    [ "${PG_UPGRADE_FAIL_AT:-}" != "$1" ] || die "injected failure at step '$1' (PG_UPGRADE_FAIL_AT is set; tests only)"
}

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
    if [ "$status" -ne 0 ] && [ "$CHECK_ONLY" -eq 0 ] && [ "$IN_PROGRESS" -eq 1 ]; then
        {
            printf '\nFAILED during step "%s" (exit %s).\n' "$STEP" "$status"
            [ -z "$ERR_CMD" ] || printf '  Failed command (line %s): %s\n' "$ERR_LINE" "$ERR_CMD"
            [ -z "$OLD_VOL" ] || printf '  The legacy volume %s was not modified.\n' "$OLD_VOL"
            if [ "${#STOPPED[@]}" -gt 0 ]; then
                printf '  Still stopped: %s\n' "${STOPPED[*]}"
            fi
            if [ "$MARKED_WIP" -eq 1 ]; then
                printf '  Volume %s keeps the %s marker: deploy.sh refuses to start the stack until the upgrade completes,\n' "$NEW_VOL" "$MARKER_WIP"
                printf '  and PostgreSQL %s there rejects network connections (pg_hba gate), so nothing can run on an incomplete database.\n' "$IMAGE_MAJOR"
            fi
            printf '  Fix the cause and run the same command again — it resumes where it stopped:  %s\n' "$SAME_CMD"
            printf '  To stay on PostgreSQL %s instead: restore the previous compose files (prod: bash deploy.sh --rollback <prev_sha>) and start\n' "${OLD_MAJOR:-16}"
            printf '  the stack; the legacy volume is picked up again. The next forward deploy then stops until the upgrade is run again.\n'
        } >&2
    fi
    cleanup_temp
}
trap 'ERR_CMD=$BASH_COMMAND; ERR_LINE=$LINENO' ERR
trap 'on_exit $?' EXIT

# One lock for deploy.sh and this script: a deploy never overlaps a data move. flock(1) is util-linux; the production
# server is Linux. --check never locks (read-only, and deploy.sh calls it while holding the lock itself).
take_lock() {
    [ "$LOCKED" -eq 0 ] || return 0
    if ! command -v flock >/dev/null 2>&1; then
        [ "$MODE" != 'prod' ] || die "flock(1) is required (package util-linux): deploy.sh takes the same lock $LOCK_FILE"
        warn "flock(1) not found: running without the lock shared with deploy.sh"
        LOCKED=1
        return 0
    fi
    exec 9>>"$LOCK_FILE" || die "cannot open the lock file $LOCK_FILE"
    if ! flock -n 9; then
        log "Waiting for the lock $LOCK_FILE — another deploy.sh or upgrade.sh is running ($(head -n 1 "$LOCK_FILE" 2>/dev/null || true)); up to ${LOCK_WAIT}s"
        flock -w "$LOCK_WAIT" 9 || die "the lock $LOCK_FILE is still held after ${LOCK_WAIT}s; let the other deploy/upgrade finish, then run this again"
    fi
    : > "$LOCK_FILE"
    printf 'pid=%s started=%s command=%s\n' "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$SAME_CMD" >&9
    LOCKED=1
}

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
add_stopped() { case " ${STOPPED[*]:-} " in *" $1 "*) ;; *) STOPPED+=("$1") ;; esac; }

pg_target() {  # run a program inside the stack's postgres container (as root, like the entrypoint)
    compose exec -T postgres "$@"
}
pg_target_as_postgres() {
    compose exec -T --user postgres postgres "$@"
}
psql_target() {  # SQL on stdin against the target cluster
    pg_target psql -v ON_ERROR_STOP=1 -X -q -At -F ' ' -U "$DB_USER" "$@"
}
psql_source() {  # SQL on stdin against the temporary source cluster, via its unix socket
    docker exec -i "$SRC_CTR" psql -v ON_ERROR_STOP=1 -X -q -At -F ' ' -U "$SRC_ROLE" "$@"
}
qi() { printf '"%s"' "${1//\"/\"\"}"; }   # SQL identifier

# A one-off shell in a container of the stack's postgres service: the stack's volumes are created by compose (with its
# labels) if missing, and it works whether or not the service container runs. Stdin is passed through.
one_off() {
    compose run --rm --no-deps -T --quiet-pull --entrypoint /bin/sh postgres -c "$@"
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

# --- the pg_hba.conf gate: while the move is unverified the new server answers only on its unix socket -----------------
# `host` rules match TCP only, so the healthcheck, `compose exec psql` and pg_restore (all via the socket) keep working;
# the application, `php artisan migrate` and anything else on the network get "pg_hba.conf rejects connection".
gate_is_closed() {
    local out
    out="$(pg_target psql -w -h 127.0.0.1 -U "$DB_USER" -d postgres -c 'SELECT 1' 2>&1 || true)"
    grep -q 'rejects connection' <<<"$out"
}
close_gate() {  # idempotent: also for a cluster initialised by an older run or by hand
    pg_target_as_postgres /bin/sh -c '
        f="$PGDATA/pg_hba.conf"
        grep -qx -- "$1" "$f" && exit 0
        { printf "# %s\n%s\n" "$2" "$1"; cat "$f"; } > "$f.lexiflow" && mv "$f.lexiflow" "$f"' sh "$GATE_RULE" "$GATE_NOTE"
    printf 'SELECT pg_reload_conf()' | psql_target -d postgres >/dev/null
    gate_is_closed || die "could not close the network gate of PostgreSQL $IMAGE_MAJOR (pg_hba.conf in $NEW_VOL)"
}
open_gate() {
    pg_target_as_postgres /bin/sh -c '
        f="$PGDATA/pg_hba.conf"
        grep -vx -e "$1" -e "# $2" -- "$f" > "$f.lexiflow" && mv "$f.lexiflow" "$f"' sh "$GATE_RULE" "$GATE_NOTE"
    printf 'SELECT pg_reload_conf()' | psql_target -d postgres >/dev/null
    local probe
    probe="$(pg_target /bin/sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -w -h 127.0.0.1 -U "$POSTGRES_USER" -d postgres -X -Atc "SELECT 1"' 2>&1 || true)"
    [ "$probe" = '1' ] || die "PostgreSQL $IMAGE_MAJOR does not accept network connections after opening the gate: $probe"
}

# --- fingerprint of the legacy cluster ----------------------------------------------------------------------------------
# global/pg_control is rewritten at every start and every checkpoint, so its md5 changes whenever PostgreSQL <old> runs on
# the volume again - a rollback, a "quick look", a `compose up` from an old checkout. pg_controldata of the matching major
# tells whether that run committed anything (NextXID / NextOID advance only when a transaction writes).
legacy_md5() {
    docker run --rm --entrypoint /bin/sh -v "$OLD_VOL:/v:ro" "$IMAGE" -c 'md5sum < /v/global/pg_control | cut -d" " -f1'
}
legacy_controldata() {
    docker run --rm --entrypoint pg_controldata -v "$OLD_VOL:/v:ro" "$SRC_IMAGE" /v
}
ctl_field() { printf '%s\n' "$1" | sed -n "s/^$2: *//p" | head -n 1; }
legacy_fingerprint_lines() {  # $1 = pg_controldata output, $2 = md5
    printf 'legacy_pg_control_md5=%s\n' "$2"
    printf 'legacy_system_identifier=%s\n' "$(ctl_field "$1" 'Database system identifier')"
    printf 'legacy_cluster_state=%s\n' "$(ctl_field "$1" 'Database cluster state')"
    printf 'legacy_checkpoint=%s\n' "$(ctl_field "$1" 'Latest checkpoint location')"
    printf 'legacy_checkpoint_time=%s\n' "$(ctl_field "$1" 'Time of latest checkpoint')"
    printf 'legacy_next_xid=%s\n' "$(ctl_field "$1" "Latest checkpoint's NextXID")"
    printf 'legacy_next_oid=%s\n' "$(ctl_field "$1" "Latest checkpoint's NextOID")"
}

# What happened on the legacy cluster since the done marker was written; sets VERDICT.
describe_legacy_change() {
    local ctl marker
    ctl="$(legacy_controldata)"
    marker="$(one_off "cat $NEW_MOUNT/$MARKER_DONE")"
    local m_sysid m_xid m_oid m_time n_sysid n_xid n_oid n_time n_state
    m_sysid="$(fact "$marker" legacy_system_identifier)"; n_sysid="$(ctl_field "$ctl" 'Database system identifier')"
    m_xid="$(fact "$marker" legacy_next_xid)";            n_xid="$(ctl_field "$ctl" "Latest checkpoint's NextXID")"
    m_oid="$(fact "$marker" legacy_next_oid)";            n_oid="$(ctl_field "$ctl" "Latest checkpoint's NextOID")"
    m_time="$(fact "$marker" legacy_checkpoint_time)";    n_time="$(ctl_field "$ctl" 'Time of latest checkpoint')"
    n_state="$(ctl_field "$ctl" 'Database cluster state')"
    info "PostgreSQL $OLD_MAJOR cluster in $OLD_VOL, at the upgrade → now:"
    info "  latest checkpoint: $m_time → $n_time"
    info "  NextXID: $m_xid → $n_xid   NextOID: $m_oid → $n_oid   cluster state now: $n_state"
    if [ "$m_sysid" != "$n_sysid" ]; then
        VERDICT="this is a DIFFERENT cluster (system identifier $m_sysid → $n_sysid): the legacy volume was re-initialised"
    elif [ "$n_state" != 'shut down' ]; then
        VERDICT="PostgreSQL $OLD_MAJOR is running or was not shut down cleanly; what it wrote is unknown — stop it, then run this again"
    elif [ "$m_xid" != "$n_xid" ] || [ "$m_oid" != "$n_oid" ]; then
        VERDICT="transactions were committed on PostgreSQL $OLD_MAJOR after the upgrade; the PostgreSQL $IMAGE_MAJOR copy lacks them"
    else
        VERDICT="PostgreSQL $OLD_MAJOR was started but no transaction wrote anything (NextXID unchanged); keeping the current data loses nothing"
    fi
    info "  verdict: $VERDICT"
}

# --- markers (written as root through a one-off container, so they can be rewritten whoever wrote them before) ---------
write_wip_marker() {
    {
        printf 'started=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf 'from=%s %s\n' "$OLD_MAJOR" "$OLD_VOL"
        printf 'to=%s %s\n' "$IMAGE_MAJOR" "$NEW_VOL"
        printf 'stopped_services=%s\n' "${STOPPED[*]:-}"
        printf 'redo=%s\n' "$REDO"
    } | one_off "cat > $NEW_MOUNT/$MARKER_WIP && chmod 644 $NEW_MOUNT/$MARKER_WIP"
    MARKED_WIP=1
}
remove_wip_marker() {
    one_off "rm -f $NEW_MOUNT/$MARKER_WIP"
    MARKED_WIP=0
}
remove_done_marker() {
    one_off "rm -f $NEW_MOUNT/$MARKER_DONE"
}
write_done_marker() {  # $1 = summary line
    local ctl
    ctl="$(legacy_controldata)"
    {
        printf '%s\n' "$1"
        printf 'source_image=%s\ntarget_image=%s\n' "$SRC_IMAGE" "$IMAGE"
        printf 'stopped_services=%s\n' "${STOPPED[*]:-}"
        legacy_fingerprint_lines "$ctl" "$LEGACY_MD5"
    } | one_off "cat > $NEW_MOUNT/$MARKER_DONE && chmod 644 $NEW_MOUNT/$MARKER_DONE"
}
refresh_done_marker() {  # --keep-current: the marker keeps its history, the legacy_* fields describe the cluster as it is now
    local ctl md5 current
    current="$(one_off "cat $NEW_MOUNT/$MARKER_DONE")"
    ctl="$(legacy_controldata)"
    md5="$(legacy_md5)"
    {
        printf '%s\n' "$current" | grep -v '^legacy_'
        legacy_fingerprint_lines "$ctl" "$md5"
        printf 'legacy_accepted_at=%s previous_md5=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${DONE_MD5:-none}"
    } | one_off "cat > $NEW_MOUNT/$MARKER_DONE && chmod 644 $NEW_MOUNT/$MARKER_DONE"
}

start_stopped_services() {
    if [ "${#STOPPED[@]}" -gt 0 ]; then
        log "Starting the services again: ${STOPPED[*]}"
        compose up -d "${STOPPED[@]}"
    else
        info "No application services were running before; start the stack yourself: ${COMPOSE[*]} up -d"
    fi
}

# done + in-progress markers together: the data move is complete, only the start of the services is pending.
finish_pending_start() {
    [ -n "$WIP" ] || return 0
    local s
    for s in $(fact "$NEW_FACTS" done_services) $(fact "$NEW_FACTS" wip_services); do add_stopped "$s"; done
    take_lock
    IN_PROGRESS=1
    MARKED_WIP=1
    step 'start the application'
    log "The previous run completed the data move but did not get to start the application services"
    start_stopped_services
    remove_wip_marker
    IN_PROGRESS=0
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
# Runs inside a one-off container of the stack's postgres service, as the postgres user. Mirrors the official
# /usr/local/bin/docker-ensure-initdb.sh step by step, except docker_error_old_databases (see the call site), plus the
# pg_hba.conf gate (PG_UPGRADE_GATE_RULE / PG_UPGRADE_GATE_NOTE) in front of the rules the image writes.
INIT_NEW_CLUSTER=$(cat <<'BASH'
set -Eeuo pipefail
source /usr/local/bin/docker-entrypoint.sh
for f in docker_setup_env docker_create_db_directories docker_verify_minimum_env docker_init_database_dir \
         pg_setup_hba_conf docker_temp_server_start docker_setup_db docker_process_init_files docker_temp_server_stop; do
    declare -F "$f" > /dev/null || { echo "docker-entrypoint.sh has no function $f; update docker/postgres/upgrade.sh" >&2; exit 3; }
done
docker_setup_env
docker_create_db_directories
if [ -n "$DATABASE_ALREADY_EXISTS" ]; then
    echo "cluster already initialised in $PGDATA"
    exit 0
fi
docker_verify_minimum_env
ls /docker-entrypoint-initdb.d/ > /dev/null
docker_init_database_dir
pg_setup_hba_conf postgres
{ printf '# %s\n%s\n' "$PG_UPGRADE_GATE_NOTE" "$PG_UPGRADE_GATE_RULE"; cat "$PGDATA/pg_hba.conf"; } > "$PGDATA/pg_hba.conf.lexiflow"
mv "$PGDATA/pg_hba.conf.lexiflow" "$PGDATA/pg_hba.conf"
export PGPASSWORD="${PGPASSWORD:-$POSTGRES_PASSWORD}"
docker_temp_server_start postgres
docker_setup_db
docker_process_init_files /docker-entrypoint-initdb.d/*
docker_temp_server_stop
echo "PostgreSQL $PG_MAJOR cluster initialised in $PGDATA (network gate closed)"
BASH
)
SQL_IS_EMPTY=$(cat <<'SQL'
SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p', 'S', 'v', 'm', 'f') AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%';
SQL
)

# ---------------------------------------------------------------------------------------------------
# Upgrade
# ---------------------------------------------------------------------------------------------------
run_upgrade() {
    resolve_stack
    inspect_stack
    log "PostgreSQL volumes of the stack"
    describe_state

    if [ -n "$OLD_VOL" ] && [ "$OLD_VOL" = "$NEW_VOL" ]; then
        die "the legacy volume is mounted at $NEW_MOUNT; give the 18+ stack a new volume (see docker-compose*.yml)"
    fi
    if [ -n "$(fact "$NEW_FACTS" root)" ] || [ -n "$(fact "$NEW_FACTS" data)" ]; then
        die "volume $NEW_VOL contains a cluster in the ≤16 layout; this script only reads the legacy volume '$LEGACY_VOLUME_KEY'"
    fi
    if [ -n "$NEW_MAJOR" ] && [ "$NEW_MAJOR" != "$IMAGE_MAJOR" ]; then
        die "volume $NEW_VOL holds a PostgreSQL $NEW_MAJOR cluster but the compose file asks for major $IMAGE_MAJOR; not handled by this script"
    fi
    if [ -n "$DONE_LINE" ] && [ -z "$NEW_MAJOR" ]; then
        die "volume $NEW_VOL carries the $MARKER_DONE marker but no cluster: the data directory was removed by hand; restore it from a backup"
    fi
    SRC_IMAGE="${PG_UPGRADE_SOURCE_IMAGE:-postgres:${OLD_MAJOR:-16}-alpine}"

    # ---- a completed upgrade: nothing to do, finish a pending start, or decide about a legacy cluster that ran again
    if [ -n "$DONE_LINE" ]; then
        if [ -z "$OLD_MAJOR" ]; then
            [ "$((REDO + KEEP_CURRENT))" -eq 0 ] || die "the legacy volume holds no PostgreSQL cluster: nothing to copy again or to compare with"
            finish_pending_start
            log "Nothing to do: the upgrade has completed ($DONE_LINE); the legacy volume holds no cluster any more"
            exit 0
        fi
        if [ -z "$DONE_MD5" ]; then
            if [ "$KEEP_CURRENT" -eq 1 ]; then
                take_lock
                ensure_image "$SRC_IMAGE"
                refresh_done_marker
                log "Recorded the fingerprint of the PostgreSQL $OLD_MAJOR cluster in $OLD_VOL; the PostgreSQL $IMAGE_MAJOR data counts as current"
                exit 0
            fi
            stop_with "the $MARKER_DONE marker in $NEW_VOL records no fingerprint of the legacy cluster (written by an older upgrade.sh), so a rollback to PostgreSQL $OLD_MAJOR after the upgrade cannot be ruled out." \
                "If the PostgreSQL $IMAGE_MAJOR data is the current data, record the fingerprint once:  $SCRIPT_CMD --keep-current"
        fi
        if [ "$DONE_MD5" = "$OLD_MD5" ]; then
            [ "$REDO" -eq 0 ] || die "the PostgreSQL $OLD_MAJOR cluster in $OLD_VOL has not run since the upgrade: nothing is stale, refusing to set the current database aside (--redo is for a legacy cluster that ran again, e.g. after a rollback)"
            if [ "$KEEP_CURRENT" -eq 1 ]; then
                log "The recorded fingerprint is current; nothing to record"
                exit 0
            fi
            finish_pending_start
            log "Nothing to do: the upgrade has already completed ($DONE_LINE)"
            info "The legacy volume $OLD_VOL is the rollback copy."
            legacy_delete_hint
            exit 0
        fi
        # the legacy cluster ran again after the upgrade
        ensure_image "$SRC_IMAGE"
        log "The PostgreSQL $OLD_MAJOR cluster in $OLD_VOL ran again after the upgrade; the PostgreSQL $IMAGE_MAJOR data holds the state of «$DONE_LINE»"
        describe_legacy_change
        if [ "$KEEP_CURRENT" -eq 1 ]; then
            take_lock
            refresh_done_marker
            log "Kept the PostgreSQL $IMAGE_MAJOR data and recorded the current state of the legacy cluster"
            info "Whatever PostgreSQL $OLD_MAJOR wrote after the upgrade stays only in volume $OLD_VOL."
            finish_pending_start
            exit 0
        fi
        if [ "$REDO" -eq 0 ]; then
            local lines=() line
            while IFS= read -r line; do lines+=("$line"); done < <(stale_commands)
            stop_with "the PostgreSQL $IMAGE_MAJOR data may be STALE — $VERDICT." "${lines[@]}"
        fi
        log "--redo: the PostgreSQL $OLD_MAJOR data will be copied again; the current PostgreSQL $IMAGE_MAJOR database is set aside, not dropped"
    else
        [ "$KEEP_CURRENT" -eq 0 ] || die "no completed upgrade to keep: volume $NEW_VOL has no $MARKER_DONE marker"
        if [ -z "$OLD_MAJOR" ]; then
            [ "$REDO" -eq 0 ] || die "the legacy volume holds no PostgreSQL cluster: nothing to copy"
            log "Nothing to upgrade: no PostgreSQL data in the legacy volume"
            if [ -n "$NEW_MAJOR" ]; then
                info "The stack already runs on PostgreSQL $NEW_MAJOR data in $NEW_VOL."
            else
                info "A fresh install: '${COMPOSE[*]} up -d' initialises an empty PostgreSQL $IMAGE_MAJOR cluster."
            fi
            exit 0
        fi
        if [ "$OLD_MAJOR" -ge "$IMAGE_MAJOR" ]; then
            die "the legacy cluster is PostgreSQL $OLD_MAJOR, the image is major $IMAGE_MAJOR: downgrades are not supported"
        fi
    fi

    TMP_VOL="${PROJECT}_pgupgrade_src"
    SOCK_VOL="${PROJECT}_pgupgrade_sock"
    SRC_CTR="${PROJECT}-pgupgrade-src"

    log "Plan: PostgreSQL $OLD_MAJOR ($OLD_VOL) → PostgreSQL $IMAGE_MAJOR ($NEW_VOL), database '$DB_NAME', role '$DB_USER'"
    info "source server: $SRC_IMAGE on a temporary copy of the legacy volume; target: the stack's postgres ($IMAGE)"
    take_lock

    step 'pull images'
    ensure_image "$SRC_IMAGE"
    ensure_image "$IMAGE"

    step 'check temporaries'
    if docker container inspect "$SRC_CTR" >/dev/null 2>&1; then
        if [ "$(docker container inspect "$SRC_CTR" --format '{{.State.Running}}')" = 'true' ]; then
            die "container $SRC_CTR is running: another upgrade seems to be in progress. If it is not, remove it:  docker rm -f $SRC_CTR"
        fi
        info "removing the leftovers of an interrupted run"
    fi
    cleanup_temp
    WORK="$(mktemp -d)"

    step 'stop the application'
    IN_PROGRESS=1
    local s
    while read -r s; do [ -z "$s" ] || add_stopped "$s"; done < <(running_writers)
    if [ "${#STOPPED[@]}" -gt 0 ]; then
        log "Stopping the services that use the database: ${STOPPED[*]}"
        compose stop "${STOPPED[@]}"
    else
        log "No application services are running"
    fi
    local previous
    previous="$(fact "$NEW_FACTS" wip_services)"
    if [ -n "$previous" ]; then
        info "services stopped by the interrupted run, started again at the end: $previous"
        for s in $previous; do add_stopped "$s"; done
    fi
    log "Stopping the stack's postgres"
    compose stop postgres
    # Read-only mounts (the guard mount of the stack's own postgres) are fine; a writer is not.
    local users='' c
    for c in $(docker ps -q --filter "volume=$OLD_VOL"); do
        if [ "$(docker inspect "$c" --format "{{range .Mounts}}{{if eq .Name \"$OLD_VOL\"}}{{.RW}}{{end}}{{end}}")" = 'true' ]; then
            users="$users $(docker inspect "$c" --format '{{.Name}}' | sed 's#^/##')"
        fi
    done
    [ -z "${users// /}" ] || die "the legacy volume $OLD_VOL is mounted read-write by running container(s):$users — stop them first"

    step 'mark the new volume'
    log "Writing the $MARKER_WIP marker into volume $NEW_VOL (stopped services: ${STOPPED[*]:-none}); deploy.sh refuses to start the stack while it is there"
    write_wip_marker

    step 'fingerprint the legacy cluster'
    LEGACY_MD5="$(legacy_md5)"
    [ -n "$LEGACY_MD5" ] || die "cannot read global/pg_control of the legacy cluster in $OLD_VOL"
    info "legacy cluster fingerprint (md5 of global/pg_control): $LEGACY_MD5"

    step 'initialise the new cluster'
    if [ -z "$NEW_MAJOR" ]; then
        # The stack mounts the legacy volume read-only at $NEW_MOUNT/<old>/docker on purpose (see the compose
        # files): the image's entrypoint then refuses to initialise an empty cluster next to it. Initialise the
        # cluster the way the official docker-ensure-initdb.sh does (same functions, same env, same volumes),
        # minus that one refusal — this script is the migration the refusal asks for — plus the network gate.
        log "Initialising the PostgreSQL $IMAGE_MAJOR cluster on volume $NEW_VOL"
        compose run --rm --no-deps -T --quiet-pull --user postgres --entrypoint /bin/bash \
            -e PG_UPGRADE_GATE_RULE="$GATE_RULE" -e PG_UPGRADE_GATE_NOTE="$GATE_NOTE" \
            postgres -c "$INIT_NEW_CLUSTER"
    fi

    step 'start the new postgres'
    log "Starting the stack's postgres ($IMAGE) on volume $NEW_VOL"
    compose up -d --no-deps --wait postgres >/dev/null 2>&1 || compose up -d --no-deps postgres
    wait_target_ready
    close_gate
    info "network gate closed: PostgreSQL $IMAGE_MAJOR answers only on its unix socket until the data move is verified"

    if [ "$REDO" -eq 1 ] && [ -n "$DONE_LINE" ]; then
        step 'forget the completed upgrade'
        remove_done_marker
    fi

    step 'copy the legacy volume'
    log "Copying the legacy volume into the temporary volume $TMP_VOL (the original is mounted read-only)"
    docker volume create --label "$LABEL" "$TMP_VOL" >/dev/null
    docker volume create --label "$LABEL" "$SOCK_VOL" >/dev/null
    docker run --rm --entrypoint /bin/sh -v "$OLD_VOL:/src:ro" -v "$TMP_VOL:/dst" "$SRC_IMAGE" \
        -c 'cp -a /src/. /dst/ && rm -f /dst/postmaster.pid'

    step 'start the source postgres'
    log "Starting PostgreSQL $OLD_MAJOR ($SRC_IMAGE) on the copy, unix socket only"
    docker run -d --name "$SRC_CTR" --label "$LABEL" --network none \
        -v "$TMP_VOL:/var/lib/postgresql/data" -v "$SOCK_VOL:/var/run/postgresql" \
        "$SRC_IMAGE" -c listen_addresses= >/dev/null
    SRC_ROLE="$DB_USER"
    wait_source_ready

    step 'inspect the source'
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

    step 'prepare the target database'
    local target_objects='0'
    if [ "$(printf "SELECT 1 FROM pg_database WHERE datname = :'db'" | psql_target -d postgres -v db="$DB_NAME")" = '1' ]; then
        target_objects="$(printf '%s' "$SQL_IS_EMPTY" | psql_target -d "$DB_NAME")"
        if [ "$target_objects" != '0' ] && [ "$REDO" -eq 1 ]; then
            STALE_DB="${DB_NAME}_stale_$(date -u +%Y%m%dT%H%M%SZ)"
            log "Setting the current database aside: '$DB_NAME' → '$STALE_DB' (kept for inspection, nothing is dropped)"
            # Nobody is connected: the application is stopped and the gate is closed. The healthcheck's pg_isready touches the
            # database for a moment, though, so a collision ("is being accessed by other users") gets a few retries.
            local attempt renamed=0
            for attempt in 1 2 3 4 5; do
                if printf 'ALTER DATABASE %s RENAME TO %s' "$(qi "$DB_NAME")" "$(qi "$STALE_DB")" | psql_target -d postgres; then
                    renamed=1
                    break
                fi
                sleep 2
            done
            [ "$renamed" -eq 1 ] || die "could not rename database '$DB_NAME' (still accessed by someone?); stop the clients and run this again"
            target_objects='0'
        fi
    fi
    if [ "$(printf "SELECT 1 FROM pg_database WHERE datname = :'db'" | psql_target -d postgres -v db="$DB_NAME")" != '1' ]; then
        log "Creating database '$DB_NAME' owned by '$DB_USER'"
        pg_target createdb -U "$DB_USER" -O "$DB_USER" "$DB_NAME"
    fi
    if [ "$target_objects" = '0' ]; then
        step 'dump and restore'
        log "Restoring '$src_db' into PostgreSQL $IMAGE_MAJOR (pg_dump $IMAGE_MAJOR → pg_restore --single-transaction)"
        docker run --rm --entrypoint pg_dump -v "$SOCK_VOL:/var/run/postgresql" "$IMAGE" \
            -h /var/run/postgresql -U "$SRC_ROLE" --format=custom --no-owner --no-privileges "$src_db" \
            | pg_target pg_restore -U "$DB_USER" -d "$DB_NAME" --single-transaction --exit-on-error --no-owner --no-privileges
    else
        log "Database '$DB_NAME' on PostgreSQL $IMAGE_MAJOR already has $target_objects objects (interrupted run?): verifying it instead of restoring"
    fi

    step 'verify'
    printf '%s' "$SQL_FINGERPRINT" | psql_target -d "$DB_NAME" > "$WORK/target.txt"
    if ! diff -u "$WORK/source.txt" "$WORK/target.txt" > "$WORK/diff.txt"; then
        {
            printf 'Differences between PostgreSQL %s (source, -) and PostgreSQL %s (target, +):\n' "$OLD_MAJOR" "$IMAGE_MAJOR"
            head -n 60 "$WORK/diff.txt"
        } >&2
        die "database '$DB_NAME' on PostgreSQL $IMAGE_MAJOR does not match the legacy data. If it is not data you need (e.g. migrations ran on the empty cluster), set it aside and copy again:  $SCRIPT_CMD --redo"
    fi
    log "Verified: $src_tables tables with $src_rows rows, sequences, indexes and constraints are identical"
    local enc
    enc="$(printf "SELECT pg_encoding_to_char(encoding) || ' ' || datcollate FROM pg_database WHERE datname = current_database()" | psql_target -d "$DB_NAME")"
    info "target encoding/collation: $enc (source: $(printf "SELECT pg_encoding_to_char(encoding) || ' ' || datcollate FROM pg_database WHERE datname = current_database()" | psql_source -d "$src_db"))"

    step 'analyze'
    log "Collecting planner statistics (vacuumdb --analyze-in-stages)"
    pg_target vacuumdb --analyze-in-stages -q -U "$DB_USER" -d "$DB_NAME"

    step 'finish'
    local md5_now
    md5_now="$(legacy_md5)"
    [ "$md5_now" = "$LEGACY_MD5" ] || die "the legacy cluster changed while the upgrade ran (pg_control md5 $LEGACY_MD5 → $md5_now): was PostgreSQL $OLD_MAJOR started on $OLD_VOL? Stop it and run this again"
    open_gate
    info "network gate opened: PostgreSQL $IMAGE_MAJOR accepts network connections again"
    write_done_marker "$(printf 'upgraded %s → %s at %s: %s tables, %s rows, database %s, source volume %s' \
        "$OLD_MAJOR" "$IMAGE_MAJOR" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$src_tables" "$src_rows" "$DB_NAME" "$OLD_VOL")"
    docker rm -f "$SRC_CTR" >/dev/null
    cleanup_temp

    step 'start the application'
    start_stopped_services
    remove_wip_marker

    log "Done: PostgreSQL $IMAGE_MAJOR serves '$DB_NAME' from volume $NEW_VOL"
    info "The legacy volume $OLD_VOL is untouched and stays as the rollback copy."
    legacy_delete_hint
    if [ -n "$STALE_DB" ]; then
        info "The previous PostgreSQL $IMAGE_MAJOR database is kept as '$STALE_DB' (what the application wrote after the first upgrade)."
        info "Inspect it, then drop it:  ${COMPOSE[*]} exec postgres dropdb -U $DB_USER $STALE_DB"
    fi
}

if [ "$CHECK_ONLY" -eq 1 ]; then
    run_check
else
    run_upgrade
fi
