// What a learner does in a run of "Karvon" (docs/05 §5b.3), shared by the scenarios of game.mjs and its pictures. `element` is the element
// of the snapshot of the run in the database of the stand (the oracle: the API never sends the right answers, docs/18 §12.8); what the
// screen shows and what the API says is judged separately.
import { randomUUID } from 'node:crypto'
import { E2EError, sel, until } from './kit.mjs'
import { boardCards, doCollocation, doDictation, doSentence, matchPair, normalize, rightOrder } from './screens.mjs'

/** The component each kind of element is drawn with. */
export const GAME_TASK = {
  translate_fwd: 'choice-task',
  translate_back: 'choice-task',
  listen: 'choice-task',
  spell: 'spell-task',
  gap: 'gap-task',
  pairs_round: 'pairs-board',
  collocation: 'collocation-choice',
  sentence: 'sentence-builder',
  dictation: 'dictation-input',
}
export const GAME_KINDS = Object.keys(GAME_TASK)
const WORD_KINDS = ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap']
export const isWordKind = (kind) => WORD_KINDS.includes(kind)
/** The kinds a wrong answer costs one flask for sure (a round of pairs may take mismatches before it ends). */
export const TYPED_OR_CHOICE = WORD_KINDS

const VERDICT_OF = { right: 'correct', wrong: 'wrong', skip: 'skipped', typo: 'typo' }
export const verdictOf = (intent) => VERDICT_OF[intent]

/** The text of a translated value of the snapshot (`{ru: ...}`) or a plain one. */
export const textOf = (value) => (typeof value === 'string' ? value : (value?.ru ?? Object.values(value ?? {})[0] ?? ''))

// ------------------------------------------------------------------------------------- the oracle

/** The run as the database has it (read only): the row, its snapshot and its state. */
export function runRow(oracle, id) {
  const row = oracle.get('SELECT * FROM game_runs WHERE id = ?', id)
  if (!row) throw new E2EError(`no game run #${id}`)
  return { ...row, snapshot: JSON.parse(row.snapshot), state: JSON.parse(row.state) }
}

/** The element the server is waiting for (a round of pairs: the current round). */
export function currentElement(oracle, id) {
  const run = runRow(oracle, id)
  const element = run.snapshot.elements[run.state.current]
  if (!element) throw new E2EError(`run #${id} has no current element (index ${run.state.current})`)
  return { element, index: run.state.current, run }
}

// ------------------------------------------------------------------------------------ the screen

export const runStatus = (p) => p.data('game-run-view', 'status')

/** Waits for the run to stand at one of the statuses. A banner of an event that is on screen is handed to `onEvent` once. */
export async function waitStatus(p, statuses, { timeout = 20_000, onEvent } = {}) {
  let announced = false
  try {
    return await until(
      async () => {
        const status = await runStatus(p)
        if (status === 'event' && onEvent && !announced) {
          announced = true
          await onEvent(await p.data('event-banner', 'event'))
        }
        return statuses.includes(status) ? status : null
      },
      { timeout, interval: 80, label: `${p.name}: the run at ${statuses.join(' / ')}` },
    )
  } catch (error) {
    throw new E2EError(`${error.message}; the screen says: "${await p.say()}"`)
  }
}

/** The options of a choice task as drawn: [{ testid, text }]. */
async function shownOptions(p) {
  return p.page.evaluate(() =>
    [...document.querySelectorAll('[data-testid="choice-task"] button[data-testid^="option-"]')].map((button) => ({
      testid: button.dataset.testid,
      text: button.querySelector('span.block')?.textContent.replace(/\s+/g, ' ').trim() ?? button.innerText.replace(/\s+/g, ' ').trim(),
    })),
  )
}

async function doChoice(p, element, intent) {
  if (intent === 'skip') {
    await p.click('skip-btn')
    return null
  }
  const right = normalize(textOf(element.options.find((option) => option.id === element.correct_option_id).text))
  const options = await shownOptions(p)
  const pick = options.find((option) => (normalize(option.text) === right) === (intent === 'right'))
  if (!pick) throw new E2EError(`no ${intent} option among [${options.map((option) => option.text).join(', ')}] (the right one: ${right})`)
  await p.click(pick.testid)
  return pick.text
}

/** `spell` and `gap`: a word typed into the field and sent with "Проверить". */
async function doTyped(p, element, intent) {
  if (intent === 'skip') {
    await p.click('skip-btn')
    return null
  }
  const expected = [].concat(element.expected)[0]
  const text = intent === 'right' ? expected : 'zzzzzzzz'
  await p.page.fill(sel('answer-input'), text)
  await until(async () => !(await p.$('check-btn').isDisabled()), { label: 'the button "Проверить"' })
  await p.click('check-btn')
  return text
}

/** A round of pairs: every match through the board; `wrong` begins with a mismatch. Returns the replies of the API. */
async function doPairs(p, element, intent) {
  const lefts = (await boardCards(p)).left
  const replies = []
  if (intent === 'wrong') {
    const wrongRight = Object.values(element.pairs).find((right) => right !== element.pairs[lefts[0].id])
    const miss = (await matchPair(p, lefts[0].id, wrongRight, { last: true })).json
    replies.push(miss)
    // The last flask can go on this mismatch: the run is over, there is no board to go on with.
    if (miss?.run?.status !== 'active') return replies
    await until(async () => (await p.data(`left-${lefts[0].id}`, 'state')) !== 'selected', { label: 'the verdict of the mismatch on the card' })
  }
  for (const [index, card] of lefts.entries()) replies.push((await matchPair(p, card.id, element.pairs[card.id], { last: index === lefts.length - 1 })).json)
  return replies
}

/**
 * Answers the element on screen. Returns what the learner sent and the replies of the API (`replies`: one per call, a round of pairs has
 * several). `intent`: right / wrong / skip.
 */
export async function answerElement(p, element, intent) {
  const mark = p.mark()
  let sent = null
  switch (element.kind) {
    case 'translate_fwd':
    case 'translate_back':
    case 'listen':
      sent = await doChoice(p, element, intent)
      break
    case 'spell':
    case 'gap':
      sent = await doTyped(p, element, intent)
      break
    case 'pairs_round':
      await doPairs(p, element, intent)
      break
    case 'collocation':
      sent = intent === 'skip' ? (await p.click('skip-btn'), null) : await doCollocation(p, element, intent)
      break
    case 'sentence':
      sent = await doSentence(p, element, intent)
      break
    case 'dictation':
      sent = await doDictation(p, element, intent)
      break
    default:
      throw new E2EError(`no way to answer an element of the kind ${element.kind}`)
  }
  await p.waitCall(/\/game\/runs\/\d+\/answer$/, { method: 'POST', since: mark, timeout: 10_000 })
  const replies = p
    .calls(/\/game\/runs\/\d+\/answer$/, { method: 'POST', since: mark })
    .filter((call) => call.status === 200 && call.body)
    .map((call) => JSON.parse(call.body))
  return { sent, replies, mark }
}

/** The reveal that stands on the screen: the word kinds have their own, the four of the practice theirs. */
export async function revealOf(p) {
  try {
    await until(async () => (await p.has('word-reveal')) || (await p.has('answer-reveal')), { timeout: 10_000, label: `${p.name}: the review of the answer` })
  } catch (error) {
    throw new E2EError(`${error.message}; the screen says: "${await p.say()}"`)
  }
  // `game-reveal` is the frame of both; the review of a word task is `word-reveal`, the four of the practice are `answer-reveal`.
  if (await p.has('word-reveal')) {
    return { own: true, verdict: await p.data('verdict-circle', 'verdict'), text: await p.textNow('verdict-label'), right: await p.textNow('right-answer'), yours: await p.textNow('your-answer') }
  }
  return { own: false, verdict: await p.data('answer-reveal', 'verdict'), text: await p.textNow('reveal-text') }
}

/** "Дальше" / "К итогу": whichever button the review has. */
export async function advance(p) {
  if (await p.has('next-btn')) await p.click('next-btn')
  else await p.click('continue-btn')
}

/** What the header says: "3 / 10", the score, the flasks left (the labels of the icons). */
export async function headerOf(p) {
  return p.page.evaluate(() => {
    const text = (id) => document.querySelector(`[data-testid="${id}"]`)?.textContent.replace(/\s+/g, ' ').trim() ?? null
    const position = /(\d+)\s*\/\s*(\d+)/.exec(text('position') ?? '')
    return { position: position ? Number(position[1]) : null, total: position ? Number(position[2]) : null, score: Number(text('score')), caption: text('caption') }
  })
}

/** The summary as it is on the screen. */
export async function readSummary(p) {
  await p.waitFor('run-summary')
  return p.page.evaluate(() => {
    const text = (id) => document.querySelector(`[data-testid="${id}"]`)?.textContent.replace(/\s+/g, ' ').trim() ?? null
    return {
      outcome: document.querySelector('[data-testid="run-summary"]')?.dataset.outcome ?? null,
      title: text('summary-title'),
      score: Number(text('summary-score')),
      stars: document.querySelectorAll('[data-testid="star"][data-full="true"]').length,
      coins: text('reward-coins'),
      league: text('reward-league'),
      newStars: text('reward-new_stars'),
      streakHeld: text('streak-held'),
      mistakes: [...document.querySelectorAll('[data-testid="mistakes"] li')].map((row) => ({
        label: row.querySelector('[data-testid="mistake-label"]')?.textContent.trim() ?? '',
        yours: row.querySelector('[data-testid="mistake-yours"]')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
        verdict: row.querySelector('[data-testid="mistake-verdict"]')?.textContent.trim() ?? '',
      })),
      again: document.querySelector('[data-testid="again-btn"]') !== null,
      review: document.querySelector('[data-testid="review-btn"]') !== null,
    }
  })
}

// -------------------------------------------------------------------------------------- a whole run

/**
 * Plays the run on the screen from its current element to the summary (or to `stopAfter` answers).
 *
 * `plan(index, element)` names the intent of an element (right / wrong / skip). For every element it checks: the header says
 * "N / total", the task is of the kind the server waits for, the verdict on the screen is the verdict of the API and the intent, the
 * score on the screen is the score of the API. `hooks`: `task(element, index)` after the task is drawn, `reveal(element, index, intent)`
 * on the review, `event(kind)` on the banner of an event.
 *
 * @returns {{ answers: Array, last: object|null }} `last`: the reply to the last answer (`run` has the summary)
 */
export async function playRun(env, s, p, runId, { plan = () => 'right', hooks = {}, stopAfter = Infinity } = {}) {
  const answers = []
  let last = null
  for (let step = 0; step < 40 && answers.length < stopAfter; step++) {
    const status = await waitStatus(p, ['playing', 'summary', 'closed', 'error'], { onEvent: hooks.event })
    if (status !== 'playing') break
    const { element, index, run } = currentElement(env.oracle, runId)
    const intent = plan(index, element)
    const opts = { players: [p] }

    const header = await headerOf(p)
    await s.check(header.position === index + 1 && header.total === run.total, `the header says "${index + 1} / ${run.total}"`, { ...opts, expected: `${index + 1} / ${run.total}`, actual: `${header.position} / ${header.total}` })
    await p.waitFor(GAME_TASK[element.kind], { timeout: 10_000 })
    await s.check(await p.visible(GAME_TASK[element.kind]), `element ${index + 1} (${element.kind}) is drawn with ${GAME_TASK[element.kind]}`, opts)
    if (element.kind === 'translate_fwd' || element.kind === 'translate_back' || element.kind === 'listen') {
      const shownKind = await p.data('choice-task', 'kind')
      await s.check(shownKind === element.kind, `the choice task is of the kind the server waits for (${element.kind})`, { ...opts, actual: shownKind })
    }
    if (hooks.task) await hooks.task(element, index, intent)

    const done = await answerElement(p, element, intent)
    const reply = done.replies.at(-1) ?? null
    if (reply === null) throw new E2EError(`no good reply of the API to the answer to element ${index + 1} (${element.kind})`)
    last = reply
    const reveal = await revealOf(p)
    if (element.kind !== 'pairs_round') {
      await s.check(reply.verdict === verdictOf(intent), `the API counts ${intent} as ${verdictOf(intent)} (${element.kind})`, { ...opts, since: done.mark, expected: verdictOf(intent), actual: reply.verdict })
      await s.check(reveal.verdict === reply.verdict, `the review says what the API says (${element.kind}: ${reply.verdict})`, { ...opts, expected: reply.verdict, actual: reveal.verdict })
    } else {
      await s.check(reply.round_done === true || reply.run.status !== 'active', 'the last match of the round ends it (or the last flask went before)', { ...opts, actual: JSON.stringify(reply.round_done) })
    }
    if (reveal.own && intent !== 'skip') {
      await s.check((reveal.right ?? '').length > 0, `the review of ${element.kind} shows the right answer`, { ...opts, actual: reveal.right })
    }
    const afterHeader = await headerOf(p)
    await s.check(afterHeader.score === reply.run.score, `the score on the screen is the score of the API (${reply.run.score})`, { ...opts, expected: reply.run.score, actual: afterHeader.score })
    if (hooks.reveal) await hooks.reveal(element, index, intent, reply)

    answers.push({ index, kind: element.kind, intent, sent: done.sent, verdict: reply.verdict, points: reply.points, run: reply.run })
    await advance(p)
  }
  return { answers, last }
}

/** The first element, from the database, whose kind is of `kinds` and whose index is `from` or later. */
export function firstOf(oracle, runId, kinds, from = 0) {
  const run = runRow(oracle, runId)
  return run.snapshot.elements.findIndex((element, index) => index >= from && kinds.includes(element.kind))
}

// ------------------------------------------------------------------------- a run through the API

/** The right answers to an element, for the API (the oracle knows them): one per call, a round of pairs has several. */
function rightAnswers(element) {
  switch (element.kind) {
    case 'translate_fwd':
    case 'translate_back':
    case 'listen':
      return [{ option_id: element.correct_option_id }]
    case 'spell':
      return [{ text: element.expected }]
    case 'gap':
      return [{ text: [].concat(element.expected)[0] }]
    case 'pairs_round':
      return Object.entries(element.pairs).map(([left, right]) => ({ left_id: left, right_id: right }))
    case 'collocation':
      return [{ option_id: element.correct_ids[0] }]
    case 'sentence':
      return [{ order: rightOrder(element) }]
    case 'dictation':
      return [{ text: element.expected[0] }]
    default:
      throw new E2EError(`no answer to an element of the kind ${element.kind}`)
  }
}

/**
 * Plays a run to its end through the API, every answer right: the quick way to what a scenario needs as a start (the stops that open a
 * boss), not the thing under test. `who` is the learner of `env.api(who)`.
 */
export async function playRunViaApi(env, who, runId) {
  const api = env.api(who)
  for (let step = 0; step < 60; step++) {
    const run = runRow(env.oracle, runId)
    if (run.status !== 'active') return run
    const element = run.snapshot.elements[run.state.current]
    for (const answer of rightAnswers(element)) {
      await env.rest(who, 4)
      const reply = await api.post(`/game/runs/${runId}/answer`, { request_id: randomUUID(), element_id: element.id, answer })
      if (reply.status !== 200) throw new E2EError(`the answer to ${element.kind} of run #${runId} was refused: ${reply.status} ${reply.text.slice(0, 160)}`)
    }
  }
  throw new E2EError(`run #${runId} did not end`)
}
