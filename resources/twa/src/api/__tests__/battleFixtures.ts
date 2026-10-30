import type {
  Battle,
  Course,
  CourseDetail,
  BattleEndReason,
  BattleIncoming,
  BattleListResponse,
  BattleModeOption,
  BattleOptionsResponse,
  BattlePoll,
  BattleReveal,
  BattleRound,
  BattleRoundResult,
  BattleRoundState,
  BattleStatus,
  PracticeMode,
} from '../types'
import { sentenceElement } from './practiceFixtures'

/** Answers of the battle API for the specs of the store, the screens and the components (docs/16 §7.3, §7.4). */

/** The server clock of every fixture. Deadlines are written as seconds from it (`at(45)`). */
export const SERVER_NOW = '2026-10-30T10:00:05.000Z'

export const at = (seconds: number): string => new Date(Date.parse(SERVER_NOW) + seconds * 1000).toISOString()

export const COURSE = { slug: 'english-a1', title: 'Английский A1' }
export const LESSON = { id: 41, stage: 2, number: 4, title: 'Еда' }

export const roundState = (overrides: Partial<BattleRoundState> = {}): BattleRoundState => ({
  no: 2,
  phase: 'answering',
  deadline_at: at(40),
  phase_ends_at: null,
  my_answered: false,
  opponent_answered: false,
  ...overrides,
})

/**
 * A battle in `playing`, answering the second round of a sentence battle; pass the fields that differ. Every key the server sends
 * is here, with the values of `BattleContractTest` (the grace of a missing player 30 s, the warning after 10 s, the grace of a
 * deadline 1.5 s).
 */
export function battle(overrides: Partial<Battle> = {}): Battle {
  return {
    id: 15,
    status: 'playing',
    end_reason: null,
    mode: 'sentence',
    role: 'challenger',
    course: COURSE,
    lesson: LESSON,
    opponent: { first_name: 'Dilnoza' },
    rounds_total: 5,
    round_seconds: 45,
    countdown_seconds: 3,
    reveal_seconds: 4,
    presence_grace_seconds: 30,
    presence_warn_seconds: 10,
    deadline_grace_ms: 1500,
    invite_expires_at: at(240),
    room_expires_at: null,
    starts_at: null,
    version: 23,
    server_now: SERVER_NOW,
    ready: { me: true, opponent: true },
    opponent_present: true,
    opponent_absent_since: null,
    round: roundState(),
    scores: { me: 100, opponent: 125 },
    result: null,
    rematch: null,
    friendship_id: 11,
    audio_preload: null,
    ...overrides,
  }
}

/** The `audio_preload` of a dictation battle: the five clips, `/api/twa/practice/battles/{id}/audio/{element}`. */
export const clipUrls = (id = 15): string[] => [1, 2, 3, 4, 5].map((n) => `/api/twa/practice/battles/${id}/audio/eD${n}`)

export const invitedBattle = (overrides: Partial<Battle> = {}): Battle =>
  battle({ status: 'invited', version: 1, ready: { me: false, opponent: false }, round: null, scores: { me: 0, opponent: 0 }, ...overrides })

export const roomBattle = (overrides: Partial<Battle> = {}): Battle =>
  invitedBattle({ status: 'room', version: 2, room_expires_at: at(105), ...overrides })

export const countdownBattle = (overrides: Partial<Battle> = {}): Battle =>
  battle({ status: 'countdown', version: 4, ready: { me: true, opponent: true }, starts_at: at(3), round: roundState({ no: 1, phase: null, deadline_at: at(48) }), scores: { me: 0, opponent: 0 }, ...overrides })

const roundResults = (rows: [BattleRoundResult['me'], BattleRoundResult['opponent']][]): BattleRoundResult[] =>
  rows.map(([me, opponent], index) => ({ no: index + 1, me, opponent }))

export const FIVE_ROUNDS: BattleRoundResult[] = roundResults([
  [{ verdict: 'correct', points: 100 }, { verdict: 'wrong', points: 0 }],
  [{ verdict: 'correct', points: 100 }, { verdict: 'correct', points: 100 }],
  [{ verdict: 'typo', points: 0 }, { verdict: 'correct', points: 100 }],
  [{ verdict: 'skipped', points: 0 }, { verdict: 'timeout', points: 0 }],
  [{ verdict: 'correct', points: 100 }, { verdict: 'wrong', points: 0 }],
])

/** A battle that is over with a result of its own. */
export function finishedBattle(outcome: 'won' | 'lost' | 'draw' = 'won', reason: BattleEndReason = 'completed', overrides: Partial<Battle> = {}): Battle {
  const scores = { won: { me: 300, opponent: 200 }, lost: { me: 200, opponent: 300 }, draw: { me: 200, opponent: 200 } }[outcome]
  return battle({
    status: 'finished',
    end_reason: reason,
    version: 40,
    round: null,
    scores,
    result: { outcome, end_reason: reason, rounds: FIVE_ROUNDS },
    ...overrides,
  })
}

/** A battle that ended without a result (the server gives it a result of its own, `outcome: none`, and the reason). */
export const closedBattle = (reason: BattleEndReason = 'canceled', overrides: Partial<Battle> = {}): Battle =>
  battle({
    status: 'closed',
    end_reason: reason,
    version: 9,
    round: null,
    scores: { me: 0, opponent: 0 },
    result: { outcome: 'none', end_reason: reason, rounds: [] },
    ...overrides,
  })

export function poll(overrides: Partial<BattlePoll> = {}): BattlePoll {
  return {
    id: 15,
    status: 'playing',
    version: 23,
    server_now: SERVER_NOW,
    ready: { me: true, opponent: true },
    opponent_present: true,
    opponent_absent_since: null,
    round: roundState(),
    scores: { me: 100, opponent: 125 },
    end_reason: null,
    rematch: null,
    ...overrides,
  }
}

/** The poll that goes with a battle. */
export const pollOf = (b: Battle, overrides: Partial<BattlePoll> = {}): BattlePoll =>
  poll({
    id: b.id,
    status: b.status,
    version: b.version,
    server_now: b.server_now,
    ready: b.ready,
    opponent_present: b.opponent_present,
    opponent_absent_since: b.opponent_absent_since,
    round: b.round,
    scores: b.scores,
    end_reason: b.result?.end_reason ?? null,
    rematch: b.rematch,
    ...overrides,
  })

/** The second round, open for answers: a sentence to build. */
export const answeringRound = (overrides: Partial<BattleRound> = {}): BattleRound => ({
  no: 2,
  phase: 'answering',
  deadline_at: at(40),
  phase_ends_at: null,
  server_now: SERVER_NOW,
  element: sentenceElement({ id: 'eSent00002' }),
  reveal: null,
  ...overrides,
})

export const sentenceReveal = (overrides: Partial<BattleReveal> = {}): BattleReveal => ({
  correct: { correct_text: 'My mother is a teacher.' },
  me: { answer: { order: ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1'] }, verdict: 'correct', points: 100 },
  opponent: { answer: { order: ['fT5', 'fW2', 'fR4', 'fQ1', 'fE3'] }, verdict: 'wrong', points: 0 },
  explanation: 'Порядок слов: подлежащее, глагол, дополнение.',
  translation: 'Моя мама — учитель.',
  ...overrides,
})

/** The second round after both have answered: the same task and what the server shows of it. */
export const revealRound = (overrides: Partial<BattleRound> = {}): BattleRound =>
  answeringRound({ phase: 'reveal', deadline_at: at(-1), phase_ends_at: at(3), reveal: sentenceReveal(), ...overrides })

const option = (mode: PracticeMode, overrides: Partial<BattleModeOption> = {}): BattleModeOption => ({
  mode,
  state: 'available',
  available: 26,
  required: 20,
  reason: null,
  round_seconds: { pairs: 45, collocation: 30, sentence: 45, dictation: 60 }[mode],
  ...overrides,
})

/** `GET /practice/battles/options` with the four formats playable; pass `modes` (by mode) to change some of them. */
export function options(
  overrides: Omit<Partial<BattleOptionsResponse>, 'modes'> & { modes?: Partial<Record<PracticeMode, Partial<BattleModeOption>>> } = {},
): BattleOptionsResponse {
  const { modes, ...rest } = overrides
  return {
    friend: { first_name: 'Dilnoza' },
    course: COURSE,
    lesson: LESSON,
    modes: (['pairs', 'collocation', 'sentence', 'dictation'] as const).map((mode) => option(mode, modes?.[mode])),
    friend_access: 'available',
    ...rest,
  }
}

export const incoming = (overrides: Partial<BattleIncoming> = {}): BattleIncoming => ({
  id: 15,
  mode: 'pairs',
  from: { first_name: 'Bek' },
  course: COURSE,
  lesson: LESSON,
  invite_expires_at: at(240),
  rematch: false,
  ...overrides,
})

export const battleList = (overrides: Partial<BattleListResponse> = {}): BattleListResponse => ({
  active: null,
  incoming: [],
  finished: [],
  ...overrides,
})

export const activeOf = (status: BattleStatus = 'room', overrides: Partial<NonNullable<BattleListResponse['active']>> = {}): NonNullable<BattleListResponse['active']> => ({
  id: 9,
  status,
  mode: 'sentence',
  role: 'challenger',
  course: COURSE,
  lesson: null,
  opponent: { first_name: 'Dilnoza' },
  ...overrides,
})

const PROGRESS = { done: 0, total: 20 }

/** A row of `GET /courses`. */
export const courseRow = (overrides: Partial<Course> = {}): Course => ({
  slug: 'english-a1',
  title: 'Английский A1',
  description: null,
  level: 'A1',
  exam_tag: null,
  words_count: 900,
  is_featured: false,
  is_active: false,
  progress: { done: 0, total: 900 },
  ...overrides,
})

/** `GET /courses/{slug}`: two modules, three lessons (one without a title, one in a module without a name). */
export const courseDetail = (overrides: Partial<CourseDetail> = {}): CourseDetail => ({
  slug: 'english-a1',
  title: 'Английский A1',
  description: null,
  level: 'A1',
  exam_tag: null,
  words_count: 900,
  is_active: true,
  progress: { done: 0, total: 900 },
  stages: [
    {
      number: 1,
      title: 'Семья',
      progress: PROGRESS,
      lessons: [
        { id: 31, number: 3, title: null, progress: PROGRESS },
        { id: 32, number: 4, title: 'Дом', progress: PROGRESS },
      ],
    },
    { number: 2, title: null, progress: PROGRESS, lessons: [{ id: 41, number: 4, title: 'Еда', progress: PROGRESS }] },
  ],
  ...overrides,
})
