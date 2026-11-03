# Browser e2e of the practice, the battle and the game "Karvon" (docs/16 §14.4, docs/05 §5b.4)

The TWA in Chromium, launched the way Telegram launches a Mini App (initData signed with the bot token, the theme in the
`#tgWebApp…` fragment), against the **real** local API with the demo seed. Nothing is mocked. No packages are added: Playwright is the
global one (`npm root -g`), the rest is `node:*`.

Needs: PHP 8.5+, Node 22.13+ (`node:sqlite`), `ffmpeg` with the `flite` filter (the demo seed voices the phrases of the dictations),
a worktree with `vendor/` and `.env` (an `APP_KEY` is enough), `resources/twa/node_modules`.

## The stand

```bash
export E2E_DIR=/tmp/lexiflow-e2e                  # the stand's directory (default $TMPDIR/lexiflow-e2e); E2E_PORT=8010
resources/twa/scripts/e2e/stand.sh up             # SQLite + migrate:fresh --seed + friends + build into public/twa + server
resources/twa/scripts/e2e/stand.sh status | sweep | down
```

`up` works from scratch and edits nothing: the settings go through the environment (`APP_ENV=local`, `DB_CONNECTION=sqlite`,
`PRACTICE_ACCESS=pilot`, `GAME_ACCESS=all`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=file`, `CACHE_LIMITER_STORE=file`, a bot token, a JWT secret and an app key made for the stand).
It seeds the pilot package (90 tasks in lessons 2-4 of the demo course, the clips voiced by ffmpeg), puts the demo learners into the
pilot, and makes Alice and Bob, Charlie and Diana friends. `game-seed.php` then gives the words of the first four lessons an example
and a recording (ffmpeg again), because the game's tasks `gap` and `listen` need both and the demo words have neither; the clips it
made are listed in `$E2E_DIR/word-audio.list` and `down` removes exactly those. The TWA and the API are one origin: `php -S` with `server.php`, which does
what nginx does (`/twa/*` falls back to `index.html`; `php artisan serve` does not, and it drops the environment of the stand).
`down` stops the server and removes `public/twa` (if `up` built it), the database and the clips of the seed.

## The run

```bash
node resources/twa/scripts/e2e/practice.mjs --db $E2E_DIR/e2e.sqlite --out $E2E_DIR/shots
```

`--db` (required) is the SQLite file of the stand: **the API never sends the right answers** (docs/16 §12), so the script reads them
from the snapshot of the session / battle, read only; what the screens and the API say is judged separately. Other options:
`--base` (http://127.0.0.1:8010), `--a/--b/--c/--d/--e` (telegram ids of the demo learners), `--only solo,battle:full,shots,…`,
`--lesson 2`, `--course default`, `--grace 30`, `--sweep "<command>"`, `--headed`. It prints the steps, a PASS/FAIL line for every
scenario with the time and the number of checks, and the findings (what was expected, what was seen, the calls of the API, a picture).
The exit code is 1 on any FAIL.

| Scenario | What it does |
|---|---|
| `solo:pairs` `:collocation` `:sentence` `:dictation` | the hub of a lesson to the summary; a mistake on purpose (pairs: one in each round; a typo, a hint); the summary against the answers, the API and the database; "Повторить ошибки"; a session left and continued from the hub (also after a reload); an answer whose reply (collocation) or request (sentence, dictation) is lost and sent again - the same request id, the same verdict, counted once, no 409; the clip of a dictation: blob, `<audio>` decodes |
| `battle:full` | the invitation from "Друзья", "Принять", "Готов" x2, 5 rounds, one round with no answer (the 30 s time-out), the same result on both screens, in the API and in the database |
| `battle:reload` | a dictation battle from the hub; a reload before and after an answer: the same round, the same score |
| `battle:background` | `visibilitychange` hidden for 13 s: no polling, the opponent is told "Соперник потерял связь", then not; the match is not lost |
| `battle:surrender` | "Остаться", then "Сдаться" by the one who leads: the other wins, the score stays; the rematch offered and accepted |
| `battle:both-absent` | both contexts closed for longer than the grace, the janitor runs, `closed / both_absent` on both screens |
| `battle:absent-lazy` | the same without the janitor: the first one to come back closes the match by their own request - `closed / both_absent`, no winner, the mark of nobody renewed; the other reads the same later |
| `shots` | 390 and 320 px x light and dark: the screens of each mode (task, review) and of a battle (room, round, review, result); nothing may stick out of the window sideways |

## The game "Karvon" (`game.mjs`)

```bash
node resources/twa/scripts/e2e/game.mjs --db $E2E_DIR/e2e.sqlite --base http://127.0.0.1:$E2E_PORT --out $E2E_DIR/shots
```

The same stand, the same kit, `--only game:stop,shots,...`. **The API never sends the right answers**, so the script reads the snapshot of
the run (`game_runs.snapshot`, `state.current`), read only; what the screens and the API say is judged separately. Every scenario has
its own learner, because the caravan of the day is one a day for a person and the API allows 60 calls a minute (`game:stop` Alice,
`game:daily` Bob and Diana, `game:reload` Charlie, the pictures Alice, Charlie, Eve and, for "played", Bob; `--a` ... `--e` name the demo
learners as in `practice.mjs`). Run it on a fresh stand: a day that has been played stays played.

| Scenario | What it does |
|---|---|
| `game:stop` | "Today" with the entry "Караван дня ждёт" (against `/today.game`), the course, "Караван", the map against `GET /game/map` (cities, open and closed stops, the Pro chip of a boss, the card of the day), the sheet of stop 2, "В путь" (`POST /game/runs`: the body, `course.slug` and `lesson_id` in the reply), the run to the summary: the header at every element, the kind drawn, the verdict of the screen against the API and the intent, the score; a mistake and a "Не знаю" on purpose; the bazaar hint without coins (409 `coins_insufficient`, a toast, the task stays); the clip of a `listen` task; the summary against the API and the row of the database; "Today" after it: `streak.held_by = game` and "Серия удержана забегом"; a second run and "Ещё раз" (the address by the run itself, no query: `/game?course=&stop=` with the sheet and the best score) |
| `game:daily` | "Today" -> "Караван дня ждёт" -> `/game/daily` (the words due as the map and `/today` say), "В путь" (`{kind: daily}` only), a caravan of words to the summary (no "Ещё раз"), the map card "Сыграно, завтра снова", the screen `done` with `resets_at`, "Повторять в карточках" opens a review; the second start through the API is 409 `game_daily_done {resets_at}`; a lost race: the day is spent by another device while the screen says "ready", the press is 409 and the screen turns to `done` without a toast, "Today" has no entry |
| `game:reload` | `?stop=` opens the sheet; a reload before the third element: the same element, place and score; an answer and a reload after it: the next element, counted once; ✕ -> "Выйти" abandons the run and leads to the map of the run's own course; the address of the abandoned run says "Забег закончился" |
| `shots` | 390 and 320 px x light and dark (two learners at a time): "Today", the map, the caravan of the day (waiting, running, played), the sheet of a stop (also with the best score), runs of the stops until all nine kinds of tasks have been drawn and explained (and the banners of the events), the summary (completed, lost), the sheet "Выйти", the map with an unfinished run, the question "continue or start this one", the caravan of the day to its summary (320 dark); nothing may stick out of the window sideways |

Files: `game.mjs` (the entry), `game-scenarios.mjs`, `game-play.mjs` (what a learner does in a run: answers every kind of element on the
screen, checks the verdict and the score), `game-shots.mjs`, `game-seed.php`.

## In CI

`.github/workflows/e2e.yml` runs the whole thing every night (02:37 UTC) and by hand (Actions -> "E2E Mini App" -> Run workflow); not on
push or pull request. The job sets up PHP 8.5 and Node 22 (`node:sqlite` needs 22.13+), installs `ffmpeg` from apt (the workflow
checks that it has the `flite` filter), Playwright pinned in the workflow (`PLAYWRIGHT_VERSION`, installed globally: nothing in
`package.json`) with `npx playwright install --with-deps chromium`, then `stand.sh up`, `practice.mjs`, a minute of rest (the API allows 60 calls a minute), `game.mjs`, `stand.sh down` (also when the run fails).
The artifact `e2e-report` (14 days) holds `report.txt` and `report-game.txt` (the output of the scripts: PASS/FAIL lines and the findings), the pictures
(`shots/`) and `storage/logs/`. A red night is a failed run of the workflow; to reproduce, run the same commands as in "The stand" and "The run".
When Playwright is updated, change the version in the workflow and look at the pictures once.

## Things to know

- **`presence_grace_seconds`.** The server's default is 30 s; `PRACTICE_BATTLE_PRESENCE_GRACE` (stand: `E2E_GRACE=15`, script: `--grace 15`)
  shortens it, but never below the floor of 15 s (`heartbeat_write_seconds` + the longest pause of the poll,
  `BattleSettings::presenceGraceFloorSeconds`): a heartbeat is written every 5 s and polled every 2 s, so a healthy player is up to
  7 s "old" (up to 15 s after a failed poll), and the 5 s an earlier docs/16 §14.4 proposed cancelled a match in the countdown.
  `--grace` must name the EFFECTIVE value - the API tells it in `presence_grace_seconds` of a battle. `battle:background` keeps a
  player away for 13 s and needs about 25 s or more. For quicker `battle:both-absent` / `battle:absent-lazy` runs, run those
  scenarios alone with 15 (about 25 s of waiting instead of 40).
- **The clock, not the janitor.** `both_absent` is decided by the clock: the first request of either player after the grace - opening
  the match, a poll, an action - moves the match by the presence as it WAS, so both are absent and the match is closed without a
  winner, and the one who came back is not marked present by coming back (`battle:absent-lazy`); a player away longer than the grace
  loses to one away less, whoever asks first. `practice:battles-sweep` (every minute in production) gives the same outcome when nobody
  comes back; the stand has no scheduler, so `battle:both-absent` runs `stand.sh sweep` itself (`--sweep` for another stand).
- **Limits.** The API allows 60 calls a minute and `/auth` 10 a minute per learner. The script counts its own calls, waits when a
  learner is short of room and spreads the work over the five demo learners (the solo modes: Alice, Bob, Charlie, Diana; the battles by
  turns: Alice and Bob, Charlie and Diana; the solo pictures: Eve). Do not start it twice within a minute, or two copies at once.
- The pictures: `<out>/<scenario>--<player>--<screen>--<width>-<theme>[--pN].png` (`--pN`: the page scrolled, when it is longer than
  the window - the buttons at the bottom stay in place, so a picture of the "whole page" would paint them over the content).
  `node resources/twa/scripts/e2e/sheet.mjs <out> --width 320 --only '^shots-' --name sheet-320` makes contact sheets (`sheet-320-NN.png` and `.html`, the light
  and the dark picture of a screen side by side, a caption under each) to look at 4 screens at once.
