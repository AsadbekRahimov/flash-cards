// Scenarios 2-6 of docs/16 §14.4: the battle 1 on 1 with two browser contexts (two learners) on the real server - the invitation from
// "Друзья", the match to the end with a time-out and the same result on both screens, a reload in the middle, the background, a
// surrender (and a rematch), and "both left" (`both_absent`).
import { EXPECTED_STATUSES, clockSeconds, sel, sleep, until } from './kit.mjs'
import { COMPONENT, MODE_TITLE, doRound, normalize, whyNotShown } from './screens.mjs'

/** The verdict of a round and its points for what a player did (`null`: did not answer, the time ran out). */
const VERDICT = { right: 'correct', wrong: 'wrong', skip: 'skipped', typo: 'typo', null: 'timeout' }
const VERDICT_WORDS = { correct: 'Верно', typo: 'Опечатка', wrong: 'Ошибка', skipped: 'Пропуск', timeout: 'Время вышло' }
const POINTS = { correct: 100, typo: 0, wrong: 0, skipped: 0, timeout: 0 }
const OUTCOME_WORDS = { won: 'Победа', lost: 'Поражение', draw: 'Ничья', none: 'Матч не состоялся', aborted: 'Матч прерван' }
const MIRROR = { won: 'lost', lost: 'won', draw: 'draw', none: 'none' }

export function battleScenarios(env) {
  return [
    { name: 'battle:full', body: (s) => withCleanState(env, s, () => battleFull(env, s)) },
    { name: 'battle:reload', body: (s) => withCleanState(env, s, () => battleReload(env, s)) },
    { name: 'battle:background', body: (s) => withCleanState(env, s, () => battleBackground(env, s)) },
    { name: 'battle:surrender', body: (s) => withCleanState(env, s, () => battleSurrender(env, s)) },
    { name: 'battle:both-absent', body: (s) => withCleanState(env, s, () => battleBothAbsent(env, s)) },
  ]
}

// ------------------------------------------------------------------------------------ the state

/** A battle of an earlier run that is still on (a scenario that fell half-way) stands in the way of the next one: it is ended through the API. */
export async function clearBattles(env, whos = ['a', 'b']) {
  for (const who of whos) {
    const api = env.api(who)
    const list = await api.get('/practice/battles')
    const active = list.json?.active
    if (!active) continue
    const action = active.status === 'playing' || active.status === 'countdown' ? 'surrender' : active.role === 'challenger' || active.status === 'room' ? 'cancel' : 'decline'
    await api.post(`/practice/battles/${active.id}/${action}`, action === 'surrender' ? { confirm: true } : {})
  }
}

async function withCleanState(env, s, body) {
  await clearBattles(env)
  try {
    await body()
  } finally {
    await clearBattles(env).catch(() => undefined)
  }
}

// ----------------------------------------------------------------------------------- the room

/** A invites B from the screen "Друзья" (a button "Баттл" of the friend), chooses the lesson and the format, "Пригласить". */
async function inviteFromFriends(env, s, a, mode, { shot = false } = {}) {
  s.step(`${a.name}: invite a friend to a battle from "Друзья" (${MODE_TITLE[mode]})`)
  await a.open('/twa/friends')
  await a.waitFor('friends')
  await a.click('battle-btn')
  await a.waitFor('battle-setup-view')
  return chooseAndInvite(env, s, a, mode, { shot })
}

/** The setup of a battle: the lesson of the stand, the format, "Пригласить"; the room of the invitation sent comes up. Returns the battle. */
async function chooseAndInvite(env, s, a, mode, { shot = false } = {}) {
  await a.waitFor('formats')
  const mark = a.mark()
  // From the hub of a lesson the lesson is already chosen; from "Друзья" it is chosen here (and the formats are asked for again).
  if ((await a.page.locator(sel('lesson-select')).inputValue()) !== String(env.cfg.lesson)) {
    await a.page.selectOption(sel('lesson-select'), String(env.cfg.lesson))
    await a.waitCall(/\/practice\/battles\/options\?.*lesson_id=/, { since: mark })
    await a.waitFor('formats')
  }
  const known = a.lastJson(/\/practice\/battles\/options\?.*lesson_id=/)
  await s.check(known?.lesson?.id === env.cfg.lesson && known.modes?.every((entry) => entry.state === 'available'), 'every format of the lesson is playable for the pair', { players: [a], since: mark, actual: known?.modes?.map((entry) => `${entry.mode}:${entry.state}`).join(' ') })
  if (shot) await a.shot('setup')
  await a.click(`format-${mode}`)
  await until(async () => (await a.data(`format-${mode}`, 'state')) === 'selected', { label: 'the format to be chosen' })
  await until(() => a.$('invite-btn').isEnabled(), { label: 'the button "Пригласить"' })
  const sent = a.mark()
  await a.click('invite-btn')
  const created = await a.waitCall(/\/practice\/battles$/, { method: 'POST', since: sent })
  await s.check(created.status === 201, 'the invitation is created (201)', { players: [a], since: sent, actual: `${created.status} ${String(created.body).slice(0, 200)}` })
  const battle = JSON.parse(created.body)
  await a.waitFor('battle-room')
  await s.check((await a.data('battle-room', 'phase')) === 'invite_sent', 'the challenger sees the invitation that waits for an answer', { players: [a] })
  await s.check(battle.status === 'invited' && battle.mode === mode && battle.role === 'challenger', 'the invitation is of the chosen format, for a challenger', { players: [a], since: sent, actual: `${battle.status}/${battle.mode}/${battle.role}` })
  if (shot) await a.shot('invite-sent')
  s.note(`battle #${battle.id} (${mode}), invitation sent`)
  return battle
}

/** B opens "Друзья": the invitation is in the list, "Принять" leads into the room. */
async function acceptFromFriends(env, s, b, battle, { shot = false } = {}) {
  s.step(`${b.name}: accept from "Друзья"`)
  await b.open('/twa/friends')
  await b.waitFor('battle-invites')
  const meta = await b.text('battle-invite-meta')
  await s.check(meta.includes(MODE_TITLE[battle.mode]), 'the invitation names the format', { players: [b], expected: MODE_TITLE[battle.mode], actual: meta })
  await b.click('battle-accept-btn')
  await b.waitFor('battle-room')
  await s.check((await b.data('battle-room', 'phase')) === 'room', 'after "Принять" the opponent is in the room', { players: [b] })
  if (shot) await b.shot('room')
}

/** B follows the link of the DM of the bot (`/twa/battles/{id}`): the invitation that came, "Принять". */
export async function acceptFromLink(env, s, b, battle, { shot = false } = {}) {
  s.step(`${b.name}: open the link of the invitation and accept`)
  await b.open(`/twa/battles/${battle.id}`)
  await b.waitFor('battle-room')
  await s.check((await b.data('battle-room', 'phase')) === 'invite_received', 'the opponent sees the invitation that came', { players: [b] })
  if (shot) await b.shot('invite-received')
  await b.click('accept-btn')
  await until(async () => (await b.data('battle-room', 'phase')) === 'room', { label: `${b.name}: the room after "Принять"` })
}

/** Both are in the room and press "Готов" (a dictation waits for its five clips first); the countdown and the first round follow. */
export async function bothReady(env, s, a, b, battle, { shot = false, shoot = (p, label) => p.shot(label) } = {}) {
  s.step('both press "Готов"')
  await until(async () => (await a.data('battle-room', 'phase')) === 'room', { label: `${a.name}: the room (the poll brings the accepted invitation)`, timeout: 15_000 })
  for (const p of [a, b]) {
    await p.waitFor('ready-btn')
    if (battle.mode === 'dictation') {
      await until(async () => (await p.data('room-audio', 'state')) === 'ready', { label: `${p.name}: the five clips of the dictation`, timeout: 20_000 })
      const clips = p.calls(new RegExp(`/practice/battles/${battle.id}/audio/`), { method: 'GET' }).filter((call) => call.status === 200)
      await s.check(clips.length >= 5, 'the room loads the five clips of the dictation', { players: [p], actual: `${clips.length} clips` })
    }
  }
  if (shot) await shoot(a, 'room')
  const marks = [a.mark(), b.mark()]
  await Promise.all([a.click('ready-btn'), b.click('ready-btn')])
  await Promise.all([a, b].map((p, i) => p.waitCall(/\/ready$/, { method: 'POST', since: marks[i], status: 200 })))
  await Promise.all([a, b].map((p) => until(async () => ['countdown', 'playing'].includes(await p.data('battle-view', 'status')), { label: `${p.name}: the countdown`, timeout: 20_000 })))
  const state = await a.data('battle-view', 'status')
  if (state === 'countdown' && shot) await shoot(a, 'countdown')
  await Promise.all([a, b].map((p) => until(async () => (await p.data('battle-view', 'status')) === 'playing', { label: `${p.name}: the match begins`, timeout: 20_000 })))
}

// ------------------------------------------------------------------------------------ a round

export async function waitRound(p, round, battle, { timeout = 30_000 } = {}) {
  const headline = `Раунд ${round} из 5`
  await until(async () => {
    const view = await p.page.evaluate(() => ({
      status: document.querySelector('[data-testid="battle-view"]')?.dataset.status ?? null,
      headline: document.querySelector('[data-testid="headline"]')?.textContent.trim() ?? null,
      reveal: document.querySelector('[data-testid="battle-reveal"]') !== null,
    }))
    return view.status === 'playing' && view.headline === headline && !view.reveal
  }, { label: `${p.name}: round ${round} on the screen`, timeout })
  await p.waitFor(COMPONENT[battle.mode], { timeout })
}

/** What the screen of the reveal says, read in one go (the reveal stays only a few seconds). */
async function readReveal(p) {
  return p.page.evaluate(() => {
    const text = (root, id) => root.querySelector(`[data-testid="${id}"]`)?.textContent.replace(/\s+/g, ' ').trim() ?? null
    const side = (key) => {
      const root = document.querySelector(`[data-testid="side-${key}"]`)
      return root ? { verdict: root.dataset.verdict, points: text(root, 'side-points'), answer: text(root, 'side-answer'), name: text(root, 'side-name') } : null
    }
    const root = document.querySelector('[data-testid="battle-reveal"]')
    if (!root) return null
    return {
      me: side('me'),
      opponent: side('opponent'),
      correct: text(root, 'reveal-correct'),
      pairs: [...root.querySelectorAll('[data-testid="reveal-pairs"] li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim()),
      translation: text(root, 'reveal-translation'),
      explanation: text(root, 'reveal-explanation'),
      next: text(root, 'reveal-next-text'),
    }
  })
}

/** The right answer as the reveal writes it. */
function correctText(element) {
  if (element.kind === 'collocation') return element.full_text
  if (element.kind === 'sentence') return element.accepted_display?.[0] ?? element.accepted[0]
  if (element.kind === 'dictation') return element.display
  return null
}

/**
 * One round: both players answer the way `plan` says (`null`: nothing - the time runs out), the API's words about each answer, then the
 * reveal on both screens: the right answer, a verdict and the points of each, the same on both sides, mirrored.
 */
export async function playRound(env, s, battle, a, b, round, plan, { shots = false, waitingShot = false, shoot = (p, label) => p.shot(label) } = {}) {
  const { oracle } = env
  s.step(`round ${round}: ${a.name} ${plan.a ?? 'gives no answer'}, ${b.name} ${plan.b ?? 'gives no answer'}`)
  await Promise.all([waitRound(a, round, battle), waitRound(b, round, battle)])
  const element = oracle.battleElement(battle.id, round)
  for (const p of [a, b]) {
    const why = await whyNotShown(p, element)
    await s.check(why === null, `${p.name}: the screen shows round ${round}, the element of the server`, { players: [p], actual: why })
  }
  await s.check((await a.page.locator(sel('round-timer')).count()) > 0, 'the round has a timer', { players: [a] })
  if (shots) await shoot(a, `round-${round}`)

  const marks = [a.mark(), b.mark()]
  if (waitingShot && plan.a !== null && plan.b !== null) {
    // A answers first and waits: the picture of the card "Ответ принят" - then B answers.
    await doRound(a, element, plan.a)
    await a.waitFor('battle-waiting')
    await shoot(a, 'waiting')
    await doRound(b, element, plan.b)
  } else {
    const doing = []
    if (plan.a !== null) doing.push(doRound(a, element, plan.a))
    if (plan.b !== null) doing.push(doRound(b, element, plan.b))
    await Promise.all(doing)
  }

  // What the API said to each answer.
  for (const [p, intent, mark] of [[a, plan.a, marks[0]], [b, plan.b, marks[1]]]) {
    if (intent === null) continue
    const sent = await p.waitCall(new RegExp(`/practice/battles/${battle.id}/answer$`), { method: 'POST', since: mark })
    const reply = sent.body ? JSON.parse(sent.body) : null
    const expectation = expectedOf(element, intent)
    await s.check(sent.status === 200, `${p.name}: the answer is accepted (200)`, { players: [p], since: mark, actual: `${sent.status} ${String(sent.body).slice(0, 160)}` })
    await s.check(reply?.verdict === expectation.verdict && reply?.points === expectation.points, `${p.name}: the API gives ${expectation.verdict} and ${expectation.points} points for the ${intent} answer`, { players: [p], since: mark, expected: `${expectation.verdict}/${expectation.points}`, actual: `${reply?.verdict}/${reply?.points}` })
  }
  if (plan.a !== null && plan.b === null) {
    await a.waitFor('battle-waiting')
    await s.check((await a.text('battle-waiting')).includes('Ждём соперника'), `${a.name}: after the answer the screen waits for the opponent`, { players: [a] })
    if (shots) await shoot(a, 'waiting')
  }

  // The round is resolved: at once when both answered, after the deadline and its grace when one did not.
  const seconds = battle.round_seconds ?? 45
  const timeout = plan.a !== null && plan.b !== null ? 20_000 : (seconds + 25) * 1000
  const started = Date.now()
  const unanswered = plan.a === null ? a : plan.b === null ? b : null
  if (unanswered) {
    // The clock of the round runs on the screen of the one who waits (the deadline of the server, not the clock of the device).
    await unanswered.waitFor('timer-text')
    const first = clockSeconds(await unanswered.text('timer-text'))
    await sleep(3_000)
    const second = clockSeconds(await unanswered.text('timer-text'))
    await s.check(first !== null && second !== null && first - second >= 2 && first - second <= 4, 'the timer of the round counts down by the clock of the server', { players: [unanswered], expected: '~3 s less', actual: `${first} -> ${second}` })
    await s.check(first !== null && first <= seconds && first >= seconds - 8, 'the timer starts from the limit of the round', { players: [unanswered], expected: `<= ${seconds}`, actual: first })
    await s.check(!(await unanswered.has('absent-strip')) && !(await a.has('absent-strip')) && !(await b.has('absent-strip')), 'nobody is told that the opponent is gone while both are here', { players: [a, b] })
  }
  const reveals = await Promise.all([a, b].map((p) => p.waitFor('battle-reveal', { timeout }).then(() => readReveal(p))))
  const took = ((Date.now() - started) / 1000).toFixed(1)
  if (unanswered) s.note(`the round was resolved ${took} s after the answers (deadline ${seconds} s + grace)`)
  if (unanswered) await s.check(Date.now() - started >= (seconds - 12) * 1000, 'a round without an answer is resolved only after its time', { players: [a, b], actual: `${took} s` })

  const wantA = expectedOf(element, plan.a)
  const wantB = expectedOf(element, plan.b)
  const [ra, rb] = reveals
  for (const [p, shown, mine, theirs] of [[a, ra, wantA, wantB], [b, rb, wantB, wantA]]) {
    await s.check(shown !== null, `${p.name}: the reveal of round ${round} is on the screen`, { players: [p] })
    if (shown === null) continue
    await s.check(shown.me?.verdict === mine.verdict && shown.me?.points === `+${mine.points}`, `${p.name}: "you" - ${mine.verdict}, +${mine.points}`, { players: [p], expected: `${mine.verdict} +${mine.points}`, actual: `${shown.me?.verdict} ${shown.me?.points}` })
    await s.check(shown.opponent?.verdict === theirs.verdict && shown.opponent?.points === `+${theirs.points}`, `${p.name}: the opponent - ${theirs.verdict}, +${theirs.points}`, { players: [p], expected: `${theirs.verdict} +${theirs.points}`, actual: `${shown.opponent?.verdict} ${shown.opponent?.points}` })
    if (correctText(element)) await s.check(shown.correct !== null && normalize(shown.correct) === normalize(correctText(element)), `${p.name}: the reveal shows the right answer`, { players: [p], expected: correctText(element), actual: shown.correct })
    if (element.kind === 'pairs_round') await s.check(shown.pairs.length === element.left.length, `${p.name}: the reveal lists the pairs`, { players: [p], actual: shown.pairs.join(' | ') })
    for (const [side, intent, who] of [[shown.me, p === a ? plan.a : plan.b, 'you'], [shown.opponent, p === a ? plan.b : plan.a, 'the opponent']]) {
      const words = answerWords(element, intent)
      if (words !== null) await s.check(side?.answer === words, `${p.name}: the reveal says what ${who} gave (${words})`, { players: [p], expected: words, actual: side?.answer })
    }
  }
  if (shots) {
    await shoot(a, `reveal-${round}`)
    await shoot(b, `reveal-${round}`)
  }
  return { a: wantA, b: wantB, element }
}

/** What the reveal says an answer was, where that is the same every time: no answer, or how many pairs were right. */
function answerWords(element, intent) {
  if (intent === null || intent === 'skip') return 'Ответа нет'
  if (element.kind === 'pairs_round') return `Верных пар: ${intent === 'right' ? element.left.length : element.left.length - 2} из ${element.left.length}`
  return null
}

/** What the server owes for an answer of this kind (a collocation / a sentence / a dictation: 100 or nothing; pairs: 25 for each right pair). */
export function expectedOf(element, intent) {
  if (element.kind === 'pairs_round' && intent === 'wrong') {
    const right = element.left.length - 2
    return { verdict: 'wrong', points: right * 25 }
  }
  const verdict = VERDICT[String(intent)]
  return { verdict, points: POINTS[verdict] }
}

// ---------------------------------------------------------------------------------- the result

/** Reads the result screen of a finished battle in one go. */
export async function readResult(p) {
  await p.waitFor('battle-result', { timeout: 25_000 })
  return p.page.evaluate(() => {
    const text = (id) => document.querySelector(`[data-testid="${id}"]`)?.textContent.replace(/\s+/g, ' ').trim() ?? null
    const root = document.querySelector('[data-testid="battle-result"]')
    return {
      outcome: root.dataset.outcome,
      reason: root.dataset.reason,
      title: text('result-title'),
      reasonText: text('result-reason'),
      topic: text('result-topic'),
      me: text('score-me'),
      opponent: text('score-opponent'),
      rows: [...document.querySelectorAll('[data-testid="round-row"]')].map((row) => ({
        round: Number(row.dataset.round),
        me: { verdict: row.querySelector('[data-testid="round-me"]')?.dataset.verdict, points: row.querySelector('[data-testid="round-me"]')?.textContent.replace(/\D+/g, ' ').trim() },
        opponent: { verdict: row.querySelector('[data-testid="round-opponent"]')?.dataset.verdict, points: row.querySelector('[data-testid="round-opponent"]')?.textContent.replace(/\D+/g, ' ').trim() },
      })),
      buttons: ['rematch-btn', 'friends-btn', 'course-btn', 'accept-rematch-btn', 'open-rematch-btn'].filter((id) => document.querySelector(`[data-testid="${id}"]`)),
    }
  })
}

/**
 * The end of a match on both screens: the same outcome mirrored (a win and a defeat, or two draws), the same score and the same rounds
 * with the sides turned round; then the API (two learners ask for the same battle) and the database say the same.
 */
export async function checkResults(env, s, battle, a, b, rounds, expectation) {
  s.step('the result is the same on both screens, in the API and in the database')
  const [ra, rb] = await Promise.all([readResult(a), readResult(b)])
  const sumA = rounds.reduce((sum, round) => sum + round.a.points, 0)
  const sumB = rounds.reduce((sum, round) => sum + round.b.points, 0)
  const outcomeA = expectation?.outcome ?? (sumA > sumB ? 'won' : sumA < sumB ? 'lost' : 'draw')
  const reason = expectation?.reason ?? 'completed'

  for (const [p, shown, outcome, mine, theirs] of [[a, ra, outcomeA, sumA, sumB], [b, rb, MIRROR[outcomeA], sumB, sumA]]) {
    await s.check(shown.outcome === outcome && shown.title === OUTCOME_WORDS[outcome], `${p.name}: the result is "${OUTCOME_WORDS[outcome]}"`, { players: [p], expected: outcome, actual: `${shown.outcome} "${shown.title}"` })
    await s.check(shown.reason === reason, `${p.name}: the result gives the reason ${reason}`, { players: [p], expected: reason, actual: shown.reason })
    await s.check(shown.me === String(mine) && shown.opponent === String(theirs), `${p.name}: the score is ${mine} : ${theirs}`, { players: [p], expected: `${mine} : ${theirs}`, actual: `${shown.me} : ${shown.opponent}` })
    await s.check(shown.rows.length === rounds.length, `${p.name}: the result lists the ${rounds.length} rounds played`, { players: [p], actual: shown.rows.length })
    for (const [index, round] of rounds.entries()) {
      const row = shown.rows[index]
      if (!row) continue
      const want = p === a ? [round.a, round.b] : [round.b, round.a]
      await s.check(row.me.verdict === want[0].verdict && row.me.points === String(want[0].points) && row.opponent.verdict === want[1].verdict && row.opponent.points === String(want[1].points), `${p.name}: round ${index + 1} in the list - you ${want[0].verdict} ${want[0].points}, opponent ${want[1].verdict} ${want[1].points}`, { players: [p], actual: JSON.stringify(row) })
    }
  }
  await s.check(ra.rows.every((row, index) => row.me.verdict === rb.rows[index]?.opponent.verdict && row.opponent.verdict === rb.rows[index]?.me.verdict && row.me.points === rb.rows[index]?.opponent.points), 'the two screens list the same rounds with the sides turned round', { players: [a, b], actual: `${JSON.stringify(ra.rows)} vs ${JSON.stringify(rb.rows)}` })
  await s.check(ra.topic === rb.topic && ra.topic !== '', 'both screens name the same format and topic', { players: [a, b], actual: `${ra.topic} / ${rb.topic}` })
  await s.check(ra.buttons.includes('rematch-btn') && rb.buttons.includes('rematch-btn'), 'both screens offer the rematch', { players: [a, b], actual: `${ra.buttons} / ${rb.buttons}` })

  // The API: two learners ask for the same battle.
  const [apiA, apiB] = await Promise.all([env.api(a.who).get(`/practice/battles/${battle.id}`), env.api(b.who).get(`/practice/battles/${battle.id}`)])
  await s.check(apiA.status === 200 && apiB.status === 200, 'both learners can read the finished battle', { players: [a, b], actual: `${apiA.status} / ${apiB.status}` })
  const ja = apiA.json
  const jb = apiB.json
  await s.check(ja?.status === 'finished' && jb?.status === 'finished' && ja.end_reason === reason && jb.end_reason === reason, `the API says finished / ${reason} to both`, { players: [a, b], actual: `${ja?.status}/${ja?.end_reason} and ${jb?.status}/${jb?.end_reason}` })
  await s.check(ja?.scores?.me === jb?.scores?.opponent && ja?.scores?.opponent === jb?.scores?.me && ja?.scores?.me === sumA, 'the API gives the mirrored score to the two learners', { players: [a, b], actual: `${JSON.stringify(ja?.scores)} vs ${JSON.stringify(jb?.scores)}` })
  await s.check(ja?.result?.outcome === outcomeA && jb?.result?.outcome === MIRROR[outcomeA], 'the API gives the mirrored outcome to the two learners', { players: [a, b], actual: `${ja?.result?.outcome} / ${jb?.result?.outcome}` })
  const mirrored = (ja?.result?.rounds ?? []).every((row, index) => row.me.verdict === jb.result.rounds[index]?.opponent.verdict && row.me.points === jb.result.rounds[index]?.opponent.points && row.opponent.verdict === jb.result.rounds[index]?.me.verdict)
  await s.check(mirrored && (ja?.result?.rounds ?? []).length === rounds.length, 'the API lists the same rounds with the sides turned round', { players: [a, b] })

  const row = env.oracle.battle(battle.id)
  const scores = row.participants.map((participant) => participant.score).sort((x, y) => x - y)
  await s.check(row.status === 'finished' && row.end_reason === reason && scores.join() === [sumA, sumB].sort((x, y) => x - y).join(), 'the database holds the same match', { players: [a, b], actual: `${row.status}/${row.end_reason} scores ${scores}` })
  await s.check(row.participants.every((participant) => participant.active_learner_id === null), 'no player is held by the finished match', { players: [a, b], actual: JSON.stringify(row.participants.map((participant) => participant.active_learner_id)) })
  const winner = outcomeA === 'draw' ? null : row.participants.find((participant) => participant.role === (outcomeA === 'won' ? ja?.role : jb?.role))
  if (outcomeA !== 'draw') await s.check(row.winner_learner_id === winner?.learner_id, 'the winner of the match is the one the screens call so', { players: [a, b], actual: row.winner_learner_id })
  else await s.check(row.winner_learner_id === null, 'a draw has no winner in the database', { players: [a, b], actual: row.winner_learner_id })
  return { ra, rb }
}

// ----------------------------------------------------------------------------------- the match

/** Plays rounds `from..5` as the plans say, then returns what was played. */
async function playMatch(env, s, battle, a, b, plans, opts = {}) {
  const rounds = []
  for (const [index, plan] of plans.entries()) rounds.push(await playRound(env, s, battle, a, b, index + 1, plan, { shots: opts.shotRounds?.includes(index + 1) }))
  return rounds
}

async function twoPlayers(env, s, opts = {}) {
  const a = await env.newPlayer(s, 'a', opts)
  const b = await env.newPlayer(s, 'b', opts)
  return { a, b }
}

// ======================================================================================== scenarios

/** 2: the match from the invitation in "Друзья" to the result; one player does not answer in one round (a time-out). */
async function battleFull(env, s) {
  const { a, b } = await twoPlayers(env, s)
  const battle = await inviteFromFriends(env, s, a, 'collocation', { shot: true })
  await acceptFromFriends(env, s, b, battle, { shot: true })
  await bothReady(env, s, a, b, battle, { shot: true })

  const rounds = await playMatch(env, s, battle, a, b, [
    { a: 'right', b: 'right' },
    { a: 'right', b: 'wrong' },
    { a: 'right', b: null },
    { a: 'wrong', b: 'right' },
    { a: 'right', b: 'right' },
  ], { shotRounds: [1] })
  await checkResults(env, s, battle, a, b, rounds)
  await a.shot('result')
  await b.shot('result')
  for (const p of [a, b]) await s.check((await p.overflow()) <= 0, `${p.name}: the result has no sideways scroll`, { players: [p] })
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

/** 3: a reload in the middle of a match (before answering, and after answering): the match goes on from the same round. */
async function battleReload(env, s) {
  const { a, b } = await twoPlayers(env, s)
  // The entry from the practice hub of the lesson: "Играть с другом" -> the friend -> the setup of the battle.
  s.step(`${a.name}: invite from the hub of the lesson ("Играть с другом")`)
  await a.open(`/twa/practice/${env.cfg.course}?lesson=${env.cfg.lesson}`)
  await a.waitFor('battle-play-btn')
  await a.click('battle-play-btn')
  await a.waitFor('friend-list')
  await a.page.locator(`${sel('friend-list')} button`).first().click()
  await a.waitFor('battle-setup-view')
  const battle = await chooseAndInvite(env, s, a, 'dictation')
  await acceptFromLink(env, s, b, battle)
  await bothReady(env, s, a, b, battle, { shot: true })

  // Round 1: A answers, B reloads the page before answering.
  s.step('round 1: B reloads the page before answering')
  await Promise.all([waitRound(a, 1, battle), waitRound(b, 1, battle)])
  const first = env.oracle.battleElement(battle.id, 1)
  await doRound(a, first, 'right')
  await a.waitFor('battle-waiting')
  const beforeScore = [await b.text('score-me'), await b.text('score-opponent')]
  const reloadMark = b.mark()
  await b.page.reload({ waitUntil: 'domcontentloaded' })
  await waitRound(b, 1, battle)
  await s.check((await b.text('headline')) === 'Раунд 1 из 5', 'after the reload the match is on the same round', { players: [b], since: reloadMark, actual: await b.text('headline') })
  await s.check(beforeScore.join() === [await b.text('score-me'), await b.text('score-opponent')].join(), 'after the reload the score is the same', { players: [b], since: reloadMark })
  await s.check(!(await b.has('battle-waiting')), 'the reloaded player is still to answer', { players: [b] })
  await until(async () => (await b.data('audio-state', 'state')) === 'ready', { label: 'the clip of the round after the reload', timeout: 15_000 })
  const clip = b.calls(new RegExp(`/practice/battles/${battle.id}/audio/`), { method: 'GET', since: reloadMark }).find((call) => call.status === 200)
  await s.check(clip !== undefined && clip.type.startsWith('audio/mpeg'), 'the reloaded page fetches the clip of the round again', { players: [b], since: reloadMark })
  await b.shot('after-reload')
  await doRound(b, first, 'right')
  await Promise.all([a, b].map((p) => p.waitFor('battle-reveal', { timeout: 20_000 })))

  // Round 2: B answers, then reloads (the answer is the server's already); A answers last.
  s.step('round 2: B answers and reloads the page')
  await Promise.all([waitRound(a, 2, battle), waitRound(b, 2, battle)])
  const second = env.oracle.battleElement(battle.id, 2)
  await doRound(b, second, 'right')
  await b.waitFor('battle-waiting')
  await b.page.reload({ waitUntil: 'domcontentloaded' })
  await b.waitFor('battle-waiting')
  await s.check((await b.text('headline')) === 'Раунд 2 из 5' && (await b.text('battle-waiting')).includes('Ждём соперника'), 'after the reload an answer already given is not asked for again', { players: [b], actual: await b.text('headline') })
  await doRound(a, second, 'right')
  await Promise.all([a, b].map((p) => p.waitFor('battle-reveal', { timeout: 20_000 })))

  const rounds = [
    { a: expectedOf(first, 'right'), b: expectedOf(first, 'right'), element: first },
    { a: expectedOf(second, 'right'), b: expectedOf(second, 'right'), element: second },
  ]
  for (let round = 3; round <= 5; round++) rounds.push(await playRound(env, s, battle, a, b, round, { a: 'right', b: 'right' }))
  await checkResults(env, s, battle, a, b, rounds)
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

/** Hides the tab of a player (what `visibilitychange` does when Telegram is minimised) or shows it again. */
async function setHidden(p, hidden) {
  await p.page.evaluate((value) => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (value ? 'hidden' : 'visible') })
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => value })
    document.dispatchEvent(new Event('visibilitychange'))
  }, hidden)
}

/** 4: one player goes to the background for ~13 s and comes back: the match is not lost, the other one is told, then told no more. */
async function battleBackground(env, s) {
  const { a, b } = await twoPlayers(env, s)
  const battle = await inviteFromFriends(env, s, a, 'sentence')
  await acceptFromLink(env, s, b, battle)
  await bothReady(env, s, a, b, battle)
  s.step('round 1: A goes to the background, B answers')
  await Promise.all([waitRound(a, 1, battle), waitRound(b, 1, battle)])
  const element = env.oracle.battleElement(battle.id, 1)

  const polls = a.calls(/\/poll$/).length
  await setHidden(a, true)
  const hiddenAt = Date.now()
  await doRound(b, element, 'right')
  await b.waitFor('battle-waiting')

  // B is told that A is gone once A has been silent for presence_warn_seconds (10).
  await b.waitFor('absent-strip', { timeout: 25_000 })
  const told = ((Date.now() - hiddenAt) / 1000).toFixed(1)
  s.note(`B was told that A was gone ${told} s after A went to the background`)
  await s.check((await b.text('absent-text')).includes('Соперник потерял связь'), 'B is told that the opponent lost the connection', { players: [b], actual: await b.text('absent-text') })
  await b.shot('absent-strip')
  const pollsWhileHidden = a.calls(/\/poll$/).length - polls
  await s.check(pollsWhileHidden <= 1, 'a page in the background does not poll', { players: [a], actual: `${pollsWhileHidden} polls while hidden` })

  // ~13 s in the background in all, then back: the page asks at once.
  await until(() => Date.now() - hiddenAt >= 13_000, { label: 'the time in the background', timeout: 20_000, interval: 250 })
  const back = a.mark()
  const shownAt = Date.now()
  await setHidden(a, false)
  const first = await a.waitCall(/\/poll$/, { since: back, timeout: 1_500 }).catch(() => null)
  await s.check(first !== null, 'the page polls at once when it is shown again (not at the next beat of two seconds)', { players: [a], since: back, actual: first === null ? 'no poll within 1.5 s' : `${first.at - shownAt} ms` })
  await until(async () => !(await b.has('absent-strip')), { label: 'the strip "Соперник потерял связь" to go once A is back', timeout: 15_000 })
  await s.check((await a.data('battle-view', 'status')) === 'playing' && (await a.text('headline')) === 'Раунд 1 из 5', 'the match is not lost: the same round on the screen of the one who was away', { players: [a], actual: `${await a.data('battle-view', 'status')} ${await a.text('headline')}` })
  await s.check(!(await a.has('absent-strip')), 'the one who came back is told nothing about being away', { players: [a] })
  const row = env.oracle.battle(battle.id)
  await s.check(row.status === 'playing' && row.current_round === 1, 'the database still has the match on round 1', { players: [a, b], actual: `${row.status} round ${row.current_round}` })

  s.step('round 1: A answers late, the match goes on to the end')
  await doRound(a, element, 'right')
  await Promise.all([a, b].map((p) => p.waitFor('battle-reveal', { timeout: 20_000 })))
  const rounds = [{ a: expectedOf(element, 'right'), b: expectedOf(element, 'right'), element }]
  for (let round = 2; round <= 5; round++) rounds.push(await playRound(env, s, battle, a, b, round, { a: 'right', b: round === 3 ? 'skip' : 'right' }))
  await checkResults(env, s, battle, a, b, rounds)
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

/**
 * 5: a surrender in the middle of a match - after "Остаться" - by the one who leads: the opponent wins, the score stays as it was, and
 * the rounds played are listed; then the rematch offered to the one who surrendered.
 */
async function battleSurrender(env, s) {
  const { a, b } = await twoPlayers(env, s)
  const battle = await inviteFromFriends(env, s, a, 'pairs')
  await acceptFromLink(env, s, b, battle)
  await bothReady(env, s, a, b, battle)
  // Round 1 is played: A makes all four pairs, B two of four (the points of pairs are 25 for each right pair).
  const first = await playRound(env, s, battle, a, b, 1, { a: 'right', b: 'wrong' }, { shots: true })
  await Promise.all([waitRound(a, 2, battle), waitRound(b, 2, battle)])
  await a.shot('pairs-round-2')

  s.step('A (leads 100 : 50): ✕ -> "Остаться" -> ✕ -> "Сдаться"')
  await a.click('close-btn')
  await a.waitFor('leave-confirm')
  await s.check((await a.text('leave-title')) === 'Сдаться и выйти?', 'the question of a leaving player in a match is a surrender', { players: [a], actual: await a.text('leave-title') })
  await a.shot('leave-confirm')
  await a.click('leave-stay')
  await until(async () => !(await a.has('leave-confirm')), { label: 'the question to go after "Остаться"' })
  await s.check((await a.data('battle-view', 'status')) === 'playing', 'after "Остаться" the match goes on', { players: [a] })
  await a.click('close-btn')
  await a.waitFor('leave-confirm')
  const mark = a.mark()
  await a.click('leave-yes')
  const sent = await a.waitCall(new RegExp(`/practice/battles/${battle.id}/surrender$`), { method: 'POST', since: mark })
  await s.check(sent.status === 200 && JSON.parse(sent.req ?? '{}').confirm === true, 'the surrender is sent with the confirmation', { players: [a], since: mark, actual: `${sent.status} ${sent.req}` })

  // The one who leads and surrenders loses ("points as they are", the winner is the opponent); only the resolved round is listed.
  const { ra, rb } = await checkResults(env, s, battle, a, b, [first], { outcome: 'lost', reason: 'surrender' })
  await s.check(ra.reasonText === 'Вы сдались: победа засчитана сопернику.', 'the one who surrendered is told why', { players: [a], actual: ra.reasonText })
  await s.check(rb.reasonText === 'Соперник сдался — победа ваша.', 'the opponent is told why', { players: [b], actual: rb.reasonText })
  await a.shot('result-lost')
  await b.shot('result-won')

  // The rematch: the winner asks, the one who surrendered gets the offer on the result screen and accepts.
  s.step('rematch: B offers, A accepts')
  const rematchMark = b.mark()
  await b.click('rematch-btn')
  const created = await b.waitCall(/\/practice\/battles$/, { method: 'POST', since: rematchMark })
  await s.check(created.status === 201 && JSON.parse(created.req ?? '{}').rematch_of === battle.id, 'the rematch is an invitation that points at the match', { players: [b], since: rematchMark, actual: `${created.status} ${created.req}` })
  const rematch = JSON.parse(created.body)
  await b.waitFor('battle-room')
  await a.waitFor('rematch-offer', { timeout: 20_000 })
  await s.check(/предлагает реванш/.test(await a.text('rematch-offer')), 'the one who surrendered is offered the rematch', { players: [a], actual: await a.text('rematch-offer') })
  await a.shot('rematch-offer')
  await a.click('accept-rematch-btn')
  await until(async () => (await a.data('battle-room', 'phase')) === 'room', { label: 'the room of the rematch', timeout: 15_000 })
  const next = env.oracle.battle(rematch.id)
  await s.check(next.rematch_of_id === battle.id && next.status === 'room' && next.mode === 'pairs', 'the database: the rematch is a room of the same format that follows the match', { players: [a, b], actual: `${next.status}/${next.mode} of ${next.rematch_of_id}` })
  // The room is left: A cancels it (the stand is left clean).
  await a.click('leave-btn')
  await a.waitFor('leave-confirm')
  await a.click('leave-yes')
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}

/**
 * 6: both players close the app; after the grace the janitor of the server (`practice:battles-sweep`, every minute in production) closes the
 * match as `both_absent`, and both see it so when they open it. (Without the janitor the first one to come back is "present" and wins
 * by the absence of the other: `finished/absent`; the stand has no scheduler, so the scenario runs the janitor itself.)
 */
async function battleBothAbsent(env, s) {
  const { a, b } = await twoPlayers(env, s)
  const battle = await inviteFromFriends(env, s, a, 'sentence')
  await acceptFromLink(env, s, b, battle)
  await bothReady(env, s, a, b, battle)
  await Promise.all([waitRound(a, 1, battle), waitRound(b, 1, battle)])
  const row = env.oracle.battle(battle.id)
  await s.check(row.status === 'playing', 'the match is on', { players: [a, b], actual: row.status })

  s.step('both close the app')
  await a.close()
  await b.close()
  const closedAt = Date.now()
  const seen = env.oracle.battle(battle.id).participants.map((participant) => Date.parse(`${participant.last_seen_at.replace(' ', 'T')}Z`))
  const lastPresence = Math.max(...seen)
  s.note(`the server waits ${env.cfg.grace} s (presence_grace_seconds) after the last presence of the two`)
  await until(() => Date.now() - lastPresence > (env.cfg.grace + 2) * 1000, { label: `the grace of ${env.cfg.grace} s after the last presence`, timeout: (env.cfg.grace + 15) * 1000, interval: 500 })
  s.note(`both were away for ${((Date.now() - closedAt) / 1000).toFixed(0)} s`)
  await s.check(env.oracle.battle(battle.id).status === 'playing', 'nothing touches the match while nobody is there', { actual: env.oracle.battle(battle.id).status })

  s.step('the janitor runs (practice:battles-sweep)')
  const swept = env.sweep()
  await s.check(swept.ok && /Battles swept: [1-9]/.test(swept.output), 'the janitor finds the match to move on', { actual: swept.output })
  const closed = env.oracle.battle(battle.id)
  await s.check(closed.status === 'closed' && closed.end_reason === 'both_absent' && closed.winner_learner_id === null, 'the database: closed, both_absent, no winner', { actual: `${closed.status}/${closed.end_reason} winner ${closed.winner_learner_id}` })
  await s.check(closed.participants.every((participant) => participant.active_learner_id === null), 'nobody is held by the closed match', { actual: JSON.stringify(closed.participants.map((participant) => participant.active_learner_id)) })

  s.step('both open the match again')
  const a2 = await env.newPlayer(s, 'a', { tag: 'battle-both-absent-again' })
  await a2.open(`/twa/battles/${battle.id}`)
  const ra = await readResult(a2)
  const b2 = await env.newPlayer(s, 'b', { tag: 'battle-both-absent-again' })
  await b2.open(`/twa/battles/${battle.id}`)
  const rb = await readResult(b2)
  for (const [p, shown] of [[a2, ra], [b2, rb]]) {
    await s.check(shown.outcome === 'none' && shown.reason === 'both_absent', `${p.name}: the match is closed, both absent`, { players: [p], actual: `${shown.outcome}/${shown.reason}` })
    await s.check(shown.title === 'Матч прерван' && shown.reasonText === 'Оба игрока пропали — матч закрыт без победителя.', `${p.name}: the screen says the match was interrupted and why`, { players: [p], actual: `"${shown.title}" "${shown.reasonText}"` })
    await s.check(shown.rows.length === 0 && !shown.buttons.includes('accept-rematch-btn'), `${p.name}: nothing to list, no rematch to answer`, { players: [p], actual: JSON.stringify(shown.buttons) })
  }
  await a2.shot('result-both-absent')
  await b2.shot('result-both-absent')
  const apis = await Promise.all([env.api('a').get('/practice/battles'), env.api('b').get('/practice/battles')])
  await s.check(apis.every((api) => api.json?.active === null), 'neither player has an active battle after it', { players: [a2, b2], actual: JSON.stringify(apis.map((api) => api.json?.active)) })
  const same = await Promise.all([env.api('a').get(`/practice/battles/${battle.id}`), env.api('b').get(`/practice/battles/${battle.id}`)])
  await s.check(same.every((api) => api.json?.status === 'closed' && api.json?.end_reason === 'both_absent' && api.json?.result?.outcome === 'none'), 'the API says closed / both_absent / no result to both', { players: [a2, b2], actual: JSON.stringify(same.map((api) => [api.json?.status, api.json?.end_reason, api.json?.result?.outcome])) })
  await s.noPageErrors()
  await s.noUnexpectedStatuses(EXPECTED_STATUSES)
}
