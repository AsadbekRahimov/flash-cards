#!/usr/bin/env bash
# The stand of the browser e2e of the practice and the battle (docs/16 §14.4): a separate SQLite, the demo seed, two pairs of friends,
# the TWA built into public/twa and PHP's built-in server that serves the TWA and the API from ONE origin. From scratch, one command:
#
#   resources/twa/scripts/e2e/stand.sh up       # database, seed, friends, build, server
#   resources/twa/scripts/e2e/stand.sh status
#   resources/twa/scripts/e2e/stand.sh sweep    # one run of the janitor of the battles (what the scheduler does every minute in production)
#   resources/twa/scripts/e2e/stand.sh down     # stops the server, removes what `up` made
#
# The game of the caravan (docs/05 §5b.4) is open to everybody on the stand (GAME_ACCESS=all); the practice is in its pilot.
# Settings (environment, all optional): E2E_DIR (stand directory, default $TMPDIR/lexiflow-e2e), E2E_PORT (8010),
# E2E_GRACE (PRACTICE_BATTLE_PRESENCE_GRACE, seconds; the server default 30 is kept when empty).
# Nothing in .env is edited: the settings go through the environment of the commands below, which beats what .env says (the stand
# makes its own APP_KEY and JWT secret). From the machine it needs php, node with the packages of resources/twa, curl and ffmpeg.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
DIR="${E2E_DIR:-${TMPDIR:-/tmp}/lexiflow-e2e}"
PORT="${E2E_PORT:-8010}"
PID_FILE="$DIR/server.pid"
BUILT_MARK="$DIR/twa-built-by-stand"
AUDIO_MARK="$DIR/audio-made-by-stand"
AUDIO_DIR="$ROOT/storage/app/practice-audio"
WORD_AUDIO_LIST="$DIR/word-audio.list"
WORD_AUDIO_DIR="$ROOT/storage/app/public"

# One random secret per stand directory: the JWT secret (the one of .env may be empty or short) and the key of the app.
secret() { # secret <name> <bytes>
  local file="$DIR/$1.secret"
  [ -s "$file" ] || head -c "$2" /dev/urandom | base64 | tr -d '\n' > "$file"
  cat "$file"
}

stand_env() {
  export APP_ENV=local APP_DEBUG=false LOG_CHANNEL=single
  export DB_CONNECTION=sqlite DB_DATABASE="$DIR/e2e.sqlite"
  export PRACTICE_ACCESS=pilot GAME_ACCESS=all
  export QUEUE_CONNECTION=sync CACHE_STORE=file CACHE_LIMITER_STORE=file SESSION_DRIVER=file
  export TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-123456:e2e-test-token}"
  export APP_URL="http://127.0.0.1:$PORT"
  export TWA_JWT_SECRET="$(secret jwt 48)"
  export APP_KEY="base64:$(secret appkey 32)"
  if [ -n "${E2E_GRACE:-}" ]; then export PRACTICE_BATTLE_PRESENCE_GRACE="$E2E_GRACE"; fi
}

running() {
  [ -s "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null
}

up() {
  mkdir -p "$DIR"
  if running; then echo "The stand is already running (pid $(cat "$PID_FILE")); run 'down' first." >&2; exit 1; fi
  stand_env
  cd "$ROOT"

  rm -f "$DB_DATABASE" && : > "$DB_DATABASE"
  # The seed voices the phrases of the dictations into storage/app/practice-audio (git-ignored): remember whether they are the stand's own.
  if compgen -G "$AUDIO_DIR/*.mp3" > /dev/null; then rm -f "$AUDIO_MARK"; else : > "$AUDIO_MARK"; fi

  echo "== database and demo seed ($DB_DATABASE)"
  php artisan migrate:fresh --seed --force --no-interaction | tail -n 3
  # The limiters live in the file cache of the worktree: what an earlier run counted is not the business of this one.
  php artisan cache:clear --no-interaction > /dev/null
  echo "== friends (Alice and Bob; Charlie and Diana for the pictures that run two by two)"
  php resources/twa/scripts/e2e/befriend.php 200000001 200000002
  php resources/twa/scripts/e2e/befriend.php 200000003 200000004
  echo "== examples and recordings of the words (the gap and listen tasks of the game)"
  php resources/twa/scripts/e2e/game-seed.php "$WORD_AUDIO_LIST"

  if [ ! -f public/twa/index.html ] || [ -f "$BUILT_MARK" ]; then
    echo "== build of the TWA into public/twa"
    (cd resources/twa && npx vite build --logLevel warn)
    : > "$BUILT_MARK"
  fi

  echo "== server on 127.0.0.1:$PORT"
  nohup php -S "127.0.0.1:$PORT" -t public resources/twa/scripts/e2e/server.php > "$DIR/server.log" 2>&1 < /dev/null &
  echo $! > "$PID_FILE"
  for _ in $(seq 1 50); do
    # A server that died at once (the port is taken) must not be mistaken for a live one that answers.
    if ! running; then break; fi
    if curl -fs -o /dev/null "http://127.0.0.1:$PORT/twa/"; then
      echo "The stand is up: http://127.0.0.1:$PORT/twa/ (pid $(cat "$PID_FILE"), log $DIR/server.log)"
      return 0
    fi
    sleep 0.2
  done
  echo "The server did not come up; see $DIR/server.log" >&2
  rm -f "$PID_FILE"
  exit 1
}

down() {
  if running; then kill "$(cat "$PID_FILE")" && echo "server stopped"; fi
  rm -f "$PID_FILE" "$DIR/e2e.sqlite" "$DIR/e2e.sqlite-journal" "$DIR/e2e.sqlite-wal" "$DIR/e2e.sqlite-shm"
  if [ -f "$BUILT_MARK" ]; then rm -rf "$ROOT/public/twa" "$BUILT_MARK" && echo "public/twa removed"; fi
  if [ -f "$AUDIO_MARK" ]; then rm -f "$AUDIO_DIR"/*.mp3 "$AUDIO_MARK" && echo "audio of the seed removed"; fi
  # The recordings of the words that game-seed.php made (and only those).
  if [ -s "$WORD_AUDIO_LIST" ]; then while IFS= read -r clip; do rm -f "$WORD_AUDIO_DIR/$clip"; done < "$WORD_AUDIO_LIST"; echo "recordings of the words removed"; fi
  rm -f "$WORD_AUDIO_LIST"
}

# The stand has no scheduler (it would run every command of the project): the one run of the janitor that the scenarios need.
sweep() {
  stand_env
  cd "$ROOT"
  php artisan practice:battles-sweep --no-interaction
}

status() {
  if running; then echo "running: http://127.0.0.1:$PORT/twa/ (pid $(cat "$PID_FILE"), database $DIR/e2e.sqlite)"; else echo "stopped"; fi
}

case "${1:-}" in
  up) up ;;
  down) down ;;
  status) status ;;
  sweep) sweep ;;
  *) echo "usage: $0 up|down|status|sweep" >&2; exit 2 ;;
esac
