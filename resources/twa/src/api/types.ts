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
  /**
   * Practice access (docs/16 §3.3). The server always sends it; optional here so that an answer of an older server (and
   * every fixture typed as `MeResponse`) reads as "no practice": the billing store turns a missing block into `available: false`.
   */
  practice?: PracticeAccess
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
  /** Leagues (docs/13 §7.3): true = the learner is not placed in weekly groups. */
  leagues_opt_out: boolean
  /** ISO 8601 UTC: the time zone may be changed again from then on (once in 24 h); null = now. */
  timezone_change_available_at: string | null
  /** until when the last change of the zone can be taken back (10 minutes); null = it cannot */
  timezone_undo_available_until: string | null
  active_course: { slug: string; title: string } | null
  /** Exercise types the learner has switched on (docs/13 §8.2); flash cards are always on. */
  exercises: ExerciseSettings
  options: {
    locales: SettingsChoice[]
    timezones: SettingsChoice[]
    reminder_times: string[]
    daily_goals: number[]
  }
}

/** One active membership of GET /me/organizations (docs/04 A.11). */
export interface OrganizationMembership {
  /** organization_members.id: what DELETE /me/organizations/{id} takes. */
  id: number
  name: string
  /** `seated` = Pro from the center; `waitlisted` = no free seat yet (plain Free). */
  seat_state: 'seated' | 'waitlisted' | string
  /** `invite` / `admin` can be left by the learner; `group` comes back with the group chat. */
  joined_via: 'invite' | 'group' | 'admin' | string
  joined_at: string
  can_leave: boolean
}

export interface OrganizationsResponse {
  organizations: OrganizationMembership[]
}

/** GET /referrals (docs/04 A.12): "Invite a friend". `link` is null while the bot username is not configured. */
export type ReferralStatus = 'pending' | 'activated' | 'rewarded'

export interface ReferralItem {
  first_name: string | null
  status: ReferralStatus
  linked_at: string
}

export interface ReferralsResponse {
  code: string
  link: string | null
  reward_days: number
  activation: { days: number; reviews_per_day: number; window_days: number }
  counts: { invited: number; activated: number; rewarded: number; capped: number }
  referrals: ReferralItem[]
}

/** GET /league (docs/04 A.13): the learner's group of the week. */
export type LeagueTierKey = 'bronze' | 'silver' | 'gold' | 'platinum' | 'diamond' | string
export type LeagueResult = 'promoted' | 'stayed' | 'demoted'
export type LeagueZone = 'promotion' | 'safe' | 'demotion'

export interface LeagueStandingItem {
  rank: number
  /** First name of the member; null = no name to show. Nothing else of other people is sent. */
  name: string | null
  points: number
  is_me: boolean
}

export interface LeagueMembership {
  tier: number
  tier_key: LeagueTierKey
  rank: number
  points: number
  members_count: number
  zone: LeagueZone
  /** Points missing to enter the promotion zone; null when already there or the tier cannot promote. */
  points_to_promote: number | null
  /** Points missing to leave the demotion zone; null when not in it or the tier cannot demote. */
  points_to_safety: number | null
  standings: LeagueStandingItem[]
}

export interface LeagueResponse {
  enabled: boolean
  opted_out: boolean
  /** `closes_at`: ISO 8601 UTC instant of the next Monday 00:00 in the league time zone. */
  week: { starts_on: string; ends_on: string; closes_at: string }
  rules: { capacity: number; promote: number; demote: number; tiers: LeagueTierKey[] }
  membership: LeagueMembership | null
  last_week: { starts_on: string; tier: number; tier_key: LeagueTierKey; result: LeagueResult; rank: number; points: number } | null
}

/** GET /achievements (docs/04 A.15): the whole catalog; `title` / `description` / `share_text` are in the request language. */
export interface AchievementItem {
  /** `first_session`, `streak_7`, `words_100`, `league_top1`, … */
  key: string
  title: string
  description: string
  /** ISO 8601; null = still locked. */
  unlocked_at: string | null
  /** ISO 8601; set once the learner opened the share dialog. */
  shared_at: string | null
  /** Progress towards the threshold of a counting key (`current` is capped at `target`); null for one-off keys. */
  progress: { current: number; target: number } | null
  /** Prefilled message of the share dialog; null while locked. */
  share_text: string | null
}

export interface AchievementsResponse {
  unlocked_count: number
  total: number
  /** The learner's invite link the share carries; null while the bot username is not configured (no share button). */
  share_link: string | null
  items: AchievementItem[]
}

/** GET /friends (docs/04 A.13): friends, incoming / outgoing requests. `id` is the friendship id; of other people only a first name, the streak and the learned words. */
export interface FriendItem {
  id: number
  first_name: string | null
  streak: number
  words_learned: number
  since: string | null
}

export interface FriendRequestItem {
  id: number
  first_name: string | null
  requested_at: string | null
}

export interface FriendsResponse {
  invite_link: string | null
  limit: number
  friends: FriendItem[]
  incoming: FriendRequestItem[]
  outgoing: FriendRequestItem[]
}

/** GET /challenges, POST /challenges[/id/accept|decline] (docs/04 A.13). */
export type ChallengeMetric = 'reviews' | 'new_words'
export type ChallengeStatus = 'pending' | 'active' | 'finished' | 'declined' | 'expired' | 'canceled'

export interface ChallengeItem {
  id: number
  status: ChallengeStatus
  metric: ChallengeMetric
  days: number
  /** `challenger` = you proposed it; `opponent` = you were challenged. */
  role: 'challenger' | 'opponent'
  friend_name: string | null
  /** null once the friendship is gone. */
  friendship_id: number | null
  starts_on: string | null
  ends_on: string | null
  created_at: string | null
  my_score: number | null
  their_score: number | null
  result: 'won' | 'lost' | 'draw' | null
}

export interface ChallengesResponse {
  days: number
  open: ChallengeItem[]
  finished: ChallengeItem[]
}

export interface ExerciseSettings {
  reverse: boolean
  audio: boolean
  /** Pro feature: typed answers are served only with `exercise.spelling`. */
  spelling: boolean
  /** Pro feature. */
  gap_fill: boolean
}

/** Body of PATCH /me/settings: only the fields to change; `reminder_time: null` turns the reminders off. */
export type SettingsPatch = Partial<
  Pick<LearnerSettings, 'interface_locale' | 'timezone' | 'reminder_time' | 'daily_goal' | 'goal' | 'level' | 'leagues_opt_out'>
> & { exercises?: Partial<ExerciseSettings> }

/** `source`: 'free' | 'subscription' | 'sponsor:organization:<id>'; `until`: ISO 8601, null = no end. */
export interface Entitlement {
  plan: 'free' | 'pro'
  source: string
  until: string | null
  /** null = unlimited */
  new_words_per_day: number | null
  /**
   * The learner's own live subscription (docs/04 A.2); null = none (Free, or Pro only from a center).
   * Absent in the entitlement of `/today` and in the auth answer.
   */
  renewal?: Renewal | null
  /**
   * "Pro ended" (docs/04 A.2, `/me` only): the learner is on Free and their own paid subscription expired
   * lately; null = nothing to offer. Absent in the entitlement of `/today` and in the auth answer.
   */
  lapsed?: Lapsed | null
}

export interface Lapsed {
  provider: string
  /** ISO 8601 moment the subscription expired; also the key of the local "dismissed" mark. */
  ended_at: string
}

export interface Renewal {
  /** The auto-renewal is running (Telegram Stars, status `active`). */
  auto: boolean
  provider: string
  /** null = no end. */
  ends_at: string | null
  /** A "renew soon" reminder was already sent for the current period end. */
  reminder_sent: boolean
}

// ---- billing (docs/04 A.8) ----

export type BillingProviderKey = 'telegram_stars' | 'payme' | 'click'
export type BillingPeriod = 'month' | 'year'
/** Prices are minor units: XTR = whole Stars, UZS = tiyin (÷ 100 to show). */
export type BillingCurrency = 'XTR' | 'UZS'
export type Prices = Partial<Record<BillingCurrency, number>>

export interface CatalogItem {
  plan: string
  tier: string | null
  period: BillingPeriod
  /** After the open offer. */
  prices: Prices
  /** Before it. */
  list_prices: Prices
}

export interface SubscriptionSummary {
  status: string
  provider: string
  plan: string
  /** null = no end. */
  ends_at: string | null
  auto_renew: boolean
  can_cancel: boolean
  can_resume: boolean
  /** Pro without an end date (a manual grant): nothing to buy, the server answers `already_perpetual`. */
  perpetual: boolean
}

export interface BillingCatalog {
  /** Empty = nothing to sell. */
  items: CatalogItem[]
  providers: BillingProviderKey[]
  /** `period` null = the offer covers any period; the rows of `items` already carry the discounted `prices`. */
  offer: { kind: string; discount_pct: number; period: BillingPeriod | null; expires_at: string } | null
  subscription: SubscriptionSummary | null
}

export interface CheckoutResponse {
  order: { id: number; token: string; status: OrderStatus; amount: number; currency: BillingCurrency; expires_at: string }
  /** `invoice_link` → Telegram.WebApp.openInvoice, `redirect` → openLink. */
  checkout: { url: string; kind: 'invoice_link' | 'redirect' }
}

export type OrderStatus = 'pending' | 'paid' | 'canceled' | 'expired' | 'failed'

export interface OrderStatusResponse {
  status: OrderStatus
  paid_at: string | null
  /** `replaced`: canceled because the offer's discount moved to a newer order / was used up — not a failure. */
  reason: 'replaced' | null
  /** Token (never an id) of the order that took over, when known. */
  replaced_by: string | null
  subscription: SubscriptionSummary | null
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
export type SessionSource = 'self' | 'picker' | 'reminder' | 'onboarding' | 'assignment'

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
  /** Streak freezes the learner can spend (Pro feature; 0 on Free — docs/13 §7.2). */
  freezes: number
  /** Yesterday was missed and held by a freeze. */
  frozen_yesterday: boolean
}

/** Answer of POST /training/sessions/{id}/finish. `goal` / `streak`: state of the whole day, not of the session. */
export interface SessionSummary {
  reviewed: number
  correct: number
  new_words: number
  goal?: DailyGoal
  streak?: Streak
  /** Where "Practice of the lesson / course" leads (docs/16 §11.1); null: no entry (daily review, group session, no access). */
  practice_entry?: { course: string; lesson_id: number | null } | null
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
  /** The learner's place in this week's league group (docs/13 §7.3); null while not placed. */
  league: TodayLeague | null
  /**
   * IANA id of the learner's time zone (`Asia/Tashkent`): the day of `goal` and `streak` is counted in it, so the
   * header of Home dates itself by it, not by the device (docs/04 A.2a). Always a non-empty string.
   */
  timezone: string
}

export interface TodayLeague {
  tier: number
  tier_key: LeagueTierKey
  rank: number
  points: number
  members_count: number
}

/** One assignment of the learner's center (GET /assignments, docs/04 A.9): only the learner's own progress. */
export interface AssignmentItem {
  id: number
  title: string
  group: string
  /** What `POST /training/sessions {mode: 'lesson'}` takes. */
  lesson_id: number
  stage: number
  lesson: number
  /** ISO 8601 with offset. */
  due_at: string
  /** The due date has passed, the assignment is still open (late work counts). */
  overdue: boolean
  progress_pct: number
  completed: boolean
  completed_at: string | null
  /** Completed after the due date. */
  late: boolean
}

/** Answer of GET /assignments. */
export interface AssignmentsResponse {
  assignments: AssignmentItem[]
}

/**
 * due      — graduated card scheduled in days (SM-2)
 * new      — never answered
 * learning — inside minute-based learning/relearning steps (returns in the same session)
 */
export type CardKind = 'due' | 'new' | 'learning'

/**
 * How the card is presented and who grades it (docs/13 §2.5, docs/04 A.3):
 * flash / reverse / audio → POST /review {quality}; spelling / gap_fill → POST /review {answer}.
 */
export type ExerciseType = 'flash' | 'reverse' | 'audio' | 'spelling' | 'gap_fill'

export interface ExercisePrompt {
  type: ExerciseType
  /** reverse / spelling / gap_fill: the translation; flash: the word; audio: '' (the word is only heard). */
  front: string
  /** gap_fill: the example with the word's form replaced by `____`. */
  masked_example: string | null
  /** reverse: four English words; audio: four translations (the right one among them). */
  options: string[] | null
  /** spelling / gap_fill: length of the expected text. */
  answer_length: number | null
}

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
  /** The exercise of this serve; absent/null = flash (older servers). */
  prompt?: ExercisePrompt | null
}

export interface NextCardResponse {
  card: Card | null
  progress: { done: number; total: number }
  done_reason?: DoneReason | null
  quota?: Quota
}

/**
 * Grade sent to POST /review (server accepts 0..5).
 *   2 — «Не помню» / Again: failed, card restarts learning steps
 *   4 — «Помню»    / Good:  passed, EF unchanged
 *   5 — «Легко»    / Easy:  passed, EF +0.10 (skips remaining learning steps)
 * 3 ("Hard" pass, EF -0.14) is valid for the API but has no button in v1.
 */
export type Quality = 2 | 4 | 5

/** The server's grade of a typed answer (spelling / gap_fill). */
export interface Graded {
  /** 5 exact, 4 within the tolerance, 1 wrong. */
  quality: number
  correct: boolean
  /** The normalized right answer, to show after a miss. */
  expected: string
}

export interface ReviewResponse {
  next_review_at: string | null
  new_interval_days: number
  easiness_factor: number
  /** null when the client graded (quality was sent). */
  graded?: Graded | null
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

export type ExamMode = 'group_exam' | 'personal_mock'

/** POST /courses/{slug}/mock-exam (docs/04 A.4a): the learner's IELTS / SAT mock, new (201) or the open one (200). */
export interface MockExamOpenResponse {
  session_id: number
  mode: ExamMode
  course_id: number | null
  started_at: string | null
  ends_at: string | null
  total_questions: number
  /** Whole-exam time limit; a mock has no per-question timer. */
  seconds: number
  resumed: boolean
}

/** GET /exam/sessions/{id}/result of a `personal_mock` session: percent + percentile instead of a leaderboard. */
export interface MockExamResultResponse {
  mode: 'personal_mock'
  correct: number
  total: number
  answered: number
  percent: number
  /** Share of closed mocks of the course over 90 days at or below this percent; null while open. */
  percentile: number | null
  attempts: number
  course_id: number | null
}

export type AnyExamResultResponse = ExamResultResponse | MockExamResultResponse

export function isMockExamResult(result: AnyExamResultResponse): result is MockExamResultResponse {
  return 'mode' in result && result.mode === 'personal_mock'
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
  /** null: the module has no name of its own; it is shown as "Module N" (`course.module`) in the language of the reader. */
  title: string | null
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

// ---------------- Practice and battle (docs/16 §7, §11.7) ----------------

export type PracticeMode = 'pairs' | 'collocation' | 'sentence' | 'dictation'

/** `kind` of an element a screen draws: it equals the mode except for pairs, whose element is one round. */
export type PracticeElementKind = 'pairs_round' | 'collocation' | 'sentence' | 'dictation'

/** Block `practice` of GET /me (docs/16 §3.3): `available` false hides every entry; `pro_required` shows them with a Pro chip. */
export interface PracticeAccess {
  available: boolean
  pro_required: boolean
  /** The modes switched on. */
  modes: PracticeMode[]
  battle: boolean
}

export interface PracticeCourseRef {
  slug: string
  title: string
}

export interface PracticeLessonRef {
  id: number
  /** Number of the module; null when the server does not know it (the lesson was read from a snapshot that has none). */
  stage: number | null
  /** Number of the lesson inside the module; null likewise. */
  number: number | null
  title: string | null
}

export type PracticeSource = 'hub' | 'after_training' | 'retry' | 'deeplink'

/** `available` is playable; `not_enough` lacks material (see `reason`); `disabled` is switched off; `pro_required` is behind the paywall. */
export type PracticeModeState = 'available' | 'not_enough' | 'disabled' | 'pro_required'

/** Why a mode lacks material (docs/16 §4.3). `no_items`: there are no published tasks for the topic (or too few). */
export type PracticeShortageReason = 'no_words' | 'ambiguous' | 'no_items' | 'audio_pending' | 'no_audio_words'

/**
 * Why a card of the HUB lacks material: the reasons above, and `items_locked` - the tasks are there, but the learner has not met the
 * target words of the lesson yet (docs/16 §4.2). A battle has no learner whose words could be "not met", so its formats
 * (`BattleModeOption`) keep `PracticeShortageReason` and never get this one.
 */
export type PracticeHubReason = PracticeShortageReason | 'items_locked'

/** A card of the hub. */
export interface PracticeModeInfo {
  mode: PracticeMode
  state: PracticeModeState
  available: number
  required: number
  /** Set with `not_enough`. */
  reason: PracticeHubReason | null
  estimate_seconds: number
}

/** The unfinished solo session (one per learner). */
export interface PracticeActiveSession {
  id: number
  mode: PracticeMode
  course: string
  lesson_id: number | null
  progress: Progress
}

/** GET /practice/hub (docs/16 §7.1). */
export interface PracticeHubResponse {
  course: PracticeCourseRef
  lesson: PracticeLessonRef | null
  modes: PracticeModeInfo[]
  active_session: PracticeActiveSession | null
  battle: { enabled: boolean; active_battle_id: number | null; incoming_count: number }
}

/** A fragment, an option or a side of a pair: an opaque id and what is written on it. */
export interface PracticeChoice {
  id: string
  text: string
}

/** One round of "Find the pairs": the words on the left, the translations on the right (shuffled separately). */
export interface PairsRoundElement {
  id: string
  kind: 'pairs_round'
  round: number
  rounds: number
  left: PracticeChoice[]
  right: PracticeChoice[]
  /** `[left_id, right_id]` of the pairs already put together. */
  matched: [string, string][]
  mismatches: number
}

export interface CollocationElement {
  id: string
  kind: 'collocation'
  /** With one blank: `Let's ___ a meeting.` */
  text: string
  options: PracticeChoice[]
}

export interface SentenceElement {
  id: string
  kind: 'sentence'
  /** The meaning in the language of the learner. */
  prompt: string
  fragments: PracticeChoice[]
}

/** Dictation: there is no text, only the clip behind `audio_url`; `words` is the number of words (blanks to draw). */
export interface DictationElement {
  id: string
  kind: 'dictation'
  source: 'phrase' | 'word'
  /** Path of the API (`/api/twa/practice/sessions/7/audio/eD4…`): fetched with the token, never put into `<audio src>`. */
  audio_url: string
  words: number
  replacements_left: number
}

export type PracticeElement = PairsRoundElement | CollocationElement | SentenceElement | DictationElement

/** What the learner sends for one element. `pairs` is the battle form (the whole set at once). */
export type PracticeAnswerPayload =
  | { left_id: string; right_id: string }
  | { option_id: string }
  | { order: string[] }
  | { text: string }
  | { pairs: Record<string, string> }
  | { skip: true }

export type PracticeVerdict = 'correct' | 'typo' | 'wrong' | 'skipped'

/** Body of POST /practice/sessions/{id}/answer. */
export interface PracticeAnswerRequest {
  /** uuid: the key of the request, the same one for a repeat of it. */
  request_id: string
  element_id: string
  time_spent_ms?: number | null
  answer: PracticeAnswerPayload
}

/** Body of POST /practice/sessions/{id}/replace. */
export interface PracticeReplaceRequest {
  request_id: string
  element_id: string
  reason: 'audio_unavailable'
}

/** Body of POST /practice/sessions. */
export interface PracticeOpenRequest {
  mode: PracticeMode
  course: string
  lesson_id?: number | null
  source: PracticeSource
  replace_active?: boolean
  retry_of?: number | null
}

/** One token of the dictation diff: what was expected and what was typed in its place. */
export interface PracticeDiffToken {
  expected: string
  got: string
  ok: boolean
}

export interface DictationReveal {
  expected: string
  your_text: string | null
  /** Token by token, only when the answer had as many tokens as the phrase. */
  diff: PracticeDiffToken[] | null
  translation: string | null
  explanation: string | null
}

export interface SentenceReveal {
  correct_text: string
  /** Other accepted orders. */
  alternatives: string[]
  your_text: string | null
  explanation: string | null
}

export interface CollocationReveal {
  correct_option_ids: string[]
  full_text: string
  translation: string | null
  explanation: string | null
  /**
   * The option the server counted as the learner's answer (null: none - a hint). The server adds it to the explanation so that
   * the review is true on its own; an older server does not send it (absent: the screen marks the pick it knows).
   */
  your_option_id?: string | null
}

/** The explanation shown after an answer; its shape follows the kind of the element answered (pairs have none). */
export type PracticeReveal = DictationReveal | SentenceReveal | CollocationReveal

/** One mistake of the summary: a word of the pairs, a phrase, a sentence. */
export interface PracticeMistake {
  element_id: string
  kind: PracticeElementKind
  label: string
  your_answer: string | null
  verdict: 'typo' | 'wrong' | 'skipped'
}

export type PracticeFinishReason = 'completed' | 'expired' | 'replaced'

/** `summary` of a finished session (docs/16 §6.4). */
export interface PracticeSummary {
  total: number
  answered: number
  correct_first: number
  typos: number
  hints: number
  /** Pairs only: the wrong matches; null elsewhere. */
  mismatches: number | null
  duration_ms: number
  finish_reason: PracticeFinishReason
  retry_available: boolean
  mistakes: PracticeMistake[]
}

/** GET /practice/sessions/{id}; also what open, replace and every answer return (docs/16 §7.2). */
export interface PracticeSession {
  id: number
  status: 'active' | 'finished'
  mode: PracticeMode
  source: PracticeSource
  retry_of: number | null
  course: PracticeCourseRef
  lesson: PracticeLessonRef | null
  progress: Progress
  counters: { correct_first: number; hints: number; mismatches: number }
  expires_at: string
  server_now: string
  /** The element to answer; null once the session is finished. */
  current: PracticeElement | null
  summary: PracticeSummary | null
}

/** Answer of POST /practice/sessions/{id}/answer. Pairs: `verdict` is correct|wrong, `pair` the match tried, `reveal` null. */
export interface PracticeAnswerResponse {
  verdict: PracticeVerdict
  first_attempt: boolean
  reveal: PracticeReveal | null
  pair: { left_id: string; right_id: string } | null
  /** Pairs: the round is complete. Absent for the other modes. */
  round_done?: boolean
  session: PracticeSession
}

export type BattleStatus = 'invited' | 'room' | 'countdown' | 'playing' | 'finished' | 'closed'
export type BattlePhase = 'answering' | 'reveal'
export type BattleRole = 'challenger' | 'opponent'
export type BattleEndReason =
  | 'completed' | 'surrender' | 'absent'
  | 'declined' | 'invite_expired' | 'room_expired' | 'canceled' | 'both_absent' | 'voided'
/** `none`: a closed battle has no result. */
export type BattleOutcome = 'won' | 'lost' | 'draw' | 'none'
/** The verdict of a round; `timeout` is written by the server when the time ran out. */
export type BattleVerdict = PracticeVerdict | 'timeout'

export interface BattleOpponent {
  first_name: string | null
}

/** One format of GET /practice/battles/options (docs/16 §7.3). */
export interface BattleModeOption {
  mode: PracticeMode
  state: PracticeModeState
  available: number
  required: number
  reason: PracticeShortageReason | null
  round_seconds: number
}

export interface BattleOptionsResponse {
  friend: BattleOpponent
  course: PracticeCourseRef
  lesson: PracticeLessonRef | null
  modes: BattleModeOption[]
  friend_access: 'available' | 'no_access'
}

/** The round the battle is in. */
export interface BattleRoundState {
  no: number
  /** `null` while the countdown runs: the round has a number and a deadline, but no phase before the match starts (docs/16 §8.1). */
  phase: BattlePhase | null
  /** End of the time to answer (server clock). */
  deadline_at: string | null
  /** End of the pause that shows the answers. */
  phase_ends_at: string | null
  my_answered: boolean
  opponent_answered: boolean
}

export interface BattleScores {
  me: number
  opponent: number
}

export interface BattleRoundResult {
  no: number
  me: { verdict: BattleVerdict; points: number }
  opponent: { verdict: BattleVerdict; points: number }
}

export interface BattleResult {
  outcome: BattleOutcome
  end_reason: BattleEndReason
  rounds: BattleRoundResult[]
}

/**
 * The rematch offered after a finished battle (docs/16 §7.4, §8.7): the next battle with `rematch_of` = this one, and who offered it.
 * `by_me` tells the result screen whether the offer is the learner's to answer or their own to open.
 */
export interface BattleRematch {
  battle_id: number
  status: BattleStatus
  by_me: boolean
}

/**
 * GET /practice/battles/{id} and the answer of every action (docs/16 §7.4, "Реализация 18.6b": the wire form - every key is always
 * there, and what is absent is `null`; pinned by `BattleContractTest`).
 */
export interface Battle {
  id: number
  status: BattleStatus
  /** Why the battle ended (the same as `result.end_reason`); `null` while it is on. */
  end_reason: BattleEndReason | null
  mode: PracticeMode
  role: BattleRole
  course: PracticeCourseRef
  lesson: PracticeLessonRef | null
  opponent: BattleOpponent
  rounds_total: number
  round_seconds: number
  countdown_seconds: number
  reveal_seconds: number
  /** How long a player who has gone silent is waited for before the match ends (30 s): the strip counts down to it. */
  presence_grace_seconds: number
  /** After how long of silence `opponent_present` turns false (10 s). */
  presence_warn_seconds: number
  /** How long after the deadline of a round an answer is still taken, in milliseconds (1500): the screen asks the server after it. */
  deadline_grace_ms: number
  invite_expires_at: string
  room_expires_at: string | null
  starts_at: string | null
  /** Grows with every change the players can see; a poll that brings a new one means: read the battle again. */
  version: number
  server_now: string
  ready: { me: boolean; opponent: boolean }
  opponent_present: boolean
  /** The last time the opponent was seen, while `opponent_present` is false (the strip counts `presence_grace_seconds` from it); else `null`. */
  opponent_absent_since: string | null
  round: BattleRoundState | null
  scores: BattleScores
  result: BattleResult | null
  /** The rematch offered after this battle; only a battle that is over has one. */
  rematch: BattleRematch | null
  /** The friendship of the battle (a rematch needs none: the server takes the friend from the finished battle); `null` if it is gone. */
  friendship_id: number | null
  /** Dictation, `room` and `countdown` only (docs/16 §10.3): the clips of the five rounds to load before "Ready"; else `null`. */
  audio_preload: string[] | null
}

/** GET /practice/battles/{id}/poll (docs/16 §7.4): at most 600 bytes, read every two seconds. */
export interface BattlePoll {
  id: number
  status: BattleStatus
  version: number
  server_now: string
  ready: { me: boolean; opponent: boolean }
  opponent_present: boolean
  /** As in `Battle`. */
  opponent_absent_since: string | null
  round: BattleRoundState | null
  scores: BattleScores
  end_reason: BattleEndReason | null
  /** As in `Battle`; the poll of a battle that is on carries `null` (the server does not look for one). */
  rematch: BattleRematch | null
}

export interface BattleSideResult {
  answer: Record<string, unknown> | null
  verdict: BattleVerdict
  points: number
}

/** What the round shows once it is resolved; the correct answer in the form of the mode. */
export interface BattleReveal {
  correct: {
    correct_text?: string
    correct_option_ids?: string[]
    expected?: string
    pairs?: Record<string, string>
    /** Collocation: the whole phrase with the right option in the blank. */
    full_text?: string
    /** Sentence: the other orders that are right too (without the first one, which is `correct_text`). */
    alternatives?: string[]
  }
  me: BattleSideResult
  opponent: BattleSideResult
  explanation: string | null
  translation: string | null
}

/** GET /practice/battles/{id}/round?no= (docs/16 §7.4). */
export interface BattleRound {
  no: number
  /** `null` for round 1 of a battle in its countdown: the round exists, its phase has not begun. */
  phase: BattlePhase | null
  deadline_at: string | null
  phase_ends_at: string | null
  server_now: string
  element: PracticeElement
  /** Null until the round is resolved. */
  reveal: BattleReveal | null
}

export interface BattleIncoming {
  id: number
  mode: PracticeMode
  from: BattleOpponent
  course: PracticeCourseRef
  lesson: PracticeLessonRef | null
  invite_expires_at: string
  rematch: boolean
}

export interface BattleFinishedItem {
  id: number
  mode: PracticeMode
  opponent: BattleOpponent
  outcome: BattleOutcome
  scores: BattleScores
  finished_at: string
}

/**
 * GET /practice/battles (docs/16 §7.3). `active` is "BattleResource, compact" in the design: of it the fields every form
 * of the resource carries are typed here; the screen reads the battle itself with `GET /practice/battles/{id}`.
 */
export interface BattleListResponse {
  active: Pick<Battle, 'id' | 'status' | 'mode' | 'role' | 'course' | 'lesson' | 'opponent'> | null
  incoming: BattleIncoming[]
  finished: BattleFinishedItem[]
}

/** Body of POST /practice/battles. */
export interface BattleInviteRequest {
  /** The friendship to invite. A rematch (`rematch_of`) takes the friend from the finished battle: the id is left out when the TWA does not know it. */
  friendship_id?: number
  mode: PracticeMode
  course: string
  lesson_id?: number | null
  /** My finished battle: the format and the topic are taken from it. */
  rematch_of?: number | null
}

/** Body of POST /practice/battles/{id}/answer. */
export interface BattleAnswerRequest {
  request_id: string
  round: number
  answer: PracticeAnswerPayload
}

/** Answer of POST /practice/battles/{id}/answer; the correct answer is not returned, it comes with the resolved round. */
export interface BattleAnswerResponse {
  verdict: BattleVerdict
  points: number
  accepted_at: string
  round_resolved: boolean
  poll: BattlePoll
}

/** Body of POST /practice/battles/{id}/audio-failed. */
export interface BattleAudioFailedRequest {
  request_id: string
  element_id: string
}

/** Answer of POST /practice/battles/{id}/audio-failed: `verified` true when the server confirmed the clip is missing. */
export interface BattleAudioFailedResponse {
  verified: boolean
  battle: Battle
}
