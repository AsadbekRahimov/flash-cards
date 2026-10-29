import type {
  CollocationElement,
  DictationElement,
  PairsRoundElement,
  PracticeActiveSession,
  PracticeAnswerResponse,
  PracticeHubResponse,
  PracticeMode,
  PracticeModeInfo,
  PracticeSession,
  PracticeSummary,
  SentenceElement,
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

export const activeSession = (overrides: Partial<PracticeActiveSession> = {}): PracticeActiveSession => ({
  id: 77,
  mode: 'pairs',
  course: 'english-a1',
  lesson_id: 41,
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
