// Scenario 7 of docs/16 §14.4: the screens of a session (each mode: the task and the review) and of a battle (the room, a round, the
// review, the result) at 390 and 320 px, in the light and in the dark theme of Telegram. The pictures are for the eyes (see sheet.mjs);
// on the way the script checks what a machine can: nothing sticks out of the window sideways, and the texts that a box does not show
// whole are listed for a look.
//
// Four runs (width x theme), two at a time: each run is its own pair of friends (Alice and Bob, Charlie and Diana), so the limits of
// opening a session (10 a minute for a learner) are not the speed of the scenario.
import { EXPECTED_STATUSES, until } from './kit.mjs'
import { COMPONENT, MODES, boardCards, doCollocation, doDictation, doSentence, matchPair, typoOf } from './screens.mjs'
import { acceptFromLink, bothReady, checkResults, clearBattles, playRound } from './battle.mjs'

const CONFIGS = [
  { width: 390, theme: 'light', mode: 'pairs', who: ['a', 'b'] },
  { width: 390, theme: 'dark', mode: 'sentence', who: ['c', 'd'] },
  { width: 320, theme: 'light', mode: 'sentence', who: ['a', 'b'] },
  { width: 320, theme: 'dark', mode: 'pairs', who: ['c', 'd'] },
]
/** Who takes the pictures of the solo screens: the fifth learner, one after another (the battles go on beside her, by pairs). */
const SOLO = 'e'

export function shotScenarios(env) {
  return [{ name: 'shots', body: (s) => shots(env, s) }]
}

async function shots(env, s) {
  const solo = async () => {
    for (const config of CONFIGS) await track(env, s, config, 'solo', async (scene, p) => soloScreens(env, scene, p, config))
  }
  // The battles: two pairs at a time, the second two when the first two are done.
  const battles = async () => {
    for (const batch of [CONFIGS.slice(0, 2), CONFIGS.slice(2)]) {
      await Promise.all(
        batch.map((config) =>
          track(env, s, config, 'battle', async (scene, challenger, opponent) => {
            await battleScreens(env, scene, challenger, opponent, config)
          }),
        ),
      )
    }
  }
  await Promise.all([solo(), battles()])
}

/** One run (a width, a theme): its own scene, its players, its checks; what it finds counts for the scenario. */
async function track(env, parent, config, kind, body) {
  const s = parent.fork(`${kind}-${config.width}-${config.theme}`)
  const tag = `shots-${kind}`
  const options = { theme: config.theme, width: config.width, tag }
  const who = kind === 'solo' ? [SOLO] : config.who
  try {
    await clearBattles(env, who)
    const players = []
    for (const [index, key] of who.entries()) players.push(await env.newPlayer(s, key, { ...options, role: kind === 'battle' ? (index === 0 ? 'challenger' : 'opponent') : null }))
    await body(s, ...players)
  } catch (error) {
    await s.crash(error)
  } finally {
    await clearBattles(env, who).catch(() => undefined)
    await s.noPageErrors()
    await s.noUnexpectedStatuses(EXPECTED_STATUSES)
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

// -------------------------------------------------------------------------------------- the solo

async function openSession(env, who, mode) {
  const created = await env.api(who).post('/practice/sessions', { mode, course: env.cfg.course, lesson_id: env.cfg.lesson, source: 'hub', replace_active: true })
  if (created.status !== 201) throw new Error(`the session of ${mode} was not opened: ${created.status} ${created.text.slice(0, 200)}`)
  return created.json
}

async function soloScreens(env, s, p, config) {
  const who = p.who
  s.step(`${p.name}: the screens of a session (${config.width} px, ${config.theme})`)
  await p.open(`/twa/practice/${env.cfg.course}?lesson=${env.cfg.lesson}`)
  await p.waitFor('mode-cards')
  await s.check(await p.cutOffSees(), `the check "nothing sticks out of the window" sees a box that does stick out (${p.width} px)`, { players: [p] })
  await look(s, p, 'hub')

  for (const mode of MODES) {
    const session = await openSession(env, who, mode)
    await p.go(`/practice/sessions/${session.id}`)
    await p.waitFor(COMPONENT[mode])
    await look(s, p, `${mode}-task`)
    await BY_MODE[mode](env, s, p, session)
  }
}

/** Answer an element and show its review. */
async function review(env, s, p, session, intent, label, { last = false } = {}) {
  const element = env.oracle.sessionElement(session.id)
  if (element.kind === 'collocation') await doCollocation(p, element, intent)
  else if (element.kind === 'sentence') await doSentence(p, element, intent)
  else if (element.kind === 'dictation') await doDictation(p, element, intent)
  await p.waitFor('answer-reveal')
  if (label) await look(s, p, label)
  await p.click('continue-btn')
  if (!last) await until(async () => !(await p.has('answer-reveal')), { label: 'the next element' })
}

const BY_MODE = {
  /** A wrong match (the red cards), the end of the round. */
  async pairs(env, s, p, session) {
    const element = env.oracle.sessionElement(session.id)
    const lefts = (await boardCards(p)).left
    const wrongRight = Object.values(element.pairs).find((right) => right !== element.pairs[lefts[0].id])
    await matchPair(p, lefts[0].id, wrongRight)
    await look(s, p, 'pairs-wrong')
    for (const [index, card] of lefts.entries()) await matchPair(p, card.id, element.pairs[card.id], { last: index === lefts.length - 1 })
    await p.waitFor('answer-reveal')
    await look(s, p, 'pairs-reveal')
  },

  /** A right answer, a wrong one, the three after them, the summary (with the mistake in the list). */
  async collocation(env, s, p, session) {
    await review(env, s, p, session, 'right', 'collocation-correct')
    await review(env, s, p, session, 'wrong', 'collocation-wrong')
    for (let i = 0; i < 3; i++) await review(env, s, p, session, 'right', null, { last: i === 2 })
    await p.waitFor('practice-summary')
    await look(s, p, 'summary')
  },

  /** A wrong line, "Не знаю". */
  async sentence(env, s, p, session) {
    await review(env, s, p, session, 'wrong', 'sentence-wrong')
    await review(env, s, p, session, 'skip', 'sentence-hint')
  },

  /** A slip in a word (the typo, with the word-by-word list), then "Не знаю"; the elements before the first phrase with a long word are answered right. */
  async dictation(env, s, p, session) {
    const elements = env.oracle.session(session.id).snapshot.elements
    const typoAt = elements.findIndex((element) => typoOf(element.expected[0]) !== null)
    for (let index = 0; index < Math.max(typoAt, 0); index++) await review(env, s, p, session, 'right', null)
    if (typoAt >= 0) await review(env, s, p, session, 'typo', 'dictation-typo')
    if (typoAt < elements.length - 1) await review(env, s, p, session, 'skip', 'dictation-hint')
  },
}

// ------------------------------------------------------------------------------------- the battle

async function battleScreens(env, s, a, b, config) {
  const [whoA, whoB] = config.who
  s.step(`${a.name} and ${b.name}: the screens of a battle (${config.mode}, ${config.width} px, ${config.theme})`)
  const friends = await env.api(whoA).get('/friends')
  const friend = friends.json?.friends?.find((item) => item.first_name === env.people[whoB].first_name)
  if (!friend) {
    await s.finding(`${a.name} has no friend ${b.name} on the stand: the pictures of the battle need the pair (befriend.php ${env.people[whoA].id} ${env.people[whoB].id})`, { players: [] })
    return
  }
  const created = await env.api(whoA).post('/practice/battles', { friendship_id: friend.id, mode: config.mode, course: env.cfg.course, lesson_id: env.cfg.lesson })
  if (created.status !== 201) throw new Error(`the invitation was refused: ${created.status} ${created.text.slice(0, 200)}`)
  const battle = created.json

  await a.open(`/twa/battles/${battle.id}`)
  await a.waitFor('battle-room')
  await look(s, a, 'invite-sent')
  await acceptFromLink(env, s, b, battle)
  await look(s, b, 'room')
  // The room of the challenger is taken before the two press "Готов"; the countdown is three seconds, the picture is of whoever is on it.
  await bothReady(env, s, a, b, battle, { shot: true, shoot: (p, label) => look(s, p, label) })

  // Round 1: the question, the card "Ответ принят" of the one who answered first, the review; the other rounds only to the end.
  const rounds = []
  rounds.push(await playRound(env, s, battle, a, b, 1, { a: 'right', b: 'wrong' }, { shots: true, waitingShot: true, shoot: (p, label) => look(s, p, `${config.mode}-${label}`) }))
  for (let round = 2; round <= 5; round++) rounds.push(await playRound(env, s, battle, a, b, round, { a: round === 4 ? 'wrong' : 'right', b: 'right' }))
  await checkResults(env, s, battle, a, b, rounds)
  await look(s, a, 'result')
  await look(s, b, 'result')
}
