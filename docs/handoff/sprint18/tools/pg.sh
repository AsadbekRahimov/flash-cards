#!/usr/bin/env bash
# Private PostgreSQL 16 cluster for PG-only tests (not part of the repo).
# Usage: pg.sh start <name> <port>   -> prints env to export (DB_CONNECTION=pgsql ...)
#        pg.sh stop  <name>
set -euo pipefail
BIN=/usr/lib/postgresql/16/bin
BASE=${PG_BASE:-/tmp/lexiflow-pg}
cmd=$1; name=$2; DATA="$BASE/$name"
U="unshare --user --map-user=1000 --map-group=1000"
case "$cmd" in
  start)
    port=$3
    mkdir -p "$BASE"; chmod 777 "$BASE"
    if [ ! -d "$DATA" ]; then
      mkdir -p "$DATA"; chmod 777 "$DATA"
      $U $BIN/initdb -D "$DATA" -U lexiflow --auth=trust -E UTF8 --locale=C.UTF-8 >/dev/null
    fi
    $U $BIN/pg_ctl -D "$DATA" -o "-p $port -c unix_socket_directories= -c listen_addresses=127.0.0.1 -c fsync=off -c full_page_writes=off -c max_connections=200" -l "$DATA/log" start >/dev/null
    sleep 2
    $U $BIN/psql -h 127.0.0.1 -p "$port" -U lexiflow -d postgres -c "CREATE DATABASE lexiflow" >/dev/null 2>&1 || true
    echo "export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=lexiflow DB_USERNAME=lexiflow DB_PASSWORD="
    ;;
  stop)
    $U $BIN/pg_ctl -D "$DATA" stop -m fast >/dev/null || true
    rm -rf "$DATA"
    ;;
esac
