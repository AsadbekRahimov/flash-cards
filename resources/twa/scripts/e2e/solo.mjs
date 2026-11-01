// Scenario 1 of docs/16 §14.4: each of the four solo modes from the hub of a lesson to the summary, with mistakes made on purpose,
// "Repeat mistakes", the way back to an unfinished session ("Continue"), and an answer whose reply is lost on the way.
import { E2EError, EXPECTED_STATUSES, until } from './kit.mjs'
import { CHIP_WORDS, COMPONENT, MODE_TITLE, VERDICT_WORDS, bankFragments, boardCards, doCollocation, doDictation, doSentence, matchPair, normalize, typoOf, whyNotShown } from './screens.mjs'

const VERDICT = { right: 'correct', wrong: 'wrong', typo: 'typo', skip: 'skipped' }
/**
 * Each mode is played by another demo learner: the API allows a learner 60 calls a minute, a scenario takes 11-23 of them, and four in a
 * row for one learner would wait for the minute to pass.
 */
export function soloScenarios(env) {
  return [
    { name: 'solo:pairs', body: (s) => soloPairs(env, s) },
    { name: 'solo:collocation', body: (s) => soloCollocation(env, s) },
    { name: 'solo:sentence', body: (s) => soloSentence(env, s) },
    { name: 'solo:dictation', body: (s) => soloDictation(env, s) },
  ]
}

// ------------------------------------------------------------------------ the hub and the session

async function openHub(env, s, p) {
  s.step(`open the hub of lesson ${env.cfg.lesson}`)
  const mark = p.mark()
  await p.open(`/twa/practice/${env.cfg.course}?lesson=${env.cfg.lesson}`)
  await p.waitFor('mode-cards')
  const hub = p.lastJson('/practice/hub', { since: mark })
  const states = (hub?.modes ?? []).map((mode) => `${mode.mode}:${mode.state}`)
  await s.check(states.length === 4 && states.every((state) => state.endsWith(':available')), 'the hub of the lesson offers the four modes, all playable', { players: [p], since: mark, actual: states.join(' ') })
  const theme = await p.page.evaluate(() => document.documentElement.dataset.theme)
  await s.check(theme === p.theme, `the theme of Telegram (${p.theme}) is applied`, { players: [p], actual: theme })
  for (const mode of ['pairs', 'collocation', 'sentence', 'dictation']) {
    await s.check((await p.text(`mode-card-${mode}`)).includes(MODE_TITLE[mode]), `the card of "${MODE_TITLE[mode]}" is on the hub`, { players: [p] })
  }
}

/** Presses a card; an unfinished session of an earlier run is replaced. Returns the session the server made. */
async function startSession(s, p, mode) {
  s.step(`start "${MODE_TITLE[mode]}"`)
  // The card of a mode with an unfinished session leads back into it; to begin afresh the old one is replaced from another mode.
  if ((await p.has('continue-card')) && (await p.text('continue-title')) === MODE_TITLE[mode]) {
    s.note('an unfinished session of this mode (an earlier run) is in the way: replaced through another mode')
    await p.click(`mode-card-${mode === 'pairs' ? 'collocation' : 'pairs'}`)
    await p.waitFor('active-session-sheet')
    await p.click('active-replace')
    await p.waitFor('practice-session-view')
    await p.click('close-btn')
    await p.waitFor('mode-cards')
  }
  const mark = p.mark()
  await p.click(`mode-card-${mode}`)
  await until(async () => (await p.has('practice-session-view')) || (await p.has('active-session-sheet')), { label: 'the session or the question about an unfinished one' })
  if (await p.has('active-session-sheet')) {
    s.note('an unfinished session is in the way: "Начать новую"')
    await p.click('active-replace')
  }
  await p.waitFor('practice-session-view')
  const created = await p.waitCall(/\/practice\/sessions$/, { method: 'POST', status: 201, since: mark })
  const session = JSON.parse(created.body)
  await s.check(session.mode === mode && session.status === 'active' && session.source === 'hub', 'the new session is of the pressed mode, active, from the hub', { players: [p], since: mark, actual: `${session.mode}/${session.status}/${session.source}` })
  await p.waitFor(COMPONENT[mode])
  s.note(`session #${session.id}: ${session.progress.total} ${mode === 'pairs' ? 'rounds' : 'elements'}`)
  return session
}

async function readSummary(p) {
  await p.waitFor('practice-summary')
  return p.page.evaluate(() => {
    const text = (id) => document.querySelector(`[data-testid="${id}"]`)?.textContent.replace(/\s+/g, ' ').trim() ?? null
    return {
      done: text('stat-done'),
      first: Number(text('stat-first')),
      third: Number(text('stat-third')),
      facts: text('summary-facts'),
      reason: text('summary-reason'),
      perfect: document.querySelector('[data-testid="no-mistakes"]') !== null,
      retry: document.querySelector('[data-testid="retry-btn"]') !== null,
      again: document.querySelector('[data-testid="again-btn"]') !== null,
      mistakes: [...document.querySelectorAll('[data-testid="mistake-row"]')].map((row) => ({
        label: row.querySelector('[data-testid="mistake-label"]')?.textContent.trim() ?? '',
        answer: row.querySelector('[data-testid="mistake-answer"] .line-through')?.textContent.trim() ?? null,
        chip: row.querySelector('[data-testid="mistake-chip"]')?.textContent.trim() ?? '',
      })),
    }
  })
}

/**
 * The summary on the screen against what the script did (`want`), against the summary the API sent with the last answer and against the
 * row of the database: "the totals match the answers".
 */
async function checkSummary(env, s, p, session, want, { mark = 0 } = {}) {
  s.step('the summary matches the answers')
  const shown = await readSummary(p)
  const api = p.lastJson(new RegExp(`/practice/sessions/${session.id}/answer$`), { method: 'POST', since: mark })?.session?.summary ?? null
  const row = env.oracle.session(session.id)
  const opts = { players: [p], since: mark }
  await s.check(shown.done === `${want.answered} / ${want.total}`, 'the summary counts what was done', { ...opts, expected: `${want.answered} / ${want.total}`, actual: shown.done })
  await s.check(shown.first === want.first, 'the summary counts the answers right at the first try', { ...opts, expected: want.first, actual: shown.first })
  await s.check(shown.third === want.third, `the summary counts the ${want.pairs ? 'wrong matches' : 'hints'}`, { ...opts, expected: want.third, actual: shown.third })
  const typos = want.typos ?? 0
  await s.check(typos === 0 ? !/Опечат/.test(shown.facts ?? '') : (shown.facts ?? '').includes(`Опечаток: ${typos}`), 'the summary counts the typos', { ...opts, expected: typos, actual: shown.facts })
  await s.check(shown.mistakes.length === want.mistakes.length, 'the summary lists exactly the mistakes made', { ...opts, expected: want.mistakes.map((m) => m.chip).join(', ') || 'none', actual: shown.mistakes.map((m) => `${m.chip}: ${m.label}`).join('; ') || 'none' })
  for (const [index, mistake] of want.mistakes.entries()) {
    const got = shown.mistakes[index]
    if (!got) continue
    await s.check(got.chip === CHIP_WORDS[mistake.verdict], `mistake ${index + 1} is marked "${CHIP_WORDS[mistake.verdict]}"`, { ...opts, actual: got.chip })
    if (mistake.label) await s.check(normalize(got.label).includes(normalize(mistake.label)), `mistake ${index + 1} names the task`, { ...opts, expected: mistake.label, actual: got.label })
    if (mistake.answer) await s.check(got.answer !== null && normalize(got.answer) === normalize(mistake.answer), `mistake ${index + 1} shows what was written`, { ...opts, expected: mistake.answer, actual: got.answer })
  }
  await s.check(shown.perfect === (want.mistakes.length === 0), want.mistakes.length === 0 ? 'a session without mistakes says so' : 'a session with mistakes does not say it is perfect', { ...opts })
  await s.check(shown.retry === (want.mistakes.length > 0 && (want.retryAvailable ?? true)), want.mistakes.length > 0 ? '"Повторить ошибки" is offered' : 'no "Повторить ошибки" without mistakes', { ...opts, actual: `retry=${shown.retry} again=${shown.again}` })
  await s.check(shown.reason === 'Практика пройдена.', 'the summary says the practice is done', { ...opts, actual: shown.reason })

  await s.check(api !== null, 'the last answer carries the summary', { ...opts })
  if (api) {
    await s.check(api.answered === want.answered && api.total === want.total && api.correct_first === want.first, 'the API summary matches the answers', { ...opts, expected: `${want.answered}/${want.total}, first ${want.first}`, actual: `${api.answered}/${api.total}, first ${api.correct_first}` })
    await s.check(api.retry_available === shown.retry, 'the screen offers the retry the API allows', { ...opts, expected: api.retry_available, actual: shown.retry })
    await s.check(api.mistakes.length === want.mistakes.length, 'the API lists the mistakes made', { ...opts, expected: want.mistakes.length, actual: api.mistakes.length })
  }
  await s.check(row.status === 'finished' && row.finish_reason === 'completed' && row.answered === want.answered && row.correct_first === want.first, 'the row of the session in the database matches', { ...opts, actual: `${row.status}/${row.finish_reason} answered ${row.answered} first ${row.correct_first}` })
  return shown
}

/** "Repeat mistakes": a new session of the same mode with the mistakes of the old one. */
async function repeatMistakes(env, s, p, session, mode, mistakenItems) {
  s.step('"Повторить ошибки"')
  const mark = p.mark()
  await p.click('retry-btn')
  const created = await p.waitCall(/\/practice\/sessions$/, { method: 'POST', since: mark, status: 201 })
  const retry = JSON.parse(created.body)
  await p.waitFor(COMPONENT[mode])
  await s.check(retry.retry_of === session.id && retry.source === 'retry', 'the repeat points at the session it repeats', { players: [p], since: mark, actual: `retry_of ${retry.retry_of}, source ${retry.source}` })
  await s.check(await p.visible('retry-chip'), 'the repeat is marked "Повтор ошибок" in the header', { players: [p] })
  await s.check(retry.progress.total === mistakenItems.expectTotal, 'the repeat has exactly the mistakes to repeat', { players: [p], since: mark, expected: mistakenItems.expectTotal, actual: retry.progress.total })
  const row = env.oracle.session(retry.id)
  await s.check(row.retry_of_session_id === session.id, 'the database knows what the repeat repeats', { players: [p], actual: row.retry_of_session_id })
  if (mistakenItems.itemIds) {
    const got = row.snapshot.elements.map((element) => element.item_id).sort()
    await s.check(got.join() === [...mistakenItems.itemIds].sort().join(), 'the repeat is made of the tasks that went wrong', { players: [p], expected: [...mistakenItems.itemIds].sort().join(), actual: got.join() })
  }
  return retry
}

// ---------------------------------------------------------------------------- one element, any mode

/** Puts the loss of an answer on the road: the reply is lost after the server counted it, or the request never gets there. */
async function loseAnswer(p, kind) {
  let lost = false
  const pattern = '**/api/twa/practice/sessions/*/answer'
  const handler = async (route) => {
    if (lost) return route.continue()
    lost = true
    if (kind === 'reply') await route.fetch()
    await route.abort('connectionreset')
  }
  await p.page.route(pattern, handler)
  return () => p.page.unroute(pattern, handler)
}

const RESEND_NOTE = { collocation: 'collocation-resend-note', sentence: 'sentence-resend-note', dictation: 'dictation-resend-note' }

/**
 * One element of a session: answers it the way `step.intent` says, reads what the screen and the API say about it, goes on to the next one.
 * `step.lose`: 'reply' (the server counts the answer, the page does not hear) or 'request' (the server never gets it): the page offers to
 * send the SAME answer again, and the repeat must give the same verdict and count once. Returns what the summary has to say about it.
 */
async function answerOne(env, s, p, session, step, { last = false } = {}) {
  const { oracle } = env
  const element = oracle.sessionElement(session.id)
  const kind = element.kind
  const want = VERDICT[step.intent]
  s.step(`${kind}: ${step.intent}${step.lose ? ` (the ${step.lose} is lost)` : ''}`)
  const why = await whyNotShown(p, element)
  await s.check(why === null, 'the screen shows the element the server waits for', { players: [p], actual: why })
  if (step.shot) await p.shot(`${step.shot}-task`)

  const attemptsBefore = oracle.attempts(session.id).length
  const mark = p.mark()
  const restore = step.lose ? await loseAnswer(p, step.lose) : null
  let given = null
  if (kind === 'collocation') given = await doCollocation(p, element, step.intent)
  else if (kind === 'sentence') given = await doSentence(p, element, step.intent)
  else if (kind === 'dictation') given = await doDictation(p, element, step.intent)
  else throw new E2EError(`answerOne does not play ${kind}`)

  if (step.lose) {
    s.note(`the ${step.lose} of the answer is lost`)
    await p.waitFor(RESEND_NOTE[kind], { timeout: 15_000 })
    const first = p.calls(/\/answer$/, { method: 'POST', since: mark })[0]
    await s.check(first !== undefined && first.status === 0, 'the page saw the answer fail', { players: [p], since: mark })
    const counted = oracle.attempts(session.id).length - attemptsBefore
    await s.check(counted === (step.lose === 'reply' ? 1 : 0), step.lose === 'reply' ? 'the server counted the answer whose reply was lost' : 'the server did not get the request that was dropped', { players: [p], since: mark, expected: step.lose === 'reply' ? 1 : 0, actual: counted })
    await p.shot(`${step.shot ?? kind}-resend`)
    // "Retry": the same answer goes again (the button that sent it).
    if (kind === 'collocation') await p.click('resend-btn')
    else await p.click(step.intent === 'skip' ? 'skip-btn' : 'check-btn')
  }

  await p.waitFor('answer-reveal', { timeout: 15_000 })
  const calls = p.calls(/\/answer$/, { method: 'POST', since: mark })
  const answered = calls.filter((call) => call.status === 200)
  const api = answered.length > 0 ? JSON.parse(answered[0].body) : null
  const verdict = await p.data('answer-reveal', 'verdict')
  await s.check(verdict === want, `the screen says "${want}" for the ${step.intent} answer`, { players: [p], since: mark, expected: want, actual: verdict })
  await s.check(api?.verdict === want, `the API says "${want}" for the ${step.intent} answer`, { players: [p], since: mark, expected: want, actual: api?.verdict })
  await s.check(answered.length === 1, 'exactly one answer was accepted', { players: [p], since: mark, actual: calls.map((call) => call.status || call.failure).join(', ') })
  if (step.lose) {
    const sent = calls.map((call) => JSON.parse(call.req ?? '{}'))
    await s.check(sent.length === 2 && sent[0].request_id === sent[1].request_id && JSON.stringify(sent[0].answer) === JSON.stringify(sent[1].answer), 'the repeat carries the same request_id and the same answer', { players: [p], since: mark, actual: JSON.stringify(sent.map((body) => [body.request_id, body.answer])) })
    await s.check(!calls.some((call) => call.status === 409), 'no 409 after the repeat', { players: [p], since: mark })
    await s.check(!(await p.has('reveal-earlier')), 'the screen does not say "an answer sent earlier was counted"', { players: [p] })
    await s.check(oracle.attempts(session.id).length - attemptsBefore === 1, 'the answer is in the journal of the server once', { players: [p], since: mark })
  }
  await s.check(api?.first_attempt === true, 'the answer is the first attempt', { players: [p], since: mark, actual: api?.first_attempt })
  await checkReveal(s, p, element, step.intent, want)
  if (step.shot) await p.shot(`${step.shot}-reveal`)
  await restore?.()

  // The clip of a dictation can be heard again on the review.
  if (kind === 'dictation' && step.listenAgain) await listenAgain(s, p)

  await p.click('continue-btn')
  if (!last) await until(async () => !(await p.has('answer-reveal')), { label: 'the next element after "Дальше"' })
  return { verdict: want, intent: step.intent, given, element }
}

/** The screen of the review says what the snapshot knows about the element. */
async function checkReveal(s, p, element, intent, verdict) {
  const opts = { players: [p] }
  const expected = await p.textNow('expected')
  if (element.kind === 'dictation') await s.check(expected !== null && normalize(expected) === element.expected[0], 'the review shows the phrase as it is written', { ...opts, expected: element.expected[0], actual: expected })
  if (element.kind === 'sentence') await s.check(expected !== null && normalize(expected) === element.accepted[0], 'the review shows the right sentence', { ...opts, expected: element.accepted[0], actual: expected })
  if (element.kind === 'collocation') await s.check(expected !== null && normalize(expected) === normalize(element.full_text), 'the review shows the whole phrase', { ...opts, expected: element.full_text, actual: expected })
  const translation = element.translation?.ru ?? null
  if (translation) await s.check((await p.textNow('translation'))?.includes(translation) === true, 'the review has the translation', { ...opts, expected: translation, actual: await p.textNow('translation') })
  const explanation = element.explanation?.ru ?? null
  if (explanation) await s.check((await p.textNow('explanation'))?.includes(explanation) === true, 'the review has the explanation', { ...opts, expected: explanation, actual: await p.textNow('explanation') })
  const title = await p.textNow('reveal-text')
  if (verdict === 'correct') await s.check(title === VERDICT_WORDS.correct, 'the verdict is told in words', { ...opts, expected: VERDICT_WORDS.correct, actual: title })
  if (verdict === 'typo') {
    await s.check(title === VERDICT_WORDS.typo, 'the verdict is told in words', { ...opts, expected: VERDICT_WORDS.typo, actual: title })
    const wrongWords = await p.page.locator('[data-testid="diff-token"][data-ok="false"]').count()
    await s.check(wrongWords === 1, 'a typo marks exactly one word of what was typed', { ...opts, expected: 1, actual: wrongWords })
  }
  if (verdict === 'skipped') await s.check(title === VERDICT_WORDS.skipped, 'a skipped task is told as a hint', { ...opts, expected: VERDICT_WORDS.skipped, actual: title })
  if (verdict === 'wrong') await s.check(title !== null && title !== VERDICT_WORDS.correct, 'a wrong answer is not told as right', { ...opts, actual: title })
  await s.check((await p.overflow()) <= 0, 'the review has no sideways scroll', { ...opts, actual: `${await p.overflow()} px` })
}

/** The clip of a dictation: fetched with the token, a blob that decodes in an `<audio>`. */
async function checkClip(s, p, session, element) {
  s.step('the clip of the dictation: blob and <audio>')
  await p.waitFor('audio-state')
  await until(async () => (await p.data('audio-state', 'state')) === 'ready', { label: 'the clip of the dictation to be ready', timeout: 15_000 })
  const call = p.calls(`/practice/sessions/${session.id}/audio/`, { method: 'GET' }).find((entry) => entry.status === 200)
  await s.check(call !== undefined, 'the clip is fetched from the endpoint of the session', { players: [p] })
  if (call) {
    await s.check(call.type.startsWith('audio/mpeg'), 'the clip is audio/mpeg', { players: [p], actual: call.type })
    await s.check(call.length > 1000, 'the clip has a body', { players: [p], actual: `${call.length} bytes` })
    await s.check(/no-store/.test(call.cache) && /private/.test(call.cache), 'the clip is not cacheable (private, no-store)', { players: [p], actual: call.cache })
    await s.check(call.path.endsWith(`/audio/${element.id}`), 'the clip is the one of the current element', { players: [p], expected: element.id, actual: call.path })
  }
  await p.click('listen-btn')
  const player = await until(async () => (await p.audioPlayers()).at(-1), { label: 'an <audio> made by the button "Прослушать"' })
  const decoded = await until(async () => {
    const last = (await p.audioPlayers()).at(-1)
    return last && last.readyState >= 1 && last.duration > 0 ? last : null
  }, { label: 'the clip to decode in <audio>', timeout: 10_000 }).catch(() => null)
  await s.check(decoded !== null && decoded.src.startsWith('blob:') && decoded.error === null, 'the clip plays from a blob and decodes', { players: [p], actual: JSON.stringify(decoded ?? player) })
  if (decoded) s.note(`clip: ${decoded.duration.toFixed(1)} s, events ${decoded.events.join(',')}`)
}

async function listenAgain(s, p) {
  const count = (await p.audioPlayers()).length
  await until(async () => (await p.$('listen-again-btn').isEnabled()), { label: 'the review clip to load' })
  await p.click('listen-again-btn')
  const decoded = await until(async () => {
    const all = await p.audioPlayers()
    const last = all.at(-1)
    return all.length > count && last.duration > 0 ? last : null
  }, { label: 'the clip of the review to decode', timeout: 10_000 }).catch(() => null)
  await s.check(decoded !== null, 'the clip can be heard again on the review', { players: [p] })
}

/** What the summary has to say about a list of answers. */
function summaryOf(results) {
  const verdicts = results.map((result) => result.verdict)
  return {
    total: results.length,
    answered: results.length,
    first: verdicts.filter((verdict) => verdict === 'correct').length,
    third: verdicts.filter((verdict) => verdict === 'skipped').length,
    typos: verdicts.filter((verdict) => verdict === 'typo').length,
    mistakes: results
      .filter((result) => result.verdict !== 'correct')
      .map((result) => ({ verdict: result.verdict, label: labelOf(result.element), answer: result.verdict === 'skipped' ? null : writtenOf(result) })),
  }
}

/** What a row of the mistakes shows as the learner's answer: a collocation shows the phrase with the pick in the blank. */
function writtenOf(result) {
  return result.element.kind === 'collocation' ? result.element.text.replace('___', result.given) : result.given
}

/** What a row of the mistakes calls the task. */
function labelOf(element) {
  if (element.kind === 'dictation') return element.display
  if (element.kind === 'sentence') return element.accepted_display?.[0] ?? element.accepted[0]
  if (element.kind === 'collocation') return element.full_text
  return ''
}

/** Plays a list of steps in one session and returns what the summary has to say. */
async function playSteps(env, s, p, session, steps) {
  const results = []
  for (const [index, step] of steps.entries()) {
    results.push(await answerOne(env, s, p, session, step, { last: index === steps.length - 1 }))
  }
  return results
}

// --------------------------------------------------------------------------------------- the modes

async function soloCollocation(env, s) {
  const p = await env.newPlayer(s, 'b')
  await openHub(env, s, p)
  await p.shot('hub')
  const session = await startSession(s, p, 'collocation')
  const mark = p.mark()
  const results = await playSteps(env, s, p, session, [
    { intent: 'right' },
    { intent: 'wrong', shot: 'collocation-wrong' },
    { intent: 'right', lose: 'reply' },
    { intent: 'right' },
    { intent: 'right' },
  ])
  const want = summaryOf(results)
  await checkSummary(env, s, p, session, want, { mark })
  await p.shot('summary')
  await repeatAndFinish(env, s, p, session, results)
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

async function soloSentence(env, s) {
  const p = await env.newPlayer(s, 'c')
  await openHub(env, s, p)
  const session = await startSession(s, p, 'sentence')
  const mark = p.mark()
  const results = []
  // Two answers, then the learner walks out with the third element on the screen.
  results.push(await answerOne(env, s, p, session, { intent: 'right', shot: 'sentence-right' }))
  results.push(await answerOne(env, s, p, session, { intent: 'wrong', shot: 'sentence-wrong' }))
  const before = (await bankFragments(p)).map((fragment) => fragment.id)
  await resumeFromHub(env, s, p, session, before, 2)
  results.push(await answerOne(env, s, p, session, { intent: 'skip' }))
  results.push(await answerOne(env, s, p, session, { intent: 'right', lose: 'request' }))
  results.push(await answerOne(env, s, p, session, { intent: 'right' }, { last: true }))

  const want = summaryOf(results)
  await checkSummary(env, s, p, session, want, { mark })
  await p.shot('summary')
  await repeatAndFinish(env, s, p, session, results)
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

/**
 * The learner leaves with an element on the screen: the hub offers "Continue" with the right place, a reopened hub still does, and the
 * session comes back at the same element, laid out the same way (the layout is in the snapshot).
 */
async function resumeFromHub(env, s, p, session, fragmentsBefore, done) {
  s.step('walk out to the hub, come back through "Продолжить"')
  await p.click('close-btn')
  await p.waitFor('practice-hub-view')
  await p.waitFor('continue-card')
  const title = await p.text('continue-title')
  const progress = await p.text('continue-progress')
  await s.check(title === MODE_TITLE[session.mode], 'the hub continues the abandoned mode', { players: [p], expected: MODE_TITLE[session.mode], actual: title })
  await s.check(progress === `Выполнено ${done} из ${session.progress.total}`, 'the hub shows how far the session went', { players: [p], expected: `Выполнено ${done} из ${session.progress.total}`, actual: progress })
  await p.shot('hub-continue')

  s.step('reload the hub (the Mini App opened again)')
  const mark = p.mark()
  await p.page.reload({ waitUntil: 'domcontentloaded' })
  await p.waitFor('continue-card')
  const hub = p.lastJson('/practice/hub', { since: mark })
  await s.check(hub?.active_session?.id === session.id && hub.active_session.progress.done === done, 'the reopened hub names the same unfinished session', { players: [p], since: mark, actual: JSON.stringify(hub?.active_session) })
  await p.click('continue-card')
  await p.waitFor(COMPONENT[session.mode])
  const progressBar = await p.text('progress')
  await s.check(progressBar === `${done} / ${session.progress.total}`, 'the session comes back where it stopped', { players: [p], expected: `${done} / ${session.progress.total}`, actual: progressBar })
  const after = (await bankFragments(p)).map((fragment) => fragment.id)
  await s.check(after.join() === fragmentsBefore.join(), 'the element comes back laid out the same way', { players: [p], expected: fragmentsBefore.join(), actual: after.join() })
  await s.check(env.oracle.session(session.id).state.current === done, 'the server is still on the same element', { players: [p] })
}

async function soloDictation(env, s) {
  const { oracle } = env
  const p = await env.newPlayer(s, 'd')
  await openHub(env, s, p)

  // A slip is a typo only in a word of five letters or more, and the phrases of a session are drawn at random: a session with
  // no such phrase is not the one to test the typo on, so the next one is opened.
  let session = null
  let typoAt = -1
  for (let attempt = 1; attempt <= 6 && typoAt < 0; attempt++) {
    if (attempt > 1) {
      await p.click('close-btn')
      await p.waitFor('mode-cards')
    }
    session = await startSession(s, p, 'dictation')
    typoAt = oracle.session(session.id).snapshot.elements.findIndex((element) => typoOf(element.expected[0]) !== null)
  }
  await s.check(typoAt >= 0, 'a session of the lesson has a phrase with a word of five letters or more (for the typo)', { players: [p] })
  const mark = p.mark()
  await checkClip(s, p, session, oracle.sessionElement(session.id))

  // The typo goes where the session has a phrase for it; the other four answers fill the rest in this order.
  const rest = [
    { intent: 'right', shot: 'dictation-right', listenAgain: true },
    { intent: 'wrong', lose: 'request', shot: 'dictation-wrong' },
    { intent: 'skip', shot: 'dictation-skip' },
    { intent: 'right' },
  ]
  const steps = session.progress.total > 0 ? Array.from({ length: session.progress.total }, (_, index) => (index === typoAt ? { intent: 'typo', shot: 'dictation-typo' } : rest.shift() ?? { intent: 'right' })) : []
  const results = await playSteps(env, s, p, session, steps)
  const want = summaryOf(results)
  await checkSummary(env, s, p, session, want, { mark })
  await p.shot('summary')
  await repeatAndFinish(env, s, p, session, results)
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

/** "Repeat mistakes", then every repeated task right: the summary of the repeat is clean, and "Ещё раз" takes the place of the repeat. */
async function repeatAndFinish(env, s, p, session, results) {
  const wrong = results.filter((result) => result.verdict !== 'correct')
  const retryMark = p.mark()
  const retry = await repeatMistakes(env, s, p, session, session.mode, { expectTotal: wrong.length, itemIds: wrong.map((result) => result.element.item_id) })
  const retryResults = await playSteps(env, s, p, retry, wrong.map(() => ({ intent: 'right' })))
  await checkSummary(env, s, p, retry, summaryOf(retryResults), { mark: retryMark })
  await s.check(await p.has('again-btn'), 'after a clean repeat the summary offers "Ещё раз"', { players: [p] })
  await p.shot('retry-summary')
}

// ---------------------------------------------------------------------------------------- pairs

/**
 * One round of pairs: a wrong match first (a word with a translation that is not its own), then every pair as it should be. The API's
 * words about each match and the end of the round on the screen are checked on the way. Returns the word that was matched wrongly.
 */
async function playRoundOfPairs(env, s, p, session, { slip = true, shot = false } = {}) {
  const element = env.oracle.sessionElement(session.id)
  const why = await whyNotShown(p, element)
  await s.check(why === null, `the board shows round ${element.round}, the one the server waits for`, { players: [p], actual: why })
  if (shot) await p.shot('pairs-task')

  const lefts = (await boardCards(p)).left
  const table = element.pairs
  let slipped = null

  if (slip) {
    const countBefore = await p.textNow('pairs-count')
    const wrongRight = Object.values(table).find((right) => right !== table[lefts[0].id])
    const attempt = await matchPair(p, lefts[0].id, wrongRight)
    slipped = lefts[0].text
    await s.check(attempt.json?.verdict === 'wrong' && attempt.json.pair?.left_id === lefts[0].id, 'the API calls a wrong match wrong', { players: [p], actual: JSON.stringify({ verdict: attempt.json?.verdict, pair: attempt.json?.pair }) })
    await s.check(attempt.json?.first_attempt === true, 'the wrong match is the first attempt of its word', { players: [p], actual: attempt.json?.first_attempt })
    await s.check(attempt.json?.round_done === false, 'a wrong match does not finish the round', { players: [p], actual: attempt.json?.round_done })
    await s.check((await p.textNow('pairs-count')) === countBefore, 'a wrong match leaves every pair still to be made', { players: [p], expected: countBefore, actual: await p.textNow('pairs-count') })
    if (shot) await p.shot('pairs-wrong')
  }

  for (const [index, card] of lefts.entries()) {
    const final = index === lefts.length - 1
    const matched = await matchPair(p, card.id, table[card.id], { last: final })
    await s.check(matched.json?.verdict === 'correct' && matched.json.pair?.right_id === table[card.id], `the API calls the pair "${card.text}" right`, { players: [p], actual: JSON.stringify({ verdict: matched.json?.verdict, pair: matched.json?.pair }) })
    const first = !(slip && index === 0)
    await s.check(matched.json?.first_attempt === first, `the attempt of "${card.text}" is ${first ? 'the first' : 'a later one'}`, { players: [p], actual: matched.json?.first_attempt })
    if (final) await s.check(matched.json?.round_done === true, 'the last pair finishes the round', { players: [p], actual: matched.json?.round_done })
  }

  await p.waitFor('answer-reveal')
  await s.check((await p.data('answer-reveal', 'verdict')) === 'round', 'the end of the round is shown', { players: [p] })
  await s.check((await p.textNow('reveal-text')) === `Раунд ${element.round} из ${element.rounds} пройден`, 'the round is told as done', { players: [p], actual: await p.textNow('reveal-text') })
  const mistakes = await p.textNow('round-mistakes')
  await s.check(mistakes === (slip ? 'Ошибок в раунде: 1' : 'Без ошибок'), 'the round counts its mistakes', { players: [p], expected: slip ? 'Ошибок в раунде: 1' : 'Без ошибок', actual: mistakes })
  await s.check((await p.page.locator('[data-testid="round-pair"]').count()) === lefts.length, 'the round lists all its pairs', { players: [p] })
  if (shot) await p.shot('pairs-reveal')
  await p.click('continue-btn')
  return { slipped, words: lefts.length }
}

async function soloPairs(env, s) {
  const { oracle } = env
  const p = await env.newPlayer(s, 'a')
  await openHub(env, s, p)
  const session = await startSession(s, p, 'pairs')
  const mark = p.mark()
  const mistakes = []
  let words = 0

  for (let round = 1; round <= session.progress.total; round++) {
    s.step(`round ${round} of ${session.progress.total}`)
    const played = await playRoundOfPairs(env, s, p, session, { shot: round === 1 })
    mistakes.push(played.slipped)
    words += played.words
    if (round < session.progress.total) await until(async () => (await p.has('pairs-board')) && !(await p.has('answer-reveal')), { label: `the board of round ${round + 1}` })
  }

  const want = {
    total: session.progress.total,
    answered: session.progress.total,
    first: words - mistakes.length,
    third: mistakes.length,
    pairs: true,
    mistakes: mistakes.map((word) => ({ verdict: 'wrong', label: word })),
  }
  await checkSummary(env, s, p, session, want, { mark })
  await p.shot('summary')

  const retryMark = p.mark()
  const retry = await repeatMistakes(env, s, p, session, 'pairs', { expectTotal: 1 })
  const element = oracle.sessionElement(retry.id)
  const retried = element.left.map((card) => card.text).sort()
  await s.check(mistakes.every((word) => retried.includes(word)), 'the repeat has the words that went wrong', { players: [p], expected: [...mistakes].sort().join(', '), actual: retried.join(', ') })
  await playRoundOfPairs(env, s, p, retry, { slip: false })
  await p.waitFor('practice-summary')
  const shown = await readSummary(p)
  await s.check(shown.perfect && shown.mistakes.length === 0, 'the repeat of the mistakes ends with no mistakes', { players: [p], since: retryMark, actual: JSON.stringify(shown) })
  await s.check(shown.again && !shown.retry, 'without mistakes the summary offers "Ещё раз"', { players: [p], since: retryMark, actual: JSON.stringify({ again: shown.again, retry: shown.retry }) })
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}
