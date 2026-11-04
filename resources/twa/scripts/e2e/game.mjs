#!/usr/bin/env node
// Browser e2e of the game "Karvon" on the REAL local API (docs/05 §5b.4): the TWA in Chromium, launched the way Telegram launches a Mini
// App (signed initData, the theme in the fragment), talks to the stand with the demo seed (stand.sh, GAME_ACCESS=all). Nothing is mocked.
//
//   node resources/twa/scripts/e2e/game.mjs --db <sqlite of the stand> [--base http://127.0.0.1:8010] [--out <dir>]
//        [--a 200000001] [--b 200000002] [--c 200000003] [--d 200000004] [--e 200000005] [--only game:stop,shots,...] [--headed]
//
// The right answers are read from the snapshot of the run in the database of the stand (the API never sends them; docs/18 §12.8),
// everything else - what is on the screen, what the API says - is judged as the learner would see it. See README.md of this directory.
import { createRequire } from 'node:module'
import { mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { Api, E2EError, Oracle, Player, Report, loadPlaywright, parseArgs, until } from './kit.mjs'
import { gameScenarios } from './game-scenarios.mjs'
import { shotScenarios } from './game-shots.mjs'

const args = parseArgs(process.argv.slice(2))

if (args.help || args.h) {
  console.log(`usage: node game.mjs --db <sqlite of the stand> [--base URL] [--out DIR] [--a TG_ID] [--b TG_ID] [--c TG_ID] [--d TG_ID] [--e TG_ID] [--only a,b] [--headed]
scenarios: game:stop, game:daily, game:reload, shots`)
  process.exit(0)
}

// node:sqlite is experimental in Node 22 and says so on stderr: the warning is not news here.
process.removeAllListeners('warning')
const { DatabaseSync } = createRequire(import.meta.url)('node:sqlite')

const cfg = {
  base: String(args.base ?? 'http://127.0.0.1:8010').replace(/\/$/, ''),
  db: args.db ? String(args.db) : null,
  out: String(args.out ?? join(tmpdir(), 'lexiflow-e2e-game-shots')),
  botToken: String(args['bot-token'] ?? process.env.TELEGRAM_BOT_TOKEN ?? '123456:e2e-test-token'),
  // The demo learners (DemoLearnersSeeder): each scenario has its own, because the caravan of the day is one a day for a person and
  // the API allows 60 calls a minute.
  telegram: {
    a: Number(args.a ?? 200000001),
    b: Number(args.b ?? 200000002),
    c: Number(args.c ?? 200000003),
    d: Number(args.d ?? 200000004),
    e: Number(args.e ?? 200000005),
  },
  course: String(args.course ?? 'default'),
  headed: Boolean(args.headed),
  only: args.only ? String(args.only).split(',').map((name) => name.trim()).filter(Boolean) : [],
}

if (cfg.db === null) {
  console.error('--db <sqlite file of the stand> is required: the right answers are in the snapshot of a run, and the API never sends them (docs/18 §12.8).')
  process.exit(2)
}

mkdirSync(cfg.out, { recursive: true })

const wanted = (name) => cfg.only.length === 0 || cfg.only.some((item) => name === item || name.startsWith(`${item}:`))

async function main() {
  const { chromium } = loadPlaywright()
  const browser = await chromium.launch({ headless: !cfg.headed })
  const oracle = new Oracle(cfg.db, { DatabaseSync })
  const report = new Report({ out: cfg.out })

  const people = Object.fromEntries(Object.entries(cfg.telegram).map(([key, id]) => [key, personOf(oracle, id)]))
  // One API client (one token) for a learner: a sign-in is limited to 10 a minute for a person, like a Mini App's own.
  const apis = {}
  const ledgers = Object.fromEntries(Object.keys(people).map((key) => [key, []]))
  const env = { cfg, browser, oracle, report, people, wanted, newPlayer, rest, api: (who) => (apis[who] ??= new Api({ base: cfg.base, botToken: cfg.botToken, user: people[who], ledger: ledgers[who] })) }

  /** Waits (by the log of its own calls) until a learner has at least `room` calls left of the 60 a minute the API allows. */
  async function rest(who, room) {
    const used = () => ledgers[who].filter((at) => at > Date.now() - 60_000).length
    if (used() <= 60 - room) return
    report.log(`     (${people[who].first_name} has used ${used()} of 60 calls this minute: waiting for ${room} to be free)`)
    await until(() => used() <= 60 - room, { timeout: 90_000, interval: 500, label: `the limit of the API for ${people[who].first_name}` })
  }

  /** A player with a Telegram launch, a theme and a width; `scene` closes it at the end of the scenario. */
  async function newPlayer(scene, who, { theme = 'light', width = 390, height, role = null, tag = scene.name.replace(/[^\w-]+/g, '-') } = {}) {
    const user = people[who]
    await rest(who, 30)
    const player = new Player({ name: user.first_name, who, role, telegramUser: user, browser, base: cfg.base, botToken: cfg.botToken, theme, width, height: height ?? (width <= 320 ? 568 : 844), out: cfg.out, tag, report, ledger: ledgers[who] })
    scene.add(player)
    return player.start()
  }

  console.log(`e2e of the game "Karvon": ${cfg.base}; pictures in ${cfg.out}`)
  const started = Date.now()
  try {
    for (const { name, body } of [...gameScenarios(env), ...shotScenarios(env)]) {
      if (wanted(name)) await report.scenario(name, body)
    }
  } finally {
    await browser.close()
  }
  const code = report.summary()
  console.log(`\nwall time ${((Date.now() - started) / 1000).toFixed(1)} s; pictures in ${cfg.out}`)
  process.exit(code)
}

/** The Telegram user of a demo learner as the database has it: the names must not change when the Mini App signs in. */
function personOf(oracle, telegramId) {
  const row = oracle.learner(telegramId)
  const user = { id: Number(row.telegram_user_id), first_name: row.first_name, language_code: 'ru' }
  if (row.last_name) user.last_name = row.last_name
  if (row.username) user.username = row.username
  return user
}

main().catch((error) => {
  console.error(error instanceof E2EError ? `e2e: ${error.message}` : error)
  process.exit(1)
})
