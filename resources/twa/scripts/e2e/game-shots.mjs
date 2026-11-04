// The screens of the game "Karvon" at 390 and 320 px, in the light and in the dark theme of Telegram: "Today" with its entry, the map, the
// sheet of a stop, the caravan of the day (waiting, running, played), every kind of task with its review, the banners of the events, the
// summary (completed, failed), the sheet "Выйти", the question "continue or start this one". The pictures are for the eyes (see
// sheet.mjs); on the way the script checks what a machine can: nothing sticks out of the window sideways, the texts that a box does
// not show whole are listed for a look.
//
// Four runs (width x theme), two at a time; each has its own learner (the caravan of the day is one a day for a person, and the API
// allows 60 calls a minute). The kinds of tasks are met by playing runs of the four stops until all nine have been drawn.
import { E2EError, until } from './kit.mjs'
import { GAME_KINDS, isWordKind, playRun, readSummary, waitStatus } from './game-play.mjs'
import { GAME_EXPECTED_STATUSES } from './game-scenarios.mjs'

const CONFIGS = [
  { width: 390, theme: 'light', who: 'a' },
  { width: 390, theme: 'dark', who: 'c' },
  { width: 320, theme: 'light', who: 'e' },
  // The last to use its learner: it plays the caravan of the day, and then the day is spent.
  { width: 320, theme: 'dark', who: 'a', daily: true },
]
/** Who has played the caravan of the day by now (game:daily): the picture of "played" is taken with this learner. */
const PLAYED = 'b'
/** The stops whose runs are played for the kinds of tasks. */
const LESSONS = [1, 2, 3, 4, 2, 3]

export function shotScenarios(env) {
  return [{ name: 'shots', body: (s) => shots(env, s) }]
}

async function shots(env, s) {
  for (const batch of [CONFIGS.slice(0, 2), CONFIGS.slice(2)]) {
    await Promise.all(batch.map((config) => track(env, s, config)))
  }
}

/** One run (a width, a theme): its own scene, its players, its checks; what it finds counts for the scenario. */
async function track(env, parent, config) {
  const s = parent.fork(`game-${config.width}-${config.theme}`)
  const options = { theme: config.theme, width: config.width, tag: 'shots-game' }
  try {
    const p = await env.newPlayer(s, config.who, options)
    await screens(env, s, p, config, options)
  } catch (error) {
    await s.crash(error)
  } finally {
    await s.noPageErrors()
    await s.noUnexpectedStatuses(GAME_EXPECTED_STATUSES)
    await s.closeAll()
    parent.absorb(s)
  }
}

/** The picture of a screen and what a machine can say about it: nothing is cut off at the edge of the window. */
async function look(s, p, label) {
  await p.shot(label)
  const cut = await p.cutOff()
  await s.check(cut.edges.length === 0, `${label} (${p.width} px, ${p.theme}): nothing sticks out of the window sideways`, { players: [], actual: cut.edges.join(' | ') })
  if (cut.clipped.length > 0) s.note(`${label}: text a box does not show whole: ${cut.clipped.join(' | ')}`)
}

/**
 * At 320 x 568 what the learner touches first is in the window and above the bar of the buttons, with no scrolling (the rule of the
 * practice, 18.12b; the header of a run takes room of its own, so the caption goes and the choice task is tighter in a short window).
 */
async function taskInSight(s, p, kind) {
  const seen = await p.page.evaluate((name) => {
    const all = (selector) => [...document.querySelectorAll(selector)]
    const targets = {
      choice: all('[data-testid="choice-task"] button[data-testid^="option-"]'),
      typed: all('[data-testid="answer-input"]'),
      pairs: all('button[data-card]'),
      collocation: all('[data-testid="collocation-choice"] button[data-testid^="option-"]'),
      sentence: all('[data-testid="sentence-bank"] button[data-testid^="fragment-"]').slice(0, 1),
      dictation: all('[data-testid="dictation-text"]'),
    }[name]
    const bar = document.querySelector('[data-testid="actions"]')?.getBoundingClientRect().top ?? innerHeight
    return { limit: Math.round(Math.min(bar, innerHeight)), bottoms: targets.map((element) => Math.round(element.getBoundingClientRect().bottom)) }
  }, kind)
  const lowest = Math.max(...seen.bottoms)
  await s.check(seen.bottoms.length > 0 && lowest <= seen.limit, `${kind} (${p.width} x ${p.height} px, ${p.theme}): the task is above the buttons and inside the window, no scrolling needed`, {
    players: [p],
    expected: `the lowest edge <= ${seen.limit}`,
    actual: seen.bottoms.join(', '),
  })
}

const SIGHT = { translate_fwd: 'choice', translate_back: 'choice', listen: 'choice', spell: 'typed', gap: 'typed', pairs_round: 'pairs', collocation: 'collocation', sentence: 'sentence', dictation: 'dictation' }

/** A run of a stop through the API, opened in the browser the way a tap on the map would. */
async function openRun(env, p, lesson) {
  await env.rest(p.who, 30)
  const created = await env.api(p.who).post('/game/runs', { kind: 'stop', course: env.cfg.course, lesson_id: lesson, replace_active: true })
  if (created.status !== 201) throw new E2EError(`the run of lesson ${lesson} was not started: ${created.status} ${created.text.slice(0, 200)}`)
  await p.go(`/game/runs/${created.json.id}`)
  await p.waitFor('game-run-view')
  return created.json
}

async function screens(env, s, p, config, options) {
  const opts = { players: [p] }
  s.step(`${p.name}: "Today", the map, the caravan of the day (${config.width} px, ${config.theme})`)
  await p.open('/twa/today')
  await p.waitFor('game-entry')
  await s.check(await p.cutOffSees(), `the check of what the screen cuts off sees a box that sticks out and a text that its box cuts (${p.width} px)`, opts)
  await look(s, p, 'today')
  await p.go('/game?course=default')
  await p.waitFor('road')
  await look(s, p, 'map')
  await p.click('daily-card')
  await p.waitFor('ready')
  await look(s, p, 'daily-ready')
  await p.go('/game?course=default')
  await p.waitFor('road')
  await p.click('stop-1')
  await p.waitFor('stop-sheet')
  await look(s, p, 'stop-sheet')

  s.step(`${p.name}: runs of the stops until all nine kinds of tasks have been drawn`)
  const seenTask = new Set()
  const seenReveal = new Set()
  const seenEvent = new Set()
  let wrongDone = false
  let summaryDone = false
  for (const [turn, lesson] of LESSONS.entries()) {
    // Enough when every kind has been drawn and explained and every banner has been seen (the events are drawn by lot: a few runs at most).
    if (GAME_KINDS.every((kind) => seenTask.has(kind) && seenReveal.has(`reveal-${kind}`)) && ['sandstorm', 'oasis', 'bazaar'].every((kind) => seenEvent.has(kind))) break
    // The first run is begun from the sheet that is open; the others through the API.
    let runId
    if (turn === 0) {
      const mark = p.mark()
      await p.click('stop-go')
      runId = JSON.parse((await p.waitCall(/\/game\/runs$/, { method: 'POST', since: mark, status: 201 })).body).id
      await p.waitFor('game-run-view')
    } else {
      runId = (await openRun(env, p, lesson)).id
    }
    await env.rest(p.who, 30)
    const { last } = await playRun(env, s, p, runId, {
      plan: (index, element) => {
        if (!wrongDone && isWordKind(element.kind) && index >= 1) {
          wrongDone = true
          return 'wrong'
        }
        return 'right'
      },
      hooks: {
        event: async (kind) => {
          if (seenEvent.has(kind)) return
          seenEvent.add(kind)
          await look(s, p, `event-${kind}`)
        },
        task: async (element) => {
          if (seenTask.has(element.kind)) return
          seenTask.add(element.kind)
          await look(s, p, `task-${element.kind}`)
          if (p.width <= 320) await taskInSight(s, p, SIGHT[element.kind])
        },
        reveal: async (element, index, intent) => {
          const label = intent === 'right' ? `reveal-${element.kind}` : `reveal-${element.kind}-${intent}`
          if (seenReveal.has(label)) return
          seenReveal.add(label)
          seenReveal.add(`reveal-${element.kind}`)
          await look(s, p, label)
        },
      },
    })
    await waitStatus(p, ['summary'])
    if (!summaryDone) {
      summaryDone = true
      await look(s, p, 'summary')
    }
    s.note(`run #${runId} of lesson ${lesson}: ${last.run.summary.stars} stars; kinds seen: ${[...seenTask].join(', ')}`)
  }
  const missing = GAME_KINDS.filter((kind) => !seenTask.has(kind))
  await s.check(missing.length === 0, 'every kind of task was drawn and photographed', { ...opts, expected: GAME_KINDS.join(), actual: `missing: ${missing.join(', ')}` })

  s.step(`${p.name}: a run that is lost (the flasks run out)`)
  const lost = await openRun(env, p, 1)
  await playRun(env, s, p, lost.id, { plan: () => 'wrong' })
  await waitStatus(p, ['summary'])
  const failed = await readSummary(p)
  await s.check(failed.outcome === 'failed' && failed.mistakes.length >= 3, 'the lost run says "Фляги кончились" and lists the mistakes', { ...opts, actual: `${failed.outcome} ${failed.title}, ${failed.mistakes.length} mistakes` })
  await look(s, p, 'summary-failed')

  s.step(`${p.name}: ✕ in a run, the unfinished run on the map and in the caravan of the day, the conflict`)
  await openRun(env, p, 3)
  await waitStatus(p, ['playing', 'event'])
  await p.click('close-btn')
  await p.waitFor('exit-sheet')
  await look(s, p, 'exit-sheet')
  await p.click('stay-btn')
  await p.go('/game/daily?course=default')
  await p.waitFor('running')
  await look(s, p, 'daily-running')
  await p.go('/game?course=default')
  await p.waitFor('active-run')
  await look(s, p, 'map-active-run')
  await p.click('stop-2')
  await p.waitFor('stop-sheet')
  await p.click('stop-go')
  await p.waitFor('conflict-sheet')
  await look(s, p, 'conflict-sheet')
  await p.click('conflict-continue')
  await p.waitFor('game-run-view')
  await waitStatus(p, ['playing', 'event'])
  await p.click('close-btn')
  await p.waitFor('exit-sheet')
  await p.click('leave-btn')
  await p.waitFor('road')
  await look(s, p, 'map-played')
  await p.click('stop-1')
  await p.waitFor('stop-best')
  await look(s, p, 'stop-sheet-best')

  s.step(`the caravan of the day that has been played (${PLAYED})`)
  // game:daily plays it; run alone, the pictures spend the day the short way (a start and an abandon: any end of the run spends it).
  const spent = await env.api(PLAYED).post('/game/runs', { kind: 'daily', replace_active: true })
  if (spent.status === 201) await env.api(PLAYED).post(`/game/runs/${spent.json.id}/abandon`, { confirm: true })
  const q = await env.newPlayer(s, PLAYED, options)
  await q.open('/twa/game/daily?course=default')
  await q.waitFor('done')
  await look(s, q, 'daily-done')

  if (config.daily) {
    s.step(`${p.name}: the caravan of the day: a run and its summary`)
    await p.go('/game/daily?course=default')
    await p.waitFor('ready')
    const mark = p.mark()
    await p.click('start-btn')
    const runId = JSON.parse((await p.waitCall(/\/game\/runs$/, { method: 'POST', since: mark, status: 201 })).body).id
    await p.waitFor('game-run-view')
    await playRun(env, s, p, runId, {
      plan: (index) => (index === 1 ? 'wrong' : 'right'),
      hooks: {
        task: async (element, index) => {
          if (index === 0) await look(s, p, `daily-task-${element.kind}`)
        },
      },
    })
    await waitStatus(p, ['summary'])
    await until(async () => !(await p.has('again-btn')), { label: 'no "Ещё раз" in the caravan of the day' })
    await look(s, p, 'daily-summary')
    await p.click('map-btn')
    await p.waitFor('road')
    await look(s, p, 'map-daily-done')
  }
}

