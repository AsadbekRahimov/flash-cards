# Browser e2e of the practice and the battle (docs/16 §14.4)

The TWA in Chromium, launched the way Telegram launches a Mini App (initData signed with the bot token, the theme in the
`#tgWebApp…` fragment), against the **real** local API with the demo seed. Nothing is mocked. No packages are added: Playwright is the
global one (`npm root -g`), the rest is `node:*`.

Needs: PHP 8.2+, Node 22.13+ (`node:sqlite`), `ffmpeg` with the `flite` filter (the demo seed voices the phrases of the dictations),
a worktree with `vendor/` and `.env` (an `APP_KEY` is enough), `resources/twa/node_modules`.

## The stand

```bash
export E2E_DIR=/tmp/lexiflow-e2e                  # the stand's directory (default $TMPDIR/lexiflow-e2e); E2E_PORT=8010
resources/twa/scripts/e2e/stand.sh up             # SQLite + migrate:fresh --seed + friends + build into public/twa + server
resources/twa/scripts/e2e/stand.sh status | sweep | down
```

`up` works from scratch and edits nothing: the settings go through the environment (`APP_ENV=local`, `DB_CONNECTION=sqlite`,
`PRACTICE_ACCESS=pilot`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=file`, a bot token, a JWT secret and an app key made for the stand).
It seeds the pilot package (90 tasks in lessons 2-4 of the demo course, the clips voiced by ffmpeg), puts the demo learners into the
pilot, and makes Alice and Bob, Charlie and Diana friends. The TWA and the API are one origin: `php -S` with `server.php`, which does
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
| `shots` | 390 and 320 px x light and dark: the screens of each mode (task, review) and of a battle (room, round, review, result); nothing may stick out of the window sideways |

## Things to know

- **`presence_grace_seconds`.** The server's default is 30 s; `PRACTICE_BATTLE_PRESENCE_GRACE` (stand: `E2E_GRACE=12`, script: `--grace 12`)
  changes it. The 5 s of docs/16 §14.4 does not work: a heartbeat is written every 5 s and polled every 2 s, so a healthy player is
  up to 7 s "old", and a match with grace 5 is cancelled in the countdown. `battle:background` keeps a player away for 13 s and
  needs about 25 s or more. For a quicker `battle:both-absent` run that scenario alone with 12 (about 22 s instead of 40).
- **The janitor.** `both_absent` comes only from `practice:battles-sweep` (every minute in production): whoever opens the match first
  after the grace is "present" and wins by the absence of the other (`finished / absent`). The stand has no scheduler, so the
  scenario runs `stand.sh sweep` itself (`--sweep` for another stand).
- **Limits.** The API allows 60 calls a minute and `/auth` 10 a minute per learner. The script counts its own calls and waits when a
  learner is short of room; do not start it twice within a minute, or two copies at once.
- The pictures: `<out>/<scenario>--<player>--<screen>--<width>-<theme>[--pN].png` (`--pN`: the page scrolled, when it is longer than
  the window - the buttons at the bottom stay in place, so a picture of the "whole page" would paint them over the content).
  `node resources/twa/scripts/e2e/sheet.mjs <out> --width 320 --only shots-` makes contact sheets (`sheet-NN.png` and `.html`, the light
  and the dark picture of a screen side by side, a caption under each) to look at 4 screens at once.
