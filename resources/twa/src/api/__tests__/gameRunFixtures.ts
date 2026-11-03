import type {
  GameAnswerResponse,
  GameElement,
  GameRunView,
  GameSummary,
  GapElement,
  ListenElement,
  SpellElement,
  TranslateBackElement,
  TranslateFwdElement,
} from '../gameRunTypes'
import type { CollocationElement, DictationElement, PairsRoundElement, SentenceElement } from '../types'

/** The shapes of docs/04 A.18 "Run" (and `GameContractTest`), for the tests of the run's store, components and screen. */

export const translateFwd = (overrides: Partial<TranslateFwdElement> = {}): TranslateFwdElement => ({
  id: 'e1',
  kind: 'translate_fwd',
  prompt: 'apple',
  audio: true,
  options: [
    { id: 'o1', text: 'яблоко' },
    { id: 'o2', text: 'груша' },
    { id: 'o3', text: 'слива' },
    { id: 'o4', text: 'вишня' },
  ],
  ...overrides,
})

export const translateBack = (overrides: Partial<TranslateBackElement> = {}): TranslateBackElement => ({
  id: 'e2',
  kind: 'translate_back',
  prompt: 'хлеб',
  options: [
    { id: 'o1', text: 'bread' },
    { id: 'o2', text: 'butter' },
    { id: 'o3', text: 'cheese' },
    { id: 'o4', text: 'milk' },
  ],
  ...overrides,
})

export const listen = (overrides: Partial<ListenElement> = {}): ListenElement => ({
  id: 'e3',
  kind: 'listen',
  audio: true,
  audio_url: '/api/twa/game/runs/9/audio/e3',
  options: [
    { id: 'o1', text: 'water' },
    { id: 'o2', text: 'winter' },
    { id: 'o3', text: 'waiter' },
    { id: 'o4', text: 'weather' },
  ],
  ...overrides,
})

export const spell = (overrides: Partial<SpellElement> = {}): SpellElement => ({ id: 'e4', kind: 'spell', prompt: 'яблоко', answer_length: 5, ...overrides })

export const gap = (overrides: Partial<GapElement> = {}): GapElement => ({
  id: 'e5',
  kind: 'gap',
  masked: 'I ____ tea every morning.',
  translation: 'пить',
  ...overrides,
})

export const pairs = (overrides: Partial<PairsRoundElement> = {}): PairsRoundElement => ({
  id: 'e6',
  kind: 'pairs_round',
  round: 1,
  rounds: 1,
  left: [
    { id: 'l1', text: 'cat' },
    { id: 'l2', text: 'dog' },
  ],
  right: [
    { id: 'r1', text: 'собака' },
    { id: 'r2', text: 'кошка' },
  ],
  matched: [],
  mismatches: 0,
  ...overrides,
})

export const collocation = (overrides: Partial<CollocationElement> = {}): CollocationElement => ({
  id: 'e7',
  kind: 'collocation',
  text: "Let's ___ a meeting.",
  options: [
    { id: 'c1', text: 'have' },
    { id: 'c2', text: 'do' },
    { id: 'c3', text: 'go' },
  ],
  ...overrides,
})

export const sentence = (overrides: Partial<SentenceElement> = {}): SentenceElement => ({
  id: 'e8',
  kind: 'sentence',
  prompt: 'Я пью чай.',
  fragments: [
    { id: 'f1', text: 'I' },
    { id: 'f2', text: 'drink' },
    { id: 'f3', text: 'tea' },
  ],
  ...overrides,
})

export const dictation = (overrides: Partial<DictationElement> = {}): DictationElement => ({
  id: 'e9',
  kind: 'dictation',
  source: 'phrase',
  audio_url: '/api/twa/game/runs/9/audio/e9',
  words: 3,
  replacements_left: 0,
  ...overrides,
})

export function summary(overrides: Partial<GameSummary> = {}): GameSummary {
  return { stars: 2, score: 320, coins: 0, league_points: 0, srs_pulled: 0, mistakes: [], boss: null, ...overrides }
}

export function runView(overrides: Partial<GameRunView> = {}, element: GameElement | null = translateFwd(), event: 'sandstorm' | 'oasis' | 'bazaar' | null = null): GameRunView {
  return {
    id: 9,
    kind: 'stop',
    status: 'active',
    finish_reason: null,
    city: 'Самарканд',
    stage_number: 1,
    lesson_number: 2,
    lesson_title: 'Фрукты',
    scope_widened: false,
    total: 10,
    position: 3,
    flasks: 3,
    flasks_max: 3,
    combo: 0,
    score: 40,
    current: element === null ? null : { element, event },
    boosters: { available: [], used: [] },
    hint: { price: 5, available: false },
    summary: null,
    server_now: '2026-11-05T10:00:00.000Z',
    ...overrides,
  }
}

export function finishedRun(reason: 'completed' | 'failed' | 'abandoned' | 'expired', overrides: Partial<GameRunView> = {}, withSummary: Partial<GameSummary> = {}): GameRunView {
  return runView({ status: 'finished', finish_reason: reason, current: null, position: 10, summary: summary(withSummary), ...overrides }, null)
}

export function answerResponse(overrides: Partial<GameAnswerResponse> = {}): GameAnswerResponse {
  return {
    verdict: 'correct',
    points: 10,
    reveal: { correct_id: 'o1', correct_text: 'яблоко', word: 'apple', translation: 'яблоко' },
    flask_lost: false,
    combo: 1,
    event_applied: null,
    run: runView({ position: 4 }, translateBack()),
    ...overrides,
  }
}
