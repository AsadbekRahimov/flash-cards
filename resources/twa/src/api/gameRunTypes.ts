import type {
  CollocationElement,
  DictationElement,
  PairsRoundElement,
  PracticeAnswerPayload,
  PracticeChoice,
  PracticeReveal,
  PracticeVerdict,
  SentenceElement,
} from './types'
import type { GameRunBoosters } from './gameShopTypes'

/**
 * A run of "Karvon" (docs/18 §12.8, docs/04 A.18 "Run"). The four kinds of the practice (pairs, collocation, sentence, dictation)
 * keep the shape of their practice elements (docs/16 §2.8), so the practice components draw them; the five kinds made of a word are new.
 */

export type GameRunKind = 'stop' | 'boss' | 'daily' | 'class_boss'
export type GameRunStatus = 'active' | 'finished'
/** `failed`: the flasks ran out; `abandoned`: the learner left; `expired`: the run was not finished in time. */
export type GameFinishReason = 'completed' | 'failed' | 'abandoned' | 'expired'
export type GameRunEvent = 'sandstorm' | 'oasis' | 'bazaar'

/** What a hint of the bazaar left on the element: options taken away (a choice), or the first letters (spell, gap). */
export type GameHint = { options_removed: string[] } | { prefix: string }

interface WithHint {
  hint?: GameHint
}

/** translate_fwd: an English word, choose the translation. `audio`: the word has a recording (no clip is served for this kind). */
export interface TranslateFwdElement extends WithHint {
  id: string
  kind: 'translate_fwd'
  prompt: string
  audio: boolean
  options: PracticeChoice[]
}

export interface TranslateBackElement extends WithHint {
  id: string
  kind: 'translate_back'
  prompt: string
  options: PracticeChoice[]
}

/** listen: the clip behind `audio_url` (fetched with the token), choose the word. */
export interface ListenElement extends WithHint {
  id: string
  kind: 'listen'
  audio: true
  audio_url: string
  options: PracticeChoice[]
}

export interface SpellElement extends WithHint {
  id: string
  kind: 'spell'
  prompt: string
  answer_length: number
}

export interface GapElement extends WithHint {
  id: string
  kind: 'gap'
  /** The example with the blank: `I ____ tea every morning.` */
  masked: string
  translation: string | null
}

export type GameChoiceElement = TranslateFwdElement | TranslateBackElement | ListenElement
export type GameWordElement = GameChoiceElement | SpellElement | GapElement

/** The elements of the practice kinds, as the run sends them (a collocation may carry the hint of the bazaar). */
export type GamePracticeElement = PairsRoundElement | (CollocationElement & WithHint) | SentenceElement | DictationElement

export type GameElement = GameWordElement | GamePracticeElement
export type GameElementKind = GameElement['kind']

/** What the learner sends for one element: the forms of the practice (`option_id`, `text`, `order`, a pair, `skip`). */
export type GameAnswerPayload = PracticeAnswerPayload

export interface GameCurrent {
  element: GameElement
  event: GameRunEvent | null
}

export interface GameMistake {
  element_id: string
  kind: GameElementKind
  label: string
  your_answer: string | null
  verdict: 'typo' | 'wrong' | 'skipped'
}

/**
 * `summary.boss` (docs/18 §12.8): the accuracy in whole per cent, won or not, the first win over the city's boss; `share` - the pair of the
 * card of the city to share and its heading - only on the first win (and when the bot has a link).
 */
export interface GameBossSummary {
  accuracy: number
  won: boolean
  first: boolean
  share?: { link: string; text: string; title: string } | null
}

/** `summary` of a finished run. `coins` / `league_points` are 0 until the rewards are paid; `boss` is null for a stop. */
export interface GameSummary {
  stars: number
  score: number
  coins: number
  league_points: number
  srs_pulled: number
  mistakes: GameMistake[]
  boss: GameBossSummary | null
  /** Not sent yet (the streak is G2.10): shown only when it is true. */
  streak_held?: boolean
  /** Not sent yet: the stars that were not there before this run. */
  new_stars?: number
}

export interface GameRunView {
  id: number
  kind: GameRunKind
  status: GameRunStatus
  finish_reason: GameFinishReason | null
  /** Where the run belongs, so that "Again" / "To the map" need no query (G2.7b); `daily` has no lesson. */
  course?: { slug: string } | null
  lesson_id?: number | null
  city: string | null
  stage_number: number | null
  lesson_number: number | null
  lesson_title: string | null
  scope_widened: boolean
  total: number
  /** The number of the element on screen (1-based, never above `total`). */
  position: number
  flasks: number
  flasks_max: number
  combo: number
  score: number
  /** null once the run is over. */
  current: GameCurrent | null
  /** The stock of boosters and what this run has used (G2.5b). */
  boosters: GameRunBoosters
  /** `available`: the bazaar is on the element and a hint can be bought. */
  hint: { price: number; available: boolean }
  summary: GameSummary | null
  server_now: string
}

/** The explanation of a word task: the right answer and the word with its translation. */
export interface GameWordReveal {
  correct_id?: string
  correct_text: string
  full_text?: string
  word?: string
  translation?: string | null
}

export type GameReveal = GameWordReveal | PracticeReveal

export interface GameAnswerRequest {
  /** uuid: the key of the request, the same one for a repeat of it. */
  request_id: string
  element_id: string
  time_spent_ms?: number | null
  answer: GameAnswerPayload
}

export interface GameAnswerResponse {
  verdict: PracticeVerdict
  points: number
  reveal: GameReveal | null
  flask_lost: boolean
  combo: number
  /** `oasis`: a flask came back with this answer. */
  event_applied: GameRunEvent | null
  /** Pairs: the match that was tried, and whether it completed the round. */
  pair?: { left_id: string; right_id: string } | null
  round_done?: boolean
  run: GameRunView
}

export interface GameHintRequest {
  request_id: string
  element_id: string
}

export interface GameHintResponse {
  hint: GameHint
  coins: { balance: number }
  run: GameRunView
}
