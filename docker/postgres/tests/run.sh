#!/usr/bin/env bash
# Real-Docker scenarios for docker/postgres/upgrade.sh (CI job `postgres-upgrade`; runnable locally too).
#
#   bash docker/postgres/tests/run.sh
#
# Builds nothing: the application services are busybox stand-ins (compose.test.yml), PostgreSQL and Redis are the
# official images the stack uses. Own compose project `lexiflow-pgtest`, no published ports; everything it creates is
# removed at the end (`down -v`, throwaway containers, temporary volumes). Every check prints PASS/FAIL with the
# expected and the actual value; an unexpected error aborts with the scenario and the failing command.
#
# Scenarios (docs/DEVOPS_GUIDE.md §0, review items 🔴1 and 🔴2):
#   1  PostgreSQL 16 data in the legacy layout (jsonb with Cyrillic, sequences ahead of the rows, FK/CHECK/UNIQUE,
#      expression index) → `--check` = 1 "has not been moved"
#   2  a plain `up -d` over unmigrated 16 data: the read-only guard mount makes postgres 18 refuse to initialise
#   7  the lock shared with deploy.sh: a second run gives up after DEPLOY_LOCK_WAIT without touching anything;
#      `--check` is not blocked by the lock
#   3  the upgrade through `-- <compose args>`: counts, sequences, constraints identical; `--check` = 0; a repeat is a
#      no-op; the stand-ins run again; the network gate is open
#   4  🔴1 a PostgreSQL 18 cluster exists but no done marker (the hole left by an interrupted run of the old script):
#      `--check` = 1 "never completed"; upgrade.sh finishes the move
#   4b a run that fails right after the new cluster is up (PG_UPGRADE_FAIL_AT): the in-progress marker already lists
#      the stopped services, the gate rejects a network client even after a manual `up -d`; the resume starts the
#      services the failed run stopped
#   5  🔴2 rollback to 16 and forward: PostgreSQL 16 runs on the legacy volume and writes a row → `--check` = 1 STALE,
#      upgrade.sh without a flag stops, `--redo` copies again (the row is on 18, the old 18 database is kept aside);
#      `--keep-current` keeps 18; a start of 16 without writes is told apart from a start with writes;
#      `--redo` on an unchanged legacy cluster is refused
#   6  a fresh install without a legacy volume: `--check` = 0, `up -d` initialises 18, `--check` = 0

set -Eeuo pipefail

cd "$(cd "$(dirname "$0")" && pwd)/../../.."

PROJECT="${PGTEST_PROJECT:-lexiflow-pgtest}"
FILES=(-f docker-compose.yml -f docker/postgres/tests/compose.test.yml --env-file docker/postgres/tests/test.env)
COMPOSE=(docker compose -p "$PROJECT" "${FILES[@]}")
UPGRADE=(bash docker/postgres/upgrade.sh -- -p "$PROJECT" "${FILES[@]}")
LEGACY_VOL="${PROJECT}_pgdata"
NEW_VOL="${PROJECT}_postgresql"
NETWORK="${PROJECT}_default"
PG_CTR='lexiflow-pgtest-postgres'          # container_name of the postgres service in compose.test.yml
DB='lexiflow'; DB_USER='lexiflow'; DB_PASS='secret'   # docker/postgres/tests/test.env
SEED_CTR="${PROJECT}-seed16"
ROLLBACK_CTR="${PROJECT}-rollback16"
INIT18_CTR="${PROJECT}-init18"
MARKER_DONE='lexiflow-upgrade.done'
MARKER_WIP='lexiflow-upgrade.in-progress'

TMP="$(mktemp -d)"
export DEPLOY_LOCK_FILE="$TMP/deploy.lock"
export PG_UPGRADE_TIMEOUT="${PG_UPGRADE_TIMEOUT:-120}"

PASS_N=0; FAIL_N=0; FAILED=()
SCENARIO='setup'
EXPECTING_FAILURE=0
OUT=''; RC=0
EXPECTED=''

compose() { "${COMPOSE[@]}" "$@"; }
upgrade() { "${UPGRADE[@]}" "$@"; }
scenario() { SCENARIO="$1"; printf '\n=== %s ===\n' "$1"; }
pass() { PASS_N=$((PASS_N + 1)); printf 'PASS  %s\n' "$1"; }
fail() {
    FAIL_N=$((FAIL_N + 1)); FAILED+=("$SCENARIO: $1")
    printf 'FAIL  %s\n      expected: %s\n      actual:   %s\n' "$1" "$2" "$3"
}
expect_eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "$2" "$3"; fi; }
expect_contains() {
    if grep -qF -- "$2" <<<"$3"; then pass "$1"; else fail "$1" "output containing «$2»" "$(tail -n 12 <<<"$3")"; fi
}
expect_not_contains() {
    if grep -qF -- "$2" <<<"$3"; then fail "$1" "output without «$2»" "$(grep -F -- "$2" <<<"$3" | head -n 3)"; else pass "$1"; fi
}
# Run a command whose failure is part of the scenario: OUT = merged output, RC = exit code, never aborts.
run_capture() {
    EXPECTING_FAILURE=1
    set +e
    OUT="$("$@" 2>&1)"
    RC=$?
    set -e
    EXPECTING_FAILURE=0
}
on_err() {  # an unexpected failure: name the scenario and the command (the EXIT trap then reports ABORTED)
    [ "$EXPECTING_FAILURE" -eq 0 ] || return 0
    printf '\nERROR in scenario «%s» (line %s): %s\n' "$SCENARIO" "$1" "$2" >&2
}
trap 'on_err $LINENO "$BASH_COMMAND"' ERR

cleanup() {
    local status=$1
    set +e
    EXPECTING_FAILURE=1
    if [ "$status" -ne 0 ] || [ "$FAIL_N" -gt 0 ]; then
        printf '\n--- state at the end (for the failure above) ---\n'
        compose ps -a 2>&1 | tail -n 20
        compose logs --tail 40 postgres 2>&1 | tail -n 40
        docker ps -a --filter "name=${PROJECT}" --format '{{.Names}}  {{.Status}}' 2>&1
    fi
    printf '\n--- cleanup ---\n'
    docker rm -f "$SEED_CTR" "$ROLLBACK_CTR" "$INIT18_CTR" "${PROJECT}-pgupgrade-src" >/dev/null 2>&1
    compose down -v --remove-orphans >/dev/null 2>&1
    docker volume rm "${PROJECT}_pgupgrade_src" "${PROJECT}_pgupgrade_sock" >/dev/null 2>&1
    rm -rf "$TMP"
    printf '\n%s passed, %s failed\n' "$PASS_N" "$FAIL_N"
    local f
    for f in "${FAILED[@]:-}"; do [ -z "$f" ] || printf '  FAILED: %s\n' "$f"; done
    if [ "$status" -ne 0 ]; then printf 'ABORTED in scenario «%s» (exit %s)\n' "$SCENARIO" "$status"; exit "$status"; fi
    [ "$FAIL_N" -eq 0 ] || exit 1
}
trap 'cleanup $?' EXIT

# ------------------------------------------------------------------------------------------------------------------
# helpers
# ------------------------------------------------------------------------------------------------------------------
# The official entrypoint runs a socket-only temporary server during init (pg_isready answers, then it stops), so the
# real server is up only once PID 1 is `postgres` and a query works.
wait_pg() {  # $1 container, $2 user
    local i
    for i in $(seq 1 90); do
        if [ "$(docker exec "$1" cat /proc/1/comm 2>/dev/null || true)" = 'postgres' ] \
            && docker exec "$1" pg_isready -q -U "$2" 2>/dev/null \
            && docker exec "$1" psql -X -U "$2" -d postgres -Atc 'SELECT 1' >/dev/null 2>&1; then
            return 0
        fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" != 'true' ]; then
            docker logs --tail 20 "$1" >&2 || true
            echo "container $1 exited" >&2
            return 1
        fi
        sleep 1
    done
    echo "container $1 did not become ready" >&2
    return 1
}
wait_stopped() {  # $1 container: wait until it is no longer running
    local i
    for i in $(seq 1 60); do
        [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)" = 'true' ] || return 0
        sleep 1
    done
    return 1
}
stop_rm() { docker stop "$1" >/dev/null; docker rm "$1" >/dev/null; }   # STOPSIGNAL of the image = SIGINT: a clean shutdown

running_services() { compose ps --services --status running 2>/dev/null | sort | tr '\n' ' ' | sed 's/ $//'; }
volume_file() {  # $1 volume, $2 path inside it; prints the file or nothing
    docker run --rm -v "$1:/v:ro" busybox:stable sh -c "cat /v/$2 2>/dev/null || true"
}
volume_has() { [ -n "$(docker run --rm -v "$1:/v:ro" busybox:stable sh -c "[ -e /v/$2 ] && echo yes || true")" ]; }
labelled_volume() {  # $1 name, $2 compose key: the volume as compose would have created it
    docker volume create --label "com.docker.compose.project=$PROJECT" --label "com.docker.compose.volume=$2" "$1" >/dev/null
}

SQL_SEED=$(cat <<'SQL'
CREATE TABLE users (
    id bigserial PRIMARY KEY,
    name text NOT NULL CONSTRAINT users_name_check CHECK (length(name) > 0),
    meta jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE words (
    id bigserial PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    term text NOT NULL,
    translation jsonb,
    CONSTRAINT words_user_term_unique UNIQUE (user_id, term)
);
CREATE INDEX words_term_idx ON words (lower(term));
INSERT INTO users (name, meta)
SELECT 'user ' || g, jsonb_build_object('город', 'Ташкент', 'n', g, 'ёж', 'ёжик № ' || g)
FROM generate_series(1, 1000) AS g;
INSERT INTO words (user_id, term, translation)
SELECT (g % 1000) + 1, 'term' || g, jsonb_build_object('ru', 'слово ' || g, 'uz', 'soʻz ' || g)
FROM generate_series(1, 5000) AS g;
DELETE FROM users WHERE id > 950;      -- cascades into words: the sequences stay ahead of the row counts
SELECT setval('users_id_seq', 1500);   -- a sequence that is neither max(id) nor 1
SQL
)
# The same probe on 16 and on 18: counts, sequence positions, Cyrillic jsonb round trip, constraints, the index.
SQL_PROBE=$(cat <<'SQL'
SELECT (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM words) || ' ' ||
       (SELECT last_value FROM users_id_seq) || ' ' || (SELECT last_value FROM words_id_seq) || ' ' ||
       (SELECT meta->>'город' FROM users WHERE id = 1) || ' ' || (SELECT translation->>'ru' FROM words WHERE id = 1) || ' ' ||
       (SELECT count(*) FROM pg_constraint WHERE conname IN ('words_user_id_fkey', 'users_name_check', 'words_user_term_unique')) || ' ' ||
       (SELECT count(*) FROM pg_indexes WHERE indexname = 'words_term_idx')
SQL
)

seed_legacy_16() {  # a PostgreSQL 16 cluster in the ≤16 layout of the legacy volume; sets EXPECTED from the 16 side
    labelled_volume "$LEGACY_VOL" pgdata
    docker run -d --name "$SEED_CTR" -e POSTGRES_DB="$DB" -e POSTGRES_USER="$DB_USER" -e POSTGRES_PASSWORD="$DB_PASS" \
        -v "$LEGACY_VOL:/var/lib/postgresql/data" postgres:16-alpine >/dev/null
    wait_pg "$SEED_CTR" "$DB_USER"
    printf '%s' "$SQL_SEED" | docker exec -i "$SEED_CTR" psql -X -v ON_ERROR_STOP=1 -q -U "$DB_USER" -d "$DB" >/dev/null
    EXPECTED="$(printf '%s' "$SQL_PROBE" | docker exec -i "$SEED_CTR" psql -X -v ON_ERROR_STOP=1 -At -U "$DB_USER" -d "$DB")"
    stop_rm "$SEED_CTR"
    expect_eq "fixture: 16 holds 950 users, 4750 words, users_id_seq at 1500, Cyrillic jsonb, 3 constraints, 1 index" \
        '950 4750 1500 5000 Ташкент слово 1 3 1' "$EXPECTED"
}
probe_18() { printf '%s' "$SQL_PROBE" | compose exec -T postgres psql -X -v ON_ERROR_STOP=1 -At -U "$DB_USER" -d "$DB"; }
sql_18() { printf '%s' "$1" | compose exec -T postgres psql -X -v ON_ERROR_STOP=1 -At -U "$DB_USER" -d "${2:-$DB}"; }
# A network client, like the application: another container on the stack's network, by the service name.
tcp_probe() {
    docker run --rm --network "$NETWORK" -e PGPASSWORD="$DB_PASS" postgres:18-alpine \
        psql -w -X -h postgres -U "$DB_USER" -d postgres -Atc 'SELECT 1' 2>&1 || true
}
# PostgreSQL 16 on the legacy volume, read-write, as a rollback to the old compose would run it.
rollback_16() {  # $1 = SQL to run there (may be empty), $@ after it = extra postgres options
    local sql="$1"; shift
    docker run -d --name "$ROLLBACK_CTR" -v "$LEGACY_VOL:/var/lib/postgresql/data" postgres:16-alpine "$@" >/dev/null
    wait_pg "$ROLLBACK_CTR" "$DB_USER"
    [ -z "$sql" ] || printf '%s' "$sql" | docker exec -i "$ROLLBACK_CTR" psql -X -v ON_ERROR_STOP=1 -q -U "$DB_USER" -d "$DB" >/dev/null
    stop_rm "$ROLLBACK_CTR"
}
reset_stack() {
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    docker volume rm "$LEGACY_VOL" "$NEW_VOL" >/dev/null 2>&1 || true
}
guard_attempt() {  # `up -d postgres` over unmigrated 16 data: must exit without initialising; leaves the (labelled) new volume
    compose up -d postgres >/dev/null 2>&1 || true
    wait_stopped "$PG_CTR" || true
    run_capture compose logs postgres
    expect_contains "$1: the 18 entrypoint refuses next to the 16 data (guard mount)" 'there appears to be PostgreSQL data in' "$OUT"
    expect_eq "$1: no 18 cluster was initialised" 'no' "$(volume_has "$NEW_VOL" 18/docker/PG_VERSION && echo yes || echo no)"
    compose rm -sf postgres >/dev/null 2>&1 || true
}

# ------------------------------------------------------------------------------------------------------------------
printf 'docker: %s\ncompose: %s\n' "$(docker version --format '{{.Server.Version}}')" "$(docker compose version --short)"
scenario 'setup: images and a clean slate'
for img in postgres:16-alpine postgres:18-alpine redis:8.4-alpine busybox:stable; do
    docker image inspect "$img" >/dev/null 2>&1 || docker pull -q "$img" >/dev/null
done
reset_stack
compose config -q
pass 'the test compose project renders'

# ------------------------------------------------------------------------------------------------------------------
scenario '1. PostgreSQL 16 data in the legacy layout'
seed_legacy_16
run_capture upgrade --check
expect_eq '--check exits 1' 1 "$RC"
expect_contains '--check names the unmigrated data' 'has not been moved to the 18+ layout' "$OUT"
expect_contains '--check sees the 16 cluster' 'PostgreSQL 16 cluster (≤16 layout)' "$OUT"

# ------------------------------------------------------------------------------------------------------------------
scenario '2. a plain `up -d` over unmigrated 16 data'
guard_attempt 'guard'
run_capture upgrade --check
expect_eq '--check still exits 1 with an empty new volume' 1 "$RC"
expect_contains '--check: the new volume has no cluster yet' 'has no cluster yet' "$OUT"

# ------------------------------------------------------------------------------------------------------------------
scenario '7. the lock shared with deploy.sh'
compose up -d app nginx redis >/dev/null 2>&1
expect_eq 'stand-ins are running' 'app nginx redis' "$(running_services)"
# The holder becomes `sleep` itself (exec), so killing it closes the descriptor and releases the lock.
( exec 9>>"$DEPLOY_LOCK_FILE"; flock 9; printf 'pid=%s started=test command=holder\n' "$$" >&9; exec sleep 120 ) &
HOLDER=$!
for _ in $(seq 1 50); do ( exec 9>>"$DEPLOY_LOCK_FILE"; flock -n 9 ) 2>/dev/null || break; sleep 0.2; done
expect_eq 'the test holds the lock' 'held' "$( ( exec 9>>"$DEPLOY_LOCK_FILE"; flock -n 9 ) 2>/dev/null && echo free || echo held)"
start=$SECONDS
run_capture env DEPLOY_LOCK_WAIT=2 "${UPGRADE[@]}"
elapsed=$((SECONDS - start))
expect_eq 'upgrade.sh gives up on a held lock (exit 2)' 2 "$RC"
expect_contains 'upgrade.sh says who holds the lock and waits DEPLOY_LOCK_WAIT' 'still held after 2s' "$OUT"
expect_contains 'upgrade.sh printed the holder' 'command=holder' "$OUT"
expect_eq 'it waited about DEPLOY_LOCK_WAIT, not longer' 'yes' "$([ "$elapsed" -ge 2 ] && [ "$elapsed" -le 30 ] && echo yes || echo "no ($elapsed s)")"
expect_eq 'nothing was stopped' 'app nginx redis' "$(running_services)"
expect_eq 'no in-progress marker was written' 'no' "$(volume_has "$NEW_VOL" "$MARKER_WIP" && echo yes || echo no)"
run_capture upgrade --check
expect_eq '--check is not blocked by the lock (exit 1 by state)' 1 "$RC"
expect_not_contains '--check did not wait for the lock' 'Waiting for the lock' "$OUT"
kill "$HOLDER" 2>/dev/null || true
wait "$HOLDER" 2>/dev/null || true
for _ in $(seq 1 50); do ( exec 9>>"$DEPLOY_LOCK_FILE"; flock -n 9 ) 2>/dev/null && break; sleep 0.2; done
expect_eq 'the lock is free again' 'free' "$( ( exec 9>>"$DEPLOY_LOCK_FILE"; flock -n 9 ) 2>/dev/null && echo free || echo held)"

# ------------------------------------------------------------------------------------------------------------------
scenario '3. the upgrade (dev variant through `-- <compose args>`)'
run_capture upgrade
expect_eq 'upgrade.sh exits 0' 0 "$RC"
expect_contains 'upgrade.sh verified the data' 'Verified: 2 tables with 5700 rows' "$OUT"
expect_contains 'upgrade.sh reports done' 'Done: PostgreSQL 18 serves' "$OUT"
expect_eq 'counts, sequences, jsonb, constraints and index on 18 equal 16' "$EXPECTED" "$(probe_18)"
expect_eq 'the stand-ins run again' 'app nginx postgres redis' "$(running_services)"
expect_eq 'a network client can connect (gate open)' 1 "$(tcp_probe)"
expect_eq 'no gate rule left in pg_hba.conf' 0 "$(volume_file "$NEW_VOL" 18/docker/pg_hba.conf | grep -c '^host all all all reject' || true)"
expect_contains 'the done marker records the legacy fingerprint' 'legacy_pg_control_md5=' "$(volume_file "$NEW_VOL" "$MARKER_DONE")"
expect_contains 'the done marker records NextXID' 'legacy_next_xid=' "$(volume_file "$NEW_VOL" "$MARKER_DONE")"
expect_eq 'the in-progress marker is gone' 'no' "$(volume_has "$NEW_VOL" "$MARKER_WIP" && echo yes || echo no)"
expect_eq 'the temporary volumes are gone' '' "$(docker volume ls -q --filter "name=${PROJECT}_pgupgrade" | tr '\n' ' ')"
run_capture upgrade --check
expect_eq '--check exits 0' 0 "$RC"
expect_contains '--check: data in place, legacy volume is the rollback copy' 'OK: PostgreSQL 18 data is in place; the legacy PostgreSQL 16 volume' "$OUT"
expect_contains '--check: fingerprint unchanged' 'legacy fingerprint: unchanged since the upgrade' "$OUT"
expect_contains '--check: deletion needs the container removed first' 'rm -sf postgres && docker volume rm' "$OUT"
run_capture upgrade
expect_eq 'a repeat exits 0' 0 "$RC"
expect_contains 'a repeat is a no-op' 'Nothing to do: the upgrade has already completed' "$OUT"
expect_eq 'the stand-ins still run' 'app nginx postgres redis' "$(running_services)"

# ------------------------------------------------------------------------------------------------------------------
scenario '4. 🔴1 a PostgreSQL 18 cluster exists, no done marker (the hole of an interrupted run)'
reset_stack
seed_legacy_16
compose up -d app nginx redis >/dev/null 2>&1
guard_attempt 'guard again'
# What the old script left behind after a Ctrl+C between init and its marker: an initialised 18 cluster with the empty
# POSTGRES_DB and nothing else. Made the same way, with the plain entrypoint on the new volume (no guard mount here).
docker run -d --name "$INIT18_CTR" -e POSTGRES_DB="$DB" -e POSTGRES_USER="$DB_USER" -e POSTGRES_PASSWORD="$DB_PASS" \
    -v "$NEW_VOL:/var/lib/postgresql" postgres:18-alpine >/dev/null
wait_pg "$INIT18_CTR" "$DB_USER"
stop_rm "$INIT18_CTR"
expect_eq 'state: an 18 cluster without markers' 'yes no no' \
    "$(volume_has "$NEW_VOL" 18/docker/PG_VERSION && echo yes || echo no) $(volume_has "$NEW_VOL" "$MARKER_DONE" && echo yes || echo no) $(volume_has "$NEW_VOL" "$MARKER_WIP" && echo yes || echo no)"
run_capture upgrade --check
expect_eq '--check exits 1' 1 "$RC"
expect_contains '--check: the move was never completed' 'was never completed' "$OUT"
expect_not_contains '--check: not the "not moved" text' 'has not been moved to the 18+ layout' "$OUT"
run_capture upgrade
expect_eq 'upgrade.sh finishes the move (exit 0)' 0 "$RC"
expect_contains 'upgrade.sh restored into the empty database' 'Restoring' "$OUT"
expect_eq 'the data on 18 equals 16' "$EXPECTED" "$(probe_18)"
expect_eq 'a network client can connect' 1 "$(tcp_probe)"
expect_eq 'the stand-ins run again' 'app nginx postgres redis' "$(running_services)"
run_capture upgrade --check
expect_eq '--check exits 0 afterwards' 0 "$RC"

# ------------------------------------------------------------------------------------------------------------------
scenario '4b. a run that fails right after the new cluster is up (marker before init, network gate)'
reset_stack
seed_legacy_16
compose up -d app nginx redis >/dev/null 2>&1
run_capture env PG_UPGRADE_FAIL_AT='copy the legacy volume' "${UPGRADE[@]}"
expect_eq 'the injected failure exits 2' 2 "$RC"
expect_contains 'the failure names the step' 'FAILED during step "copy the legacy volume"' "$OUT"
expect_contains 'the failure explains the marker and the gate' 'rejects network connections (pg_hba gate)' "$OUT"
expect_eq 'the stand-ins were stopped' 'postgres redis' "$(running_services)"
WIP="$(volume_file "$NEW_VOL" "$MARKER_WIP")"
expect_contains 'the in-progress marker was written (before init)' 'from=16 ' "$WIP"
expect_eq 'the in-progress marker lists the stopped services' 'app nginx' "$(sed -n 's/^stopped_services=//p' <<<"$WIP" | tr ' ' '\n' | sort | tr '\n' ' ' | sed 's/ $//')"
expect_eq 'the gate rule is the first rule of pg_hba.conf' 'host all all all reject' "$(volume_file "$NEW_VOL" 18/docker/pg_hba.conf | grep -v '^#' | grep -m1 .)"
run_capture upgrade --check
expect_eq '--check exits 1' 1 "$RC"
expect_contains '--check: in progress or interrupted' 'in progress or was interrupted' "$OUT"
expect_contains '--check: shows the closed gate' 'network gate: CLOSED' "$OUT"
# the hole of a manual `up -d`: the cluster exists, so the entrypoint starts; the gate keeps every network client out
compose up -d postgres >/dev/null 2>&1
wait_pg "$PG_CTR" "$DB_USER"
expect_contains 'a network client is rejected by the gate' 'rejects connection' "$(tcp_probe)"
run_capture upgrade
expect_eq 'the resume exits 0' 0 "$RC"
expect_contains 'the resume knew the services of the failed run' 'services stopped by the interrupted run, started again at the end:' "$OUT"
expect_eq 'the stand-ins run again (list carried by the marker written before init)' 'app nginx postgres redis' "$(running_services)"
expect_eq 'the data on 18 equals 16' "$EXPECTED" "$(probe_18)"
expect_eq 'a network client can connect again' 1 "$(tcp_probe)"
run_capture upgrade --check
expect_eq '--check exits 0 afterwards' 0 "$RC"

# ------------------------------------------------------------------------------------------------------------------
scenario '5. 🔴2 rollback to 16 and forward again'
# round 1: 16 runs on the legacy volume and writes D1 (what a rollback deploy does), then the stack goes forward
rollback_16 "INSERT INTO users (name, meta) VALUES ('D1', '{\"after\": \"rollback\"}')"
run_capture upgrade --check
expect_eq '--check exits 1 after 16 ran again' 1 "$RC"
expect_contains '--check: the 18 data may be stale' 'may be STALE' "$OUT"
expect_contains '--check: offers --redo' '--redo' "$OUT"
expect_contains '--check: offers --keep-current' '--keep-current' "$OUT"
run_capture upgrade
expect_eq 'upgrade.sh without a flag stops (exit 1)' 1 "$RC"
expect_contains 'upgrade.sh saw the committed transactions' 'transactions were committed on PostgreSQL 16 after the upgrade' "$OUT"
expect_contains 'upgrade.sh shows NextXID' 'NextXID:' "$OUT"
expect_eq 'nothing was stopped by the refusal' 'app nginx postgres redis' "$(running_services)"
run_capture upgrade --redo
expect_eq '--redo exits 0' 0 "$RC"
expect_contains '--redo set the old 18 database aside' "Setting the current database aside: '$DB' → '${DB}_stale_" "$OUT"
expect_eq 'D1 is on 18 now' 1 "$(sql_18 "SELECT count(*) FROM users WHERE name = 'D1'")"
expect_eq 'the other rows are intact (951 users)' '951' "$(sql_18 'SELECT count(*) FROM users')"
expect_eq 'the previous 18 database is kept aside' 1 "$(sql_18 "SELECT count(*) FROM pg_database WHERE datname LIKE '${DB}_stale_%'" postgres)"
expect_eq 'the stand-ins run again' 'app nginx postgres redis' "$(running_services)"
run_capture upgrade --check
expect_eq '--check exits 0 after --redo' 0 "$RC"
run_capture upgrade --redo
expect_eq '--redo on an unchanged legacy cluster is refused (exit 2)' 2 "$RC"
expect_contains '--redo refusal explains itself' 'nothing is stale' "$OUT"
expect_eq 'the refusal changed nothing (951 users)' '951' "$(sql_18 'SELECT count(*) FROM users')"
# round 2: 16 writes D2, but 18 is the truth
rollback_16 "INSERT INTO users (name) VALUES ('D2')"
run_capture upgrade --check
expect_eq '--check exits 1 again' 1 "$RC"
run_capture upgrade --keep-current
expect_eq '--keep-current exits 0' 0 "$RC"
expect_contains '--keep-current kept 18' 'Kept the PostgreSQL 18 data' "$OUT"
expect_eq 'D2 is not on 18' 0 "$(sql_18 "SELECT count(*) FROM users WHERE name = 'D2'")"
run_capture upgrade --check
expect_eq '--check exits 0 after --keep-current' 0 "$RC"
run_capture upgrade
expect_eq 'the plain run is a no-op again' 0 "$RC"
expect_contains 'the plain run says so' 'Nothing to do' "$OUT"
# round 3: 16 only started (a "quick look"), nothing written
rollback_16 '' -c autovacuum=off
run_capture upgrade --check
expect_eq '--check exits 1 after a mere start of 16' 1 "$RC"
run_capture upgrade
expect_eq 'upgrade.sh stops (exit 1)' 1 "$RC"
expect_contains 'upgrade.sh tells a start apart from writes' 'no transaction wrote anything (NextXID unchanged)' "$OUT"
run_capture upgrade --keep-current
expect_eq '--keep-current exits 0' 0 "$RC"
run_capture upgrade --check
expect_eq '--check exits 0' 0 "$RC"
expect_eq 'the stand-ins still run' 'app nginx postgres redis' "$(running_services)"

# ------------------------------------------------------------------------------------------------------------------
scenario '6. a fresh install without a legacy volume'
reset_stack
run_capture upgrade --check
expect_eq '--check exits 0' 0 "$RC"
expect_contains '--check: fresh install' 'a fresh install' "$OUT"
run_capture upgrade
expect_eq 'upgrade.sh exits 0' 0 "$RC"
expect_contains 'upgrade.sh: nothing to upgrade' 'Nothing to upgrade' "$OUT"
compose up -d postgres >/dev/null 2>&1
wait_pg "$PG_CTR" "$DB_USER"
expect_eq 'postgres 18 initialised an empty cluster' 'yes' "$(volume_has "$NEW_VOL" 18/docker/PG_VERSION && echo yes || echo no)"
expect_eq 'the database exists' 1 "$(sql_18 "SELECT 1 FROM pg_database WHERE datname = '$DB'" postgres)"
run_capture upgrade --check
expect_eq '--check exits 0 with the cluster' 0 "$RC"
expect_contains '--check: data in place' 'OK: PostgreSQL 18 data is in place' "$OUT"
expect_not_contains '--check: no rollback copy mentioned' 'rollback copy' "$OUT"
