#!/usr/bin/env node
// Browser e2e of the practice and the battle on the REAL local API (docs/16 §14.4): the TWA in Chromium, launched the way Telegram
// launches a Mini App (signed initData, the theme in the fragment), talks to a stand with the demo seed (stand.sh). Nothing is mocked.
//
//   node resources/twa/scripts/e2e/practice.mjs --db <sqlite of the stand> [--base http://127.0.0.1:8010] [--out <dir>]
//        [--a 200000001] [--b 200000002] [--only solo,battle,...] [--grace 30] [--lesson 2] [--course default] [--sweep CMD] [--headed]
//
// The right answers are read from the snapshot of the session / the battle in the database of the stand (the API never sends them;
// docs/16 §12), everything else - what is on the screen, what the API says - is judged as the learner would see it.
// See README.md of this directory.
import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Api, E2EError, Oracle, Player, Report, loadPlaywright, parseArgs } from './kit.mjs'
import { battleScenarios } from './battle.mjs'
import { soloScenarios } from './solo.mjs'
import { shotScenarios } from './shots.mjs'

const args = parseArgs(process.argv.slice(2))

if (args.help || args.h) {
  console.log(`usage: node practice.mjs --db <sqlite of the stand> [--base URL] [--out DIR] [--a TG_ID] [--b TG_ID] [--only a,b] [--grace SEC] [--lesson ID] [--course SLUG] [--sweep CMD] [--headed]
scenarios: solo (solo:pairs, solo:collocation, solo:sentence, solo:dictation), battle (battle:full, battle:reload, battle:background, battle:surrender, battle:both-absent), shots`)
  process.exit(0)
}

// node:sqlite is experimental in Node 22 and says so on stderr: the warning is not news here.
process.removeAllListeners('warning')
const { DatabaseSync } = createRequire(import.meta.url)('node:sqlite')

const cfg = {
  base: String(args.base ?? 'http://127.0.0.1:8010').replace(/\/$/, ''),
  db: args.db ? String(args.db) : null,
  out: String(args.out ?? join(tmpdir(), 'lexiflow-e2e-shots')),
  botToken: String(args['bot-token'] ?? process.env.TELEGRAM_BOT_TOKEN ?? '123456:e2e-test-token'),
  telegramA: Number(args.a ?? 200000001),
  telegramB: Number(args.b ?? 200000002),
  // presence_grace_seconds of the SERVER (the stand's default is 30; `stand.sh` takes E2E_GRACE): how long "both absent" has to wait.
  grace: Number(args.grace ?? 30),
  course: String(args.course ?? 'default'),
  lesson: Number(args.lesson ?? 2),
  headed: Boolean(args.headed),
  // One run of the janitor of the battles: the stand has no scheduler (`stand.sh sweep`; `--sweep 'a shell command'` for another stand).
  sweep: args.sweep ? String(args.sweep) : null,
  only: args.only ? String(args.only).split(',').map((name) => name.trim()).filter(Boolean) : [],
}

if (cfg.db === null) {
  console.error('--db <sqlite file of the stand> is required: the right answers are in the snapshot of a session, and the API never sends them (docs/16 §12).')
  process.exit(2)
}

mkdirSync(cfg.out, { recursive: true })

const wanted = (name) => cfg.only.length === 0 || cfg.only.some((item) => name === item || name.startsWith(`${item}:`))

async function main() {
  const { chromium } = loadPlaywright()
  const browser = await chromium.launch({ headless: !cfg.headed })
  const oracle = new Oracle(cfg.db, { DatabaseSync })
  const report = new Report({ out: cfg.out })

  const people = {
    a: personOf(oracle, cfg.telegramA),
    b: personOf(oracle, cfg.telegramB),
  }
  const env = { cfg, browser, oracle, report, people, wanted, newPlayer, sweep, api: (who) => new Api({ base: cfg.base, botToken: cfg.botToken, user: people[who] }) }

  /** Runs the janitor once (`practice:battles-sweep`): `{ ok, output }`. */
  function sweep() {
    const standDir = basename(cfg.db) === 'e2e.sqlite' ? dirname(cfg.db) : null
    const command = cfg.sweep ?? (standDir === null ? null : `${join(dirname(fileURLToPath(import.meta.url)), 'stand.sh')} sweep`)
    if (command === null) return { ok: false, output: 'no way to run the janitor: pass --sweep "<command>" (the database of the stand is not named e2e.sqlite)' }
    const run = spawnSync('bash', ['-c', command], { encoding: 'utf8', env: { ...process.env, E2E_DIR: standDir ?? process.env.E2E_DIR ?? '', E2E_PORT: new URL(cfg.base).port || '8010' } })
    return { ok: run.status === 0, output: `${run.stdout}${run.stderr}`.trim() }
  }

  /** A player with a Telegram launch, a theme and a width; `scene` closes it at the end of the scenario. */
  async function newPlayer(scene, who, { theme = 'light', width = 390, height, tag = scene.name.replace(/[^\w-]+/g, '-') } = {}) {
    const user = people[who]
    const player = new Player({ name: user.first_name, telegramUser: user, browser, base: cfg.base, botToken: cfg.botToken, theme, width, height: height ?? (width <= 320 ? 568 : 844), out: cfg.out, tag, report })
    scene.add(player)
    return player.start()
  }

  console.log(`e2e of the practice and the battle: ${cfg.base}, ${people.a.first_name} (${cfg.telegramA}) and ${people.b.first_name} (${cfg.telegramB}); pictures in ${cfg.out}`)
  const started = Date.now()
  try {
    const scenarios = [...soloScenarios(env), ...battleScenarios(env), ...shotScenarios(env)]
    for (const { name, body } of scenarios) {
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

