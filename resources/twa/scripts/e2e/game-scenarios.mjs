// The scenarios of the game "Karvon" (docs/05 §5b, docs/18 §12.9) in the browser against the real API of the stand: `game:stop` (the map, a
// stop, a run to the summary, "Again"), `game:daily` (the caravan of the day from "Today", the second start), `game:reload` (a reload in
// the middle of a run, the way out). The pictures are in game-shots.mjs.
import { E2EError, until } from './kit.mjs'
import { GAME_TASK, TYPED_OR_CHOICE, currentElement, isWordKind, playRun, readSummary, runRow, runStatus, waitStatus } from './game-play.mjs'

/**
 * Calls of the API that end without a good status and are not faults: the second start of the caravan of a day (409), the hint the
 * learner has no coins for (409), a run that is over and is read again (410), what a reload of the page cancels (GET, no answer).
 */
export const GAME_EXPECTED_STATUSES = [
  { method: 'POST', path: /\/game\/runs$/, status: 409 },
  { method: 'POST', path: /\/game\/runs\/\d+\/hint$/, status: 409 },
  { method: 'GET', path: /\/game\/runs\/\d+$/, status: 410 },
  { method: 'POST', path: /\/answer$/, status: 0 },
  { method: 'GET', path: /\/audio\//, status: 0 },
  ...['/api/twa/today', '/api/twa/me', '/api/twa/game/map', '/api/twa/assignments', '/api/twa/auth'].map((path) => ({ method: 'GET', path: new RegExp(`^${path}`), status: 0 })),
  { method: 'GET', path: /\/game\/runs\/\d+$/, status: 0 },
]

export function gameScenarios(env) {
  return [
    { name: 'game:stop', body: (s) => gameStop(env, s) },
    { name: 'game:daily', body: (s) => gameDaily(env, s) },
    { name: 'game:reload', body: (s) => gameReload(env, s) },
  ]
}

/** A scene ends with these two: nothing broke on the page, no call ended badly that was not expected. */
export async function finish(s) {
  await s.noPageErrors()
  await s.noUnexpectedStatuses(GAME_EXPECTED_STATUSES)
}

const path = (p) => new URL(p.page.url()).pathname + new URL(p.page.url()).search

/** Opens "Today" and returns what `/today` said. */
async function openToday(p, mark = p.mark()) {
  await p.open('/twa/today')
  await p.waitFor('today-view')
  await p.waitCall('/today', { since: mark, status: 200 })
  return p.lastJson('/api/twa/today', { since: mark })
}

// ----------------------------------------------------------------------------------------- the stop

async function gameStop(env, s) {
  const p = await env.newPlayer(s, 'a')
  const api = env.api('a')

  s.step('"Today": the entry of the game against /today.game')
  let mark = p.mark()
  const today = await openToday(p, mark)
  await p.waitFor('game-entry')
  await s.check(today?.game?.daily_available === true && today.game.active_run === null, '/today says the caravan of the day is waiting', { players: [p], since: mark, actual: JSON.stringify(today?.game) })
  await s.check((await p.text('game-entry-text')) === 'Караван дня ждёт' && (await p.attr('game-entry', 'data-kind')) === 'daily', 'the entry on "Today" is "Караван дня ждёт"', { players: [p], actual: await p.text('game-entry') })

  s.step('the course -> "Караван" -> the map')
  mark = p.mark()
  await p.go('/courses/default')
  await p.waitFor('game-btn')
  await p.click('game-btn')
  await p.waitFor('road')
  const map = p.lastJson('/api/twa/game/map', { since: mark })
  await s.check(map !== null && map.course.slug === 'default', 'the map of the course is read', { players: [p], since: mark })
  for (const city of map.cities) {
    await s.check((await p.text(`city-${city.number}`)).includes(city.city), `the city ${city.number} is "${city.city}" on the road`, { players: [p] })
    for (const stop of city.stops) {
      const locked = await p.has(`stop-${stop.lesson_id}`) && (await p.attr(`stop-${stop.lesson_id}`, 'aria-disabled')) === 'true'
      await s.check(locked === !stop.unlocked, `the stop of lesson ${stop.lesson_id} is ${stop.unlocked ? 'open' : 'closed'} as the API says`, { players: [p], actual: `locked on screen: ${locked}` })
    }
    if (city.boss.state === 'locked_by_plan') await s.check(await p.has('boss-pro'), `the boss of city ${city.number} is the Pro chip`, { players: [p] })
  }
  await s.check((await p.data('daily-card', 'state')) === 'ready' && (await p.text('daily-text')).includes(String(map.daily.due_count)), 'the card of the caravan of the day says how many words are due', { players: [p], expected: `ready, ${map.daily.due_count}`, actual: await p.text('daily-card') })

  s.step('stop 2: the sheet, "В путь" -> a run')
  await p.click('stop-2')
  await p.waitFor('stop-sheet')
  await s.check((await p.text('stop-sheet')).includes('Стоянка 2'), 'the sheet is of stop 2', { players: [p], actual: await p.text('stop-sheet') })
  mark = p.mark()
  await p.click('stop-go')
  const created = await p.waitCall(/\/game\/runs$/, { method: 'POST', since: mark, status: 201 })
  const started = JSON.parse(created.body)
  await p.waitFor('game-run-view')
  await s.check(started.kind === 'stop' && started.lesson_id === 2 && started.course?.slug === 'default', 'the new run knows its course and its stop (G2.7a seam)', { players: [p], since: mark, actual: `${started.kind} lesson ${started.lesson_id} course ${started.course?.slug}` })
  await s.check(created.req === JSON.stringify({ kind: 'stop', course: 'default', lesson_id: 2 }), 'the start asks for the stop of the course and nothing else', { players: [p], since: mark, actual: created.req })
  await s.check(path(p) === `/twa/game/runs/${started.id}`, 'the run has an address without a query', { players: [p], actual: path(p) })
  s.note(`run #${started.id}: ${started.total} elements`)

  s.step('the run: a mistake and a "Не знаю" on purpose, the rest right')
  const run = runRow(env.oracle, started.id)
  const wrongAt = run.snapshot.elements.findIndex((element, index) => index >= 1 && TYPED_OR_CHOICE.includes(element.kind))
  const skipAt = run.snapshot.elements.findIndex((element, index) => index > wrongAt && TYPED_OR_CHOICE.includes(element.kind))
  let hinted = false
  let listened = false
  const events = []
  const { answers, last } = await playRun(env, s, p, started.id, {
    plan: (index) => (index === wrongAt ? 'wrong' : index === skipAt ? 'skip' : 'right'),
    hooks: {
      event: (kind) => events.push(kind),
      async task(element, index) {
        const bazaar = run.snapshot.events.some((event) => event.at === index && event.type === 'bazaar')
        const offered = await p.has('hint-offer')
        const hintable = (await p.page.evaluate(() => document.querySelector('[data-testid="hint-btn"]') !== null)) && offered
        if (bazaar && hintable && !hinted) {
          hinted = true
          s.step('the bazaar: a hint for coins the learner does not have')
          const before = p.mark()
          await p.click('hint-btn')
          const call = await p.waitCall(/\/hint$/, { method: 'POST', since: before, status: 409 })
          await s.check(JSON.parse(call.body).error.code === 'coins_insufficient', 'a hint without coins is 409 coins_insufficient', { players: [p], since: before, actual: call.body })
          await p.waitFor('toast')
          await s.check((await p.text('toast')).includes('монет'), 'the toast tells the coins are short', { players: [p], actual: await p.text('toast') })
          await s.check(await p.visible(GAME_TASK[element.kind]), 'the task stays after the refusal', { players: [p] })
        }
        if (element.kind === 'listen' && !listened) {
          listened = true
          await until(() => p.calls(/\/audio\//).some((call) => call.path.includes(element.id) && call.status === 200), { label: 'the clip of the listen task is fetched', timeout: 8000 })
          const clip = p.calls(/\/audio\//).find((call) => call.path.includes(element.id))
          await s.check(clip.type.includes('audio'), 'the clip comes as audio', { players: [p], actual: clip.type })
        }
      },
    },
  })
  await s.check(answers.length === run.total, `all ${run.total} elements were answered on the screen`, { players: [p], actual: answers.length })
  await s.check(events.length === run.snapshot.events.length, 'every event of the run showed its banner', { players: [p], expected: run.snapshot.events.map((event) => event.type).join(), actual: events.join() })

  s.step('the summary against the answers, the API and the database')
  await waitStatus(p, ['summary'])
  const shown = await readSummary(p)
  const summary = last.run.summary
  const row = runRow(env.oracle, started.id)
  const mistakes = answers.filter((answer) => answer.intent !== 'right')
  const opts = { players: [p] }
  await s.check(summary !== null && last.run.status === 'finished', 'the last answer carries the summary of a finished run', { ...opts, actual: `${last.run.status}` })
  await s.check(shown.outcome === 'completed' && shown.title === 'Забег пройден', 'the run was completed', { ...opts, actual: `${shown.outcome} ${shown.title}` })
  await s.check(shown.score === summary.score && shown.stars === summary.stars, 'the screen shows the score and the stars of the API', { ...opts, expected: `${summary.score}, ${summary.stars} stars`, actual: `${shown.score}, ${shown.stars} stars` })
  await s.check(summary.stars >= 1 && summary.stars <= 3, 'a completed run has 1-3 stars', { ...opts, actual: summary.stars })
  await s.check(shown.mistakes.length === mistakes.length && summary.mistakes.length === mistakes.length, `the summary lists exactly the ${mistakes.length} mistakes made`, { ...opts, expected: mistakes.length, actual: `screen ${shown.mistakes.length}, API ${summary.mistakes.length}` })
  for (const [index, mistake] of shown.mistakes.entries()) {
    const wanted = summary.mistakes[index]
    await s.check(wanted && mistake.label === wanted.label, `mistake ${index + 1} names the word of the API ("${wanted?.label}")`, { ...opts, actual: mistake.label })
  }
  await s.check(shown.mistakes.map((mistake) => mistake.verdict).join() === mistakes.map((mistake) => (mistake.intent === 'skip' ? 'Не знали' : 'Неверно')).join(), 'the verdicts of the mistakes are in words ("Неверно", "Не знали")', { ...opts, actual: shown.mistakes.map((mistake) => mistake.verdict).join() })
  await s.check(shown.again === true, 'a run of a stop offers "Ещё раз"', opts)
  await s.check(row.status === 'finished' && row.finish_reason === 'completed' && row.answered === run.total, 'the row of the run in the database: finished, completed, all answered', { ...opts, actual: `${row.status}/${row.finish_reason} answered ${row.answered}` })
  await s.check(row.score === summary.score && row.stars === summary.stars && row.wrong + row.typos >= 1, 'the row has the score and the stars of the summary and the mistakes', { ...opts, actual: `score ${row.score} stars ${row.stars} wrong ${row.wrong}` })
  if (summary.coins > 0) await s.check(shown.coins?.includes(`+${summary.coins}`) === true, 'the coins of the API are on the screen', { ...opts, actual: String(shown.coins) })
  else await s.check(shown.coins === null, 'no coins on the screen where the API gave none', { ...opts, actual: String(shown.coins) })
  await s.check((shown.streakHeld !== null) === (summary.streak_held === true), 'the screen says "Серия удержана" exactly when the API does', { ...opts, expected: String(summary.streak_held), actual: String(shown.streakHeld) })
  const reread = await api.get(`/game/runs/${started.id}`)
  await s.check(reread.status === 200 && reread.json?.status === 'finished' && reread.json.summary?.score === summary.score, 'GET of the run says the same summary', { ...opts, actual: `${reread.status}` })

  s.step('"Today" after the run: the streak held by the run')
  mark = p.mark()
  await p.go('/today')
  await p.waitFor('today-view')
  await p.waitCall('/today', { since: mark, status: 200 })
  const after = p.lastJson('/api/twa/today', { since: mark })
  await s.check(after?.streak?.held_by === 'game' && after.streak.met_today === true, '/today: the day is held by the run', { ...opts, since: mark, actual: JSON.stringify(after?.streak) })
  await p.waitFor('streak-held-by-game')
  await s.check((await p.text('streak-held-by-game')) === 'Серия удержана забегом', 'the streak card says "Серия удержана забегом"', { ...opts, actual: await p.text('streak-card') })
  await s.check(!(await p.has('streak-safe')), 'not the usual "На сегодня серия продлена"', opts)
  await s.check(after.game.daily_available === true, 'the caravan of the day is still waiting (the run was a stop)', { ...opts, actual: JSON.stringify(after.game) })

  s.step('"Ещё раз" from the summary (the run tells the course and the stop; no query was given)')
  // The summary is gone with the screen; a second run of the same stop ends at once through the API and is read in the browser.
  const again = await api.post('/game/runs', { kind: 'stop', course: 'default', lesson_id: 2, replace_active: true })
  await s.check(again.status === 201 && again.json.lesson_id === 2, 'a second run of the stop starts through the API', { ...opts, actual: `${again.status}` })
  await p.go(`/game/runs/${again.json.id}`)
  await p.waitFor('game-run-view')
  const second = await playRun(env, s, p, again.json.id, { plan: () => 'right' })
  await waitStatus(p, ['summary'])
  await s.check(second.answers.length === again.json.total, 'the second run is played to the end', { ...opts, actual: second.answers.length })
  const stars = (await readSummary(p)).stars
  await p.click('again-btn')
  await p.waitFor('stop-sheet')
  await s.check(path(p) === '/twa/game?course=default&stop=2', '"Ещё раз" leads to the stop of the course by the run itself', { ...opts, actual: path(p) })
  await s.check((await p.text('stop-sheet')).includes('Стоянка 2') && (await p.text('stop-best')).includes('Лучший счёт'), 'the map opens the sheet of stop 2 with the best score', { ...opts, actual: await p.text('stop-sheet') })
  const best = p.lastJson('/api/twa/game/map')
  const stop2 = best.cities.flatMap((city) => city.stops).find((stop) => stop.lesson_id === 2)
  await s.check(stop2.runs >= 2 && stop2.stars >= stars, 'the map counts both runs of the stop and keeps the best stars', { ...opts, actual: JSON.stringify(stop2) })
  await finish(s)
}

// ---------------------------------------------------------------------------------------- the daily

async function gameDaily(env, s) {
  const p = await env.newPlayer(s, 'b')
  const api = env.api('b')
  const opts = { players: [p] }

  s.step('"Today": the entry -> the caravan of the day')
  let mark = p.mark()
  const today = await openToday(p, mark)
  await p.waitFor('game-entry')
  await s.check(today?.game?.daily_available === true, '/today says the caravan of the day is waiting', { ...opts, since: mark, actual: JSON.stringify(today?.game) })
  mark = p.mark()
  await p.click('game-entry')
  await p.waitFor('game-daily-view')
  await p.waitFor('ready')
  const map = p.lastJson('/api/twa/game/map', { since: mark })
  await s.check(path(p) === '/twa/game/daily', 'the entry leads to /game/daily', { ...opts, actual: path(p) })
  await s.check(map?.daily?.available === true && map.daily.due_count === today.due_count, 'the map and /today agree on the words that are due', { ...opts, since: mark, actual: `map ${map?.daily?.due_count}, today ${today.due_count}` })
  await s.check((await p.text('ready-text')).includes(String(map.daily.due_count)) && (await p.text('ready-text')).includes('разминка'), 'the screen says how many words are due', { ...opts, actual: await p.text('ready-text') })

  s.step('"В путь": POST {kind: daily}')
  mark = p.mark()
  await p.click('start-btn')
  const created = await p.waitCall(/\/game\/runs$/, { method: 'POST', since: mark, status: 201 })
  const started = JSON.parse(created.body)
  await p.waitFor('game-run-view')
  await s.check(created.req === JSON.stringify({ kind: 'daily' }), 'the start names the kind only: no course, no lesson', { ...opts, since: mark, actual: created.req })
  await s.check(started.kind === 'daily' && started.lesson_id === null && started.course?.slug === 'default', 'the run is a caravan of the day of the active course, with no stop', { ...opts, since: mark, actual: `${started.kind} lesson ${started.lesson_id} course ${started.course?.slug}` })
  const run = runRow(env.oracle, started.id)
  await s.check(run.snapshot.elements.every((element) => isWordKind(element.kind)), 'the caravan is made of tasks of words (no pairs, phrases or dictations)', { ...opts, actual: run.snapshot.elements.map((element) => element.kind).join() })

  s.step('the run to the summary')
  const { answers, last } = await playRun(env, s, p, started.id, { plan: () => 'right' })
  await waitStatus(p, ['summary'])
  const shown = await readSummary(p)
  const row = runRow(env.oracle, started.id)
  await s.check(answers.length === run.total, `all ${run.total} elements were answered`, { ...opts, actual: answers.length })
  await s.check(shown.outcome === 'completed' && shown.score === last.run.summary.score && shown.stars === last.run.summary.stars, 'the summary is the one of the API', { ...opts, actual: JSON.stringify(shown) })
  await s.check(shown.again === false, 'the caravan of the day has no "Ещё раз" (one a day)', opts)
  await s.check(row.kind === 'daily' && row.status === 'finished' && row.finish_reason === 'completed' && row.daily_key !== null && row.lesson_id === null, 'the row: a finished daily with its day key', { ...opts, actual: `${row.kind}/${row.status}/${row.finish_reason}/${row.daily_key}` })

  s.step('to the map: the card says the day is played')
  mark = p.mark()
  await p.click('map-btn')
  await p.waitFor('road')
  await s.check(path(p) === '/twa/game?course=default', '"На карту" leads to the map of the run\'s course, no query was given', { ...opts, actual: path(p) })
  await s.check((await p.data('daily-card', 'state')) === 'done' && (await p.text('daily-text')) === 'Сыграно, завтра снова', 'the card of the caravan of the day: "Сыграно, завтра снова"', { ...opts, actual: await p.text('daily-card') })
  const done = p.lastJson('/api/twa/game/map', { since: mark })
  await s.check(done.daily.done_today === true && done.daily.available === false, 'the map: played today, not available', { ...opts, since: mark, actual: JSON.stringify(done.daily) })

  s.step('the card -> the screen "done", then the usual review in the cards')
  await p.click('daily-card')
  await p.waitFor('done')
  await s.check(path(p) === '/twa/game/daily?course=default', 'the card leads to /game/daily with the course', { ...opts, actual: path(p) })
  await s.check((await p.attr('done', 'data-resets-at')) === done.daily.resets_at, 'the screen knows when the next one comes (resets_at of the map)', { ...opts, expected: done.daily.resets_at, actual: await p.attr('done', 'data-resets-at') })
  await s.check((await p.text('done')).includes('Следующий — завтра'), 'it says the next one is tomorrow', { ...opts, actual: await p.text('done') })
  mark = p.mark()
  await p.click('review-btn')
  await p.waitCall(/\/training\/sessions$/, { method: 'POST', since: mark, status: 200 })
  await until(() => /\/twa\/training\/\d+$/.test(path(p)), { label: 'the review in the cards opens' })

  s.step('a second start through the API: 409 game_daily_done with resets_at')
  const second = await api.post('/game/runs', { kind: 'daily' })
  await s.check(second.status === 409 && second.json?.error?.code === 'game_daily_done' && second.json.error.details?.resets_at === done.daily.resets_at, 'the second start is 409 game_daily_done {resets_at}', { ...opts, actual: `${second.status} ${second.text.slice(0, 200)}` })

  s.step('a lost race: the day is spent by another device while the screen still says "ready"')
  const d = await env.newPlayer(s, 'd')
  mark = d.mark()
  await d.open('/twa/game/daily')
  await d.waitFor('ready')
  const spent = await env.api('d').post('/game/runs', { kind: 'daily' })
  await s.check(spent.status === 201, 'the other device starts the caravan', { players: [d], actual: String(spent.status) })
  await env.api('d').post(`/game/runs/${spent.json.id}/abandon`, { confirm: true })
  mark = d.mark()
  await d.click('start-btn')
  const refused = await d.waitCall(/\/game\/runs$/, { method: 'POST', since: mark, status: 409 })
  await d.waitFor('done')
  await s.check(JSON.parse(refused.body).error.code === 'game_daily_done', 'the browser is refused with 409 game_daily_done', { players: [d], since: mark, actual: refused.body })
  await s.check((await d.attr('done', 'data-resets-at')) === JSON.parse(refused.body).error.details.resets_at, 'the screen shows "done" with the resets_at of the refusal', { players: [d], actual: await d.attr('done', 'data-resets-at') })
  await s.check(!(await d.has('toast')), 'no toast: the day is simply spent', { players: [d] })
  mark = d.mark()
  await d.go('/today')
  await d.waitFor('today-view')
  await d.waitCall('/today', { since: mark, status: 200 })
  await s.check(!(await d.has('game-entry')) && d.lastJson('/api/twa/today', { since: mark })?.game?.daily_available === false, '"Today" has no entry once the day is spent', { players: [d], actual: await d.textNow('today-view') })
  await finish(s)
}

// ---------------------------------------------------------------------------------------- the reload

/** What the learner sees of an element: the place, the score and the task. */
async function screenOf(p) {
  return p.page.evaluate(() => {
    const text = (id) => document.querySelector(`[data-testid="${id}"]`)?.textContent.replace(/\s+/g, ' ').trim() ?? null
    return {
      status: document.querySelector('[data-testid="game-run-view"]')?.dataset.status ?? null,
      position: text('position'),
      score: text('score'),
      prompt: text('task-prompt'),
      task: ['choice-task', 'spell-task', 'gap-task', 'pairs-board', 'collocation-choice', 'sentence-builder', 'dictation-input'].find((id) => document.querySelector(`[data-testid="${id}"]`) !== null) ?? null,
      options: [...document.querySelectorAll('[data-testid="choice-task"] button[data-testid^="option-"]')].map((button) => button.textContent.replace(/\s+/g, ' ').trim()).join('|'),
    }
  })
}

async function gameReload(env, s) {
  const p = await env.newPlayer(s, 'c')
  const opts = { players: [p] }

  s.step('the map with ?stop=3 -> the sheet opens at once -> a run')
  let mark = p.mark()
  await p.open('/twa/game?course=default&stop=3')
  await p.waitFor('stop-sheet')
  await p.click('stop-go')
  const created = await p.waitCall(/\/game\/runs$/, { method: 'POST', since: mark, status: 201 })
  const started = JSON.parse(created.body)
  await p.waitFor('game-run-view')
  await s.check(started.lesson_id === 3, 'the run is of stop 3', { ...opts, actual: String(started.lesson_id) })

  s.step('two answers, then a reload before the third')
  await playRun(env, s, p, started.id, { stopAfter: 2 })
  await waitStatus(p, ['playing'])
  const before = await screenOf(p)
  const element = currentElement(env.oracle, started.id)
  await s.check(element.index === 2 && before.position === '3 / ' + started.total, 'the third element is on screen', { ...opts, actual: `${before.position}, index ${element.index}` })
  mark = p.mark()
  await p.page.reload({ waitUntil: 'domcontentloaded' })
  await p.waitFor('game-run-view')
  await waitStatus(p, ['playing'])
  const after = await screenOf(p)
  await s.check(JSON.stringify(after) === JSON.stringify(before), 'after the reload: the same element, the same place, the same score', { ...opts, since: mark, expected: JSON.stringify(before), actual: JSON.stringify(after) })
  await s.check(path(p) === `/twa/game/runs/${started.id}`, 'the address is the run\'s', { ...opts, actual: path(p) })

  s.step('an answer, then a reload on its review: the answer is counted once, the next element is on screen')
  const afterAnswer = await playRun(env, s, p, started.id, { plan: (index, el) => (isWordKind(el.kind) ? 'wrong' : 'right'), stopAfter: 1, hooks: { reveal: async () => undefined } })
  // playRun pressed "Дальше"; the reload below is at the next task, which the server has already moved to.
  await waitStatus(p, ['playing', 'summary'])
  const row = runRow(env.oracle, started.id)
  const next = await screenOf(p)
  mark = p.mark()
  await p.page.reload({ waitUntil: 'domcontentloaded' })
  await p.waitFor('game-run-view')
  await waitStatus(p, ['playing', 'summary'])
  const reloaded = await screenOf(p)
  await s.check(JSON.stringify(reloaded) === JSON.stringify(next), 'the element after the reload is the one the server waits for', { ...opts, since: mark, expected: JSON.stringify(next), actual: JSON.stringify(reloaded) })
  await s.check(row.answered === 3 && runRow(env.oracle, started.id).answered === 3, 'the database counted three answers, the reload added none', { ...opts, actual: String(row.answered) })
  await s.check(afterAnswer.answers.length === 1, 'one answer was given after the first reload', opts)

  s.step('✕ -> "Выйти" abandons the run and leads to the map of the run\'s course')
  mark = p.mark()
  await p.click('close-btn')
  await p.waitFor('exit-sheet')
  await p.click('leave-btn')
  await p.waitCall(/\/abandon$/, { method: 'POST', since: mark, status: 200 })
  await p.waitFor('road')
  await s.check(path(p) === '/twa/game?course=default', 'the map of the run\'s course (the route of the run had no query)', { ...opts, actual: path(p) })
  const left = runRow(env.oracle, started.id)
  await s.check(left.status === 'finished' && left.finish_reason === 'abandoned', 'the database: abandoned', { ...opts, actual: `${left.status}/${left.finish_reason}` })
  await s.check(!(await p.has('active-run')), 'the map has no banner of an unfinished run', opts)

  s.step('the address of the abandoned run: "Забег закончился"')
  await p.go(`/game/runs/${started.id}`)
  await until(async () => (await runStatus(p)) === 'closed', { label: 'the run is closed' })
  await s.check((await p.say()).includes('Забег закончился') && (await p.has('map-btn')), 'the closed run says so and leads to the map', { ...opts, actual: await p.say() })
  await finish(s)
}
