export interface Learner {
  id: number
  first_name: string | null
  locale: string
  onboarding_completed: boolean
}

export interface AuthContext {
  type: 'training' | 'exam'
  sessionId: number
}

export interface AuthResponse {
  token: string
  expires_in: number
  learner: Learner
}

export interface MeResponse {
  learner: Learner & { username: string | null }
  groups: { id: number; title: string }[]
  stats: {
    words_learned: number
    words_due_today: number
  }
  entitlement: Entitlement
  paywall: Paywall
  settings: LearnerSettings
}

export interface SettingsChoice {
  id: string
  /** Localized by the server (the time zone with its UTC offset). */
  label: string
}

/** `settings` of GET /me and of the answer to PATCH /me/settings (docs/04 A.2). */
export interface LearnerSettings {
  interface_locale: string
  /** IANA id. */
  timezone: string
  /** `HH:MM` local time; null = reminders are off. */
  reminder_time: string | null
  daily_goal: number
  goal: string | null
  level: string | null
  /** ISO 8601 UTC: the time zone may be changed again from then on (once in 24 h); null = now. */
  timezone_change_available_at: string | null
  active_course: { slug: string; title: string } | null
  options: {
    locales: SettingsChoice[]
    timezones: SettingsChoice[]
    reminder_times: string[]
    daily_goals: number[]
  }
}

/** Body of PATCH /me/settings: only the fields to change; `reminder_time: null` turns the reminders off. */
export type SettingsPatch = Partial<
  Pick<LearnerSettings, 'interface_locale' | 'timezone' | 'reminder_time' | 'daily_goal' | 'goal' | 'level'>
>

/** `source`: 'free' | 'subscription' | 'sponsor:organization:<id>'; `until`: ISO 8601, null = no end. */
export interface Entitlement {
  plan: 'free' | 'pro'
  source: string
  until: string | null
  /** null = unlimited */
  new_words_per_day: number | null
}

/** `contact` is null when the owner has not configured BILLING_SUPPORT_CONTACT — then it is not shown. */
export interface Paywall {
  mode: 'coming_soon' | 'purchase'
  contact: string | null
}

export interface LessonHeader {
  stage: number | null
  lesson: number
  title: string | null
}

/** Why a session has no card to show (docs/04 A.3). `null`/absent while there is a card. */
export type DoneReason = 'lesson_complete' | 'nothing_due' | 'new_quota_exhausted' | 'session_cap'

/**
 * Free new-word quota after the step (docs/04 A.3); `new_words_remaining` null = no limit applies (Pro, or a group
 * lesson, also for Free). It says nothing about the plan: that comes only from `/me.entitlement`.
 */
export interface Quota {
  new_words_remaining: number | null
}

export type TrainingMode = 'group_lesson' | 'lesson' | 'daily_review'

/** Where a personal session was opened from (stored for analytics). */
export type SessionSource = 'self' | 'picker' | 'reminder' | 'onboarding'

export interface TrainingStartResponse {
  session_id: number
  mode: TrainingMode
  /** null for `daily_review`: the scope spans every lesson. */
  lesson: LessonHeader | null
  total_words: number
  card?: Card | null
  progress?: { done: number; total: number }
  done_reason?: DoneReason | null
  quota?: Quota
}

/** Body of POST /training/sessions. */
export interface OpenSessionRequest {
  mode: 'lesson' | 'daily_review'
  lesson_id?: number
  source?: SessionSource
}

/** Goal of the learner's local day (docs/04 A.2a). */
export interface DailyGoal {
  target: number
  done: number
  met: boolean
}

export interface Streak {
  current: number
  best: number
  /** Today's goal is already met: the series cannot break today. */
  met_today: boolean
  /** The series ends unless the goal is met before local midnight. */
  at_risk: boolean
}

/** Answer of POST /training/sessions/{id}/finish. `goal` / `streak`: state of the whole day, not of the session. */
export interface SessionSummary {
  reviewed: number
  correct: number
  new_words: number
  goal?: DailyGoal
  streak?: Streak
}

export interface TodayLesson {
  id: number
  stage: number | null
  lesson: number
  title: string | null
}

export interface TodayCourse {
  id: number
  slug: string
  title: string
  progress: { learned: number; total: number }
  /** null once every word of the course has been served. */
  next_lesson: TodayLesson | null
}

/** Answer of GET /today (docs/04 A.2a). */
export interface TodayResponse {
  due_count: number
  /** Free quota left for today; null = no limit applies. Not a Pro marker: the plan is `entitlement.plan`. */
  new_words_remaining: number | null
  goal: DailyGoal
  streak: Streak
  active_course: TodayCourse | null
  /** Open, unexpired daily review to continue. */
  open_session_id: number | null
  entitlement: Pick<Entitlement, 'plan' | 'source' | 'until'>
  onboarding_completed: boolean
}

/**
 * due      — graduated card scheduled in days (SM-2)
 * new      — never answered
 * learning — inside minute-based learning/relearning steps (returns in the same session)
 */
export type CardKind = 'due' | 'new' | 'learning'

export interface Card {
  word_id: number
  word: string
  translation: string
  /**
   * true — `translation` is the Russian fallback because the language of the
   * session (group / learner) has no translation of this word yet; the card
   * shows a note (docs/12 §1.7).
   */
  translation_fallback: boolean
  example: string | null
  transcription: string | null
  /** Pre-generated mp3 (docs/12 task 1.2c); absent/null → Web Speech / `GET /tts` fallback. */
  audio_url?: string | null
  card_kind: CardKind
  /**
   * One-time learn-ahead ticket (docs/06 §5.2). Non-null only when the server
   * served a `learning` card before its step elapsed; must be echoed as
   * `serve_token` in POST /review for that card, otherwise 409 `card_not_due`.
   */
  serve_token: string | null
}

export interface NextCardResponse {
  card: Card | null
  progress: { done: number; total: number }
  done_reason?: DoneReason | null
  quota?: Quota
}

/**
 * Grade sent to POST /review (server accepts 0..5).
 *   2 — Трудно / Again: failed, card restarts learning steps
 *   4 — Средне / Good:  passed, EF unchanged
 *   5 — Легко  / Easy:  passed, EF +0.10 (skips remaining learning steps)
 * 3 ("Hard" pass, EF -0.14) is valid for the API but has no button in v1.
 */
export type Quality = 2 | 4 | 5

export interface ReviewResponse {
  next_review_at: string | null
  new_interval_days: number
  easiness_factor: number
  card?: Card | null
  progress?: { done: number; total: number }
  done_reason?: DoneReason | null
  quota?: Quota
}

export interface ApiError {
  error: {
    code: string
    message: string
    details?: Record<string, unknown>
  }
}

// ---------------- Exam ----------------

export interface ExamJoinResponse {
  session_id: number
  ends_at: string | null
  total_questions: number
  seconds_per_question: number
  question?: ExamQuestionResponse | null
}

export interface ExamQuestionResponse {
  question_index: number
  word_id: number
  word: string
  options: string[]
  seconds_left: number
}

export interface ExamAnswerResponse {
  is_correct: boolean
  correct_option: number
  score_earned: number
  total_score: number
  has_next: boolean
  next_question?: ExamQuestionResponse | null
}

export interface ExamLeaderboardEntry {
  rank: number
  name: string
  score: number
}

export interface ExamResultResponse {
  learner_score: number
  rank: number | null
  total_participants: number
  leaderboard: ExamLeaderboardEntry[]
}

// ---------------- Course catalog ----------------

export interface Progress {
  done: number
  total: number
}

/** Row of GET /courses and the answer of POST /me/course. Texts are localized by the server. */
export interface Course {
  slug: string
  title: string
  description: string | null
  level: string | null
  exam_tag: string | null
  words_count: number
  is_featured: boolean
  is_active: boolean
  progress: Progress
}

export interface CoursesResponse {
  courses: Course[]
}

export interface CourseLesson {
  id: number
  number: number
  title: string | null
  progress: Progress
}

export interface CourseStage {
  number: number
  title: string
  progress: Progress
  lessons: CourseLesson[]
}

export interface CourseDetail {
  slug: string
  title: string
  description: string | null
  level: string | null
  exam_tag: string | null
  words_count: number
  is_active: boolean
  progress: Progress
  stages: CourseStage[]
}

/** A learner's own deck (docs/04 A.10). */
export interface Deck {
  slug: string
  title: string
  words_count: number
  is_active: boolean
  progress: Progress
}

export interface DeckWord {
  id: number
  word: string
  translation: string
  translation_uz: string | null
  example: string | null
}

export interface DeckDetail extends Deck {
  words: DeckWord[]
}

/** Plan limits of decks: `decks` per learner, `deck_words` per deck; `used_decks` is the current count. */
export interface DeckLimits {
  decks: number
  deck_words: number
  used_decks: number
}

export interface DecksResponse {
  decks: Deck[]
  limits: DeckLimits
}

export type DeckImportFormat = 'list' | 'csv'

export type DeckRejectReason = 'empty_translation' | 'junk' | 'too_long' | 'duplicate' | 'over_limit'

/** Answer of POST /decks/{slug}/words. */
export interface DeckImportResult {
  added: number
  reactivated: number
  duplicates: number
  rejected: { line: number; reason: DeckRejectReason }[]
  deck: Deck
}
