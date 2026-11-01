#!/bin/sh
# Entrypoint wrapper of the stack's postgres service (docker-compose.yml and docker-compose.prod.yml mount this file
# read-only and name it as `entrypoint`): refuses to start PostgreSQL 18+ on data that may be STALE, otherwise hands over
# to the official entrypoint unchanged.
#
# Why. docker/postgres/upgrade.sh moves the PostgreSQL 16 data into this volume and records in its done marker a
# fingerprint of the 16 cluster - the md5 of global/pg_control, a file that server rewrites whenever it runs. The compose
# files keep the legacy volume mounted read-only at /var/lib/postgresql/16/docker. When PostgreSQL 16 runs on that volume
# again (a rollback deploy, an `up -d` from an old checkout), the fingerprint changes and the 18 data lacks whatever 16
# wrote: `upgrade.sh --check` then stops deploy.sh. Anything that bypasses deploy.sh - an old deploy.sh, `make prod-up`,
# a plain `docker compose up -d` - still starts this container; this guard makes it exit instead, so the application
# waits for a healthy postgres that never comes: downtime, never a silent return to the old state.
# See docs/DEVOPS_GUIDE.md §0, «Откат и его цена».
#
# Lets the start through when: there is no done marker (fresh install, or no move yet - the read-only legacy mount then
# makes the official entrypoint refuse next to unmoved 16 data), the marker has no fingerprint (older upgrade.sh;
# --check handles it), no legacy cluster is mounted (volume deleted or recreated empty), the fingerprint is unchanged, or
# the in-progress marker says redo=1 (a --redo at work: it starts postgres before it rewrites the done marker, behind the
# pg_hba.conf gate it closed first, so nothing can use the database it replaces). Any other in-progress marker (redo=0: a
# move whose services were not started yet) does not lift the check - left behind, it would otherwise switch this guard
# off for good, and a later rollback to 16 would start 18 on a stale snapshot. --keep-current rewrites the fingerprint
# before it starts anything; a finished --redo rewrites it before its marker turns into redo=0. upgrade.sh's own one-off
# containers use --entrypoint and never pass through here. Busybox sh: `md5sum`, `sed`, `grep`, `cut` are in the image.
set -eu

ROOT=/var/lib/postgresql
DONE="$ROOT/lexiflow-upgrade.done"
WIP="$ROOT/lexiflow-upgrade.in-progress"
UPGRADE_CMD="${LEXIFLOW_UPGRADE_CMD:-bash docker/postgres/upgrade.sh}"   # set by the compose file: with --prod in production
UP_CMD="${LEXIFLOW_UP_CMD:-docker compose up -d}"                       # set by the compose file: the deploy in production

redo=0
[ -e "$WIP" ] && grep -qx 'redo=1' "$WIP" 2>/dev/null && redo=1
if [ -s "$DONE" ] && [ "$redo" -eq 0 ]; then
    recorded="$(sed -n 's/^legacy_pg_control_md5=//p' "$DONE" | head -n 1)"
    for dir in "$ROOT"/*/docker; do
        [ -n "$recorded" ] || break
        [ -s "$dir/PG_VERSION" ] && [ -s "$dir/global/pg_control" ] || continue
        legacy="$(cat "$dir/PG_VERSION")"
        [ "$legacy" != "${PG_MAJOR:-}" ] || continue   # the cluster this image serves, not a legacy one
        current="$(md5sum < "$dir/global/pg_control" | cut -d' ' -f1)"
        [ "$current" != "$recorded" ] || continue
        cat >&2 <<EOF
lexiflow-upgrade guard: refusing to start PostgreSQL ${PG_MAJOR:-18} - its data may be STALE.
  The PostgreSQL $legacy cluster in the legacy volume (mounted at $dir) has run since its data was moved to PostgreSQL ${PG_MAJOR:-18}:
  the md5 of its global/pg_control is $current, the done marker ($DONE) recorded $recorded.
  A rollback to $legacy probably wrote data that the PostgreSQL ${PG_MAJOR:-18} copy lacks; starting now would silently put the
  application back on the old state. Decide which data is the truth, from the project directory on the host:
    $UPGRADE_CMD                  shows what changed on PostgreSQL $legacy (committed transactions, or just a start)
    $UPGRADE_CMD --redo           $legacy is the truth: its data is copied again, the current ${PG_MAJOR:-18} database is set aside (kept)
    $UPGRADE_CMD --keep-current   ${PG_MAJOR:-18} is the truth: its data stays, the new state of $legacy is recorded
  Then start the stack again:  $UP_CMD   Details: docs/DEVOPS_GUIDE.md, «Откат и его цена».
EOF
        exit 1
    done
fi

exec docker-entrypoint.sh "$@"
