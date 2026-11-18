import type {
  CollocationElement,
  DictationElement,
  ErrorHuntElement,
  ErrorHuntReveal,
  PairsRoundElement,
  PracticeActiveSession,
  PracticeAnswerResponse,
  PracticeHubResponse,
  PracticeLimit,
  PracticeLimits,
  PracticeMode,
  PracticeModeInfo,
  PracticeSession,
  PracticeSummary,
  SentenceElement,
  WordFormElement,
  WordFormReveal,
} from '../types'

/** Answers of the practice API for the specs of the stores, the screens and the mode components (docs/16 §7). */

export const sentenceElement = (overrides: Partial<SentenceElement> = {}): SentenceElement => ({
  id: 'eSent00001',
  kind: 'sentence',
  prompt: 'Моя мама — учитель.',
  fragments: [
    { id: 'fQ1', text: 'teacher' },
    { id: 'fW2', text: 'My' },
    { id: 'fE3', text: 'a' },
    { id: 'fR4', text: 'is' },
    { id: 'fT5', text: 'mother' },
  ],
  ...overrides,
})

export const collocationElement = (overrides: Partial<CollocationElement> = {}): CollocationElement => ({
  id: 'eColl00001',
  kind: 'collocation',
  text: "Let's ___ a meeting on Monday.",
  options: [
    { id: 'oA1', text: 'make' },
    { id: 'oB2', text: 'have' },
    { id: 'oC3', text: 'do' },
  ],
  ...overrides,
})

export const dictationElement = (overrides: Partial<DictationElement> = {}): DictationElement => ({
  id: 'eDict00001',
  kind: 'dictation',
  source: 'phrase',
  audio_url: '/api/twa/practice/sessions/77/audio/eDict00001',
  words: 3,
  replacements_left: 2,
  ...overrides,
})

export const wordFormElement = (overrides: Partial<WordFormElement> = {}): WordFormElement => ({
  id: 'eWord00001',
  kind: 'word_form',
  text: 'We need to make a ___ by Friday.',
  base: 'decide',
  ...overrides,
})

/** The words of `I am very interested on modern art.`: `tOn5` is the wrong one. */
export const huntTokens = [
  { id: 'tI0', text: 'I' },
  { id: 'tAm1', text: 'am' },
  { id: 'tVery2', text: 'very' },
  { id: 'tInt3', text: 'interested' },
  { id: 'tOn4', text: 'on' },
  { id: 'tMod5', text: 'modern' },
  { id: 'tArt6', text: 'art.' },
]

export const huntOptions = [
  { id: 'oAt1', text: 'at' },
  { id: 'oIn2', text: 'in' },
  { id: 'oFor3', text: 'for' },
]

/** "Error hunt" at its first step (`locate`): only the words, no corrections. */
export const errorHuntElement = (overrides: Partial<ErrorHuntElement> = {}): ErrorHuntElement => ({
  id: 'eHunt00001',
  kind: 'error_hunt',
  step: 'locate',
  tokens: huntTokens,
  located: null,
  options: null,
  ...overrides,
})

/** The same task at its second step (`fix`), after the learner pressed `tokenId` (by default the wrong word `am`). */
export const errorHuntFixElement = (tokenId = 'tAm1', overrides: Partial<ErrorHuntElement> = {}): ErrorHuntElement =>
  errorHuntElement({
    step: 'fix',
    located: { token_id: tokenId, ok: tokenId === 'tOn4', error_token_id: 'tOn4' },
    options: huntOptions,
    ...overrides,
  })

export const wordFormReveal = (overrides: Partial<WordFormReveal> = {}): WordFormReveal => ({
  expected: 'decision',
  alternatives: [],
  your_text: 'decidion',
  same_as_base: false,
  full_text: 'We need to make a decision by Friday.',
  translation: 'Нам нужно принять решение до пятницы.',
  explanation: 'После make a нужно существительное: decide → decision.',
  ...overrides,
})

export const errorHuntReveal = (overrides: Partial<ErrorHuntReveal> = {}): ErrorHuntReveal => ({
  step: 'fix',
  located_ok: false,
  fixed_ok: true,
  your_token_id: 'tAm1',
  error_token_id: 'tOn4',
  your_option_id: 'oIn2',
  correct_option_ids: ['oIn2'],
  wrong_text: 'I am very interested on modern art.',
  full_text: 'I am very interested in modern art.',
  translation: 'Меня очень интересует современное искусство.',
  explanation: 'interested in — интересоваться чем-то.',
  ...overrides,
})

export const pairsElement = (overrides: Partial<PairsRoundElement> = {}): PairsRoundElement => ({
  id: 'ePair00001',
  kind: 'pairs_round',
  round: 1,
  rounds: 3,
  left: [
    { id: 'lM1', text: 'mother' },
    { id: 'lF2', text: 'father' },
    { id: 'lS3', text: 'sister' },
    { id: 'lB4', text: 'brother' },
  ],
  right: [
    { id: 'rT1', text: 'сестра' },
    { id: 'rU2', text: 'мама' },
    { id: 'rV3', text: 'брат' },
    { id: 'rW4', text: 'папа' },
  ],
  matched: [],
  mismatches: 0,
  ...overrides,
})

export const summary = (overrides: Partial<PracticeSummary> = {}): PracticeSummary => ({
  total: 5,
  answered: 5,
  correct_first: 3,
  typos: 1,
  hints: 1,
  mismatches: null,
  duration_ms: 93_000,
  finish_reason: 'completed',
  retry_available: true,
  mistakes: [
    { element_id: 'eDict00001', kind: 'dictation', label: 'See you tomorrow', your_answer: 'see you tomorow', verdict: 'typo' },
    { element_id: 'eSent00002', kind: 'sentence', label: 'My mother is a teacher.', your_answer: null, verdict: 'skipped' },
  ],
  ...overrides,
})

/** An active session of `mode` with `current` as its element (defaults to the element of the mode). */
export function session(overrides: Partial<PracticeSession> = {}): PracticeSession {
  const mode: PracticeMode = overrides.mode ?? 'sentence'
  const current = {
    pairs: pairsElement(),
    collocation: collocationElement(),
    sentence: sentenceElement(),
    dictation: dictationElement(),
    pairs_timed: pairsElement(),
    word_form: wordFormElement(),
    error_hunt: errorHuntElement(),
  }[mode]

  return {
    id: 77,
    status: 'active',
    mode,
    source: 'hub',
    retry_of: null,
    course: { slug: 'english-a1', title: 'Английский A1' },
    lesson: { id: 41, stage: 2, number: 4, title: 'Еда' },
    progress: { done: 0, total: 5 },
    counters: { correct_first: 0, hints: 0, mismatches: 0 },
    expires_at: '2026-10-31T10:00:00.000Z',
    server_now: '2026-10-30T10:00:05.321Z',
    current,
    summary: null,
    ...overrides,
  }
}

/** The same session after its last answer: finished, with the summary. */
export const finishedSession = (overrides: Partial<PracticeSession> = {}): PracticeSession =>
  session({
    status: 'finished',
    progress: { done: 5, total: 5 },
    counters: { correct_first: 3, hints: 1, mismatches: 0 },
    current: null,
    summary: summary(),
    ...overrides,
  })

export const answerResponse = (overrides: Partial<PracticeAnswerResponse> = {}): PracticeAnswerResponse => ({
  verdict: 'correct',
  first_attempt: true,
  reveal: {
    correct_text: 'My mother is a teacher.',
    alternatives: [],
    your_text: 'My mother is a teacher',
    explanation: 'Порядок слов: подлежащее, глагол, дополнение.',
  },
  pair: null,
  session: session({ progress: { done: 1, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) }),
  ...overrides,
})

const modeInfo = (mode: PracticeMode, overrides: Partial<PracticeModeInfo> = {}): PracticeModeInfo => ({
  mode,
  state: 'available',
  available: 14,
  required: 3,
  reason: null,
  estimate_seconds: 120,
  ...overrides,
})

/** The unfinished session as the server sends it: by id and by name (lesson 41 is the one of `hub()`). */
export const activeSession = (overrides: Partial<PracticeActiveSession> = {}): PracticeActiveSession => ({
  id: 77,
  mode: 'pairs',
  course: 'english-a1',
  course_title: 'Английский A1',
  lesson_id: 41,
  lesson: { stage: 2, number: 4, title: 'Еда' },
  progress: { done: 1, total: 3 },
  ...overrides,
})

/** The hub of a lesson with the four cards available; pass `modes` to change some of them. */
export function hub(
  overrides: Omit<Partial<PracticeHubResponse>, 'modes'> & { modes?: Partial<Record<PracticeMode, Partial<PracticeModeInfo>>> | PracticeModeInfo[] } = {},
): PracticeHubResponse {
  const { modes: modeOverrides, ...rest } = overrides
  const modes = Array.isArray(modeOverrides)
    ? modeOverrides
    : (['pairs', 'collocation', 'sentence', 'dictation'] as const).map((mode) => modeInfo(mode, modeOverrides?.[mode]))

  return {
    course: { slug: 'english-a1', title: 'Английский A1' },
    lesson: { id: 41, stage: 2, number: 4, title: 'Еда' },
    modes,
    active_session: null,
    battle: { enabled: true, active_battle_id: null, incoming_count: 0 },
    ...rest,
  }
}

/** A moment `hours` from the real now: a counter whose day has not ended whenever the spec runs. */
export const inHours = (hours: number): string => new Date(Date.now() + hours * 3_600_000).toISOString()

/** One counter of today: `used` of `total` spent (docs/18 §3: two sessions of practice and one battle a day on Free). */
export const counter = (used: number, total: number, resetsAt: string = inHours(3)): PracticeLimit => ({
  limit: total,
  used,
  remaining: Math.max(0, total - used),
  resets_at: resetsAt,
})

/** The Free limits of today as `/me`, the hub and the 201 answers send them. */
export const freeLimits = (sessionsUsed = 0, battlesUsed = 0, resetsAt: string = inHours(3)): PracticeLimits => ({
  sessions: counter(sessionsUsed, 2, resetsAt),
  battles: counter(battlesUsed, 1, resetsAt),
})

/** `details` of 403 `practice_limit_reached`. */
export const limitDetails = (
  kind: 'sessions' | 'battles' = 'sessions',
  resetsAt: string = inHours(3),
): Record<string, unknown> => ({ kind, limit: kind === 'sessions' ? 2 : 1, used: kind === 'sessions' ? 2 : 1, resets_at: resetsAt })

/**
 * A timed-pairs session (`pairs_timed`): `deadlineIn` ms after `server_now` (null: not started yet), with `score` pairs made.
 * `server_now` is the fixed instant of `session()`; the store reads the time left from the two of them.
 */
export function timedSession(deadlineIn: number | null = 60_000, score = 0, overrides: Partial<PracticeSession> = {}): PracticeSession {
  const base = session({ mode: 'pairs_timed', progress: { done: 0, total: 10 }, ...overrides })
  return {
    ...base,
    timed: {
      seconds: 60,
      deadline_at: deadlineIn === null ? null : new Date(Date.parse(base.server_now) + deadlineIn).toISOString(),
      started: deadlineIn !== null,
      score,
      penalty_ms: 2000,
    },
  }
}
