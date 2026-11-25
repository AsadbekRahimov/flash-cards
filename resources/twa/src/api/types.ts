import type { GameClassBossStartRequest, GameClassCard } from './gameClassTypes'

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
  /** Optional so that an answer of an older server reads as "nothing to say". */
  account_restored?: AccountRestored | null
  /** The share of opens (0..100) that report their timing marks (docs/04 A.21); absent (an older server) - none report. */
  client_timing?: { sample_percent: number }
}

/** `POST /me/delete` (docs/04 A.1a): the account is soft-deleted; `/start` in the bot brings it back until `restorable_until`. */
export interface AccountDeletedResponse {
  deleted: true
  restorable_until: string
}

export interface MeResponse {
  /** `pro_emoji`: the emoji next to the name (docs/31 §5а) - only while the learner has Pro, else null */
  learner: Learner & { username: string | null; pro_emoji?: string | null }
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
  /** Game access (docs/18 §12.8); optional for the same reason as `practice`: no block reads as "no game". */
  game?: GameAccess
}

/**
 * "Account restored" (R11b, docs/04 A.1): null = nothing to say; else the learner came back within the 30 days and has not been
 * told yet. `pro_can_resume`: the Pro auto-renewal, stopped by the deletion, can be switched on again.
 */
export interface AccountRestored {
  pro_can_resume: boolean
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
  /** The SRS arm of the learner (docs/06 §9): the retention dial is shown for `fsrs` only. */
  scheduler?: 'sm2' | 'fsrs'
  retention_mode?: RetentionMode
  /**
   * The region (docs/35 §2.3, docs/04 A.2b): a code of `options.regions` (`UZ-TK` … or `other`); null = not given. Optional here (as
   * `scheduler`) so that the fixtures of older specs stay complete; the server always sends it.
   */
  region_code?: string | null
  /** ISO 8601 UTC: another region can be chosen from then on (once in 30 days); null = now. */
  region_change_available_at?: string | null
  /** until when the last change of the region can be taken back (10 minutes); null = it cannot */
  region_undo_available_until?: string | null
  /** show the card "Choose your region" on the Rating tab: no region and no "Not now" in the last 30 days */
  region_prompt?: boolean
  /** The emoji of Pro (docs/31 §5а): the CODE chosen, kept without Pro too (a Free learner sees it as a plus of Pro). */
  pro_emoji_choice?: string | null
  options: {
    locales: SettingsChoice[]
    reminder_times: string[]
    daily_goals: number[]
    /**
     * The highest daily goal the plan allows (docs/04 A.2, docs/05 §8c-1): the goals above it are shown with a lock and open the
     * paywall instead of saving; null = no limit (Pro).
     */
    daily_goal_max: number | null
    retention_modes?: RetentionMode[]
    /** The 14 regions of Uzbekistan in the order of the language (Tashkent City first), then `other`; names localized by the server. */
    regions?: RegionChoice[]
    /** The set of the emoji of Pro, in the order of the picker. */
    pro_emojis?: ProEmojiChoice[]
    /** true = the emoji is a Pro feature for this learner: the picker shows a lock and opens the paywall */
    pro_emoji_locked?: boolean
  }
}

export interface RegionChoice {
  code: string
  name: string
}

export interface ProEmojiChoice {
  code: string
  /** one emoji, ready to show */
  emoji: string
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

/**
 * GET /referrals (docs/04 A.12): "Invite a friend". `link` is null while the bot username is not configured.
 * `rewarded` of a referral means "counted" towards the milestones (the friend did the setup and studied `activation`).
 */
export type ReferralStatus = 'pending' | 'activated' | 'rewarded'

export interface ReferralItem {
  first_name: string | null
  status: ReferralStatus
  linked_at: string
}

/** A reward step: `days` of Pro for the inviter when `friends` are counted. */
export interface ReferralMilestone {
  friends: number
  days: number
  /** null = not reached yet. */
  reached_at: string | null
  /** The reward is paid out. */
  rewarded: boolean
}

export interface ReferralsResponse {
  code: string
  link: string | null
  activation: { days: number; reviews_per_day: number; window_days: number }
  milestone_days: number
  counts: { invited: number; activated: number; counted: number }
  milestones: ReferralMilestone[]
  /** The first milestone not reached; null after the last one. */
  next: { friends: number; remaining: number } | null
  referrals: ReferralItem[]
}

/** GET /league (docs/04 A.13): the learner's group of the week. */
export type LeagueTierKey = 'bronze' | 'silver' | 'gold' | 'platinum' | 'diamond' | string
export type LeagueResult = 'promoted' | 'stayed' | 'demoted'
export type LeagueZone = 'promotion' | 'safe' | 'demotion'

/** The table of the word points v2 a learner competes in (docs/32 §2.3): fixed by the first credit of the week / month. */
export type ScoreCategory = 'free' | 'pro'

export interface LeagueStandingItem {
  /** A v2 week: the competition rank - a shared place reads 1, 2, 2, 4. */
  rank: number
  /** First name of the member; null = no name to show. Nothing else of other people is sent. */
  name: string | null
  points: number
  is_me: boolean
  /** The emoji of Pro next to the name (docs/31 §5а): only while the member's Pro is live; absent on an older server. */
  pro_emoji?: string | null
  /** A v2 week (docs/32 §5.1): the tie-break facts of the best days - long repetitions, days with points. Absent in a v1 week. */
  long_words?: number
  active_days?: number
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
  /** A v2 week (docs/32 §8.3): the table of the group; absent in a v1 week. */
  category?: ScoreCategory
  rules_version?: number
  /** Points that overtake the nearest better place for sure; null for a leader. Absent in a v1 week. */
  next_gap?: number | null
}

export interface LeagueResponse {
  enabled: boolean
  opted_out: boolean
  /** `closes_at`: ISO 8601 UTC instant of the next Monday 00:00 in the league time zone. */
  week: { starts_on: string; ends_on: string; closes_at: string }
  rules: { capacity: number; promote: number; demote: number; tiers: LeagueTierKey[] }
  membership: LeagueMembership | null
  /** `rank`: 0 or null - the learner was out of the ranks of that week (opted out, blocked). */
  last_week: { starts_on: string; tier: number; tier_key: LeagueTierKey; result: LeagueResult; rank: number | null; points: number } | null
  /** 2: the week counts by the word points (docs/32); absent: the v1 points. */
  rules_version?: number
  /** A v2 week: how many best days of the week count (docs/32 §3.5); absent on an older server. */
  best_days?: number
}

/** GET /league/month (docs/04 A.19, docs/13 §7.6): the monthly rating over the same league points. */
export type MonthlyRatingStatus = 'open' | 'closing' | 'pending_review' | 'confirmed'

export interface MonthlyRatingRules {
  /** Points that count towards the month per day. */
  daily_cap: number
  min_active_days: number
  min_account_days: number
  places: number
  top: number
}

export interface MonthlyPrize {
  place: number
  /** In the interface language already. */
  title: string
}

export interface MonthlyTopRow {
  rank: number
  /** First name; null = no name to show. */
  name: string | null
  points: number
  active_days: number
  is_me: boolean
  /** The emoji of Pro next to the name (docs/31 §5а), only while that Pro is live; absent on an older server. */
  pro_emoji?: string | null
  /** A v2 month: the tie-break fact (distinct long repetitions); absent in a v1 month. */
  long_words?: number
}

/** A row of a v2 table outside the top (neighbours, pages after a cursor, docs/32 §5.3). */
export interface BoardRow {
  /** The competition rank: a shared place reads 7, 7, 9. */
  rank: number
  name: string | null
  points: number
  long_words: number
  active_days: number
  is_me: boolean
  pro_emoji: string | null
}

/**
 * A table of a region (docs/35 §2.4, R7): any region may be opened. Shown from `min_participants` (5) participants; below that
 * `shown` is false and the table has no rows ("в вашей области пока 3 участника").
 */
export interface BoardRegion {
  /** `UZ-TK`, … or `other` (docs/35 §2.2). */
  code: string
  /** The name in the interface language. */
  name: string
  participants: number
  shown: boolean
  min_participants: number
}

/** The learner's place in their region (`me.region`): `rank` null while the table is hidden or the learner has no place. */
export interface BoardMyRegion extends BoardRegion {
  rank: number | null
}

/** `scope` of a table: the whole category, or a region (`?scope=region[&region=<code>]`). */
export type BoardScopeName = 'all' | 'region'

/**
 * The table asked of GET /league/month and GET /league/all-time (docs/04 A.19, A.19a): `category` left out - the learner's own;
 * `scope` left out - the whole category; `region` only with `scope: 'region'` (left out - the learner's own region).
 */
export interface BoardQuery {
  category?: ScoreCategory
  scope?: 'region'
  region?: string
}

/**
 * GET /league/month?category=&after=… and GET /league/all-time?category=&after=… : the page of a v2 table after a cursor; 422
 * `invalid_cursor` for a cursor of another table (another month, period, category or region).
 */
export interface LeagueBoardPage {
  rules_version: number
  category: ScoreCategory
  /** The learner has no place in this table (the other category, another region): shown, read-only. */
  read_only: boolean
  rows: BoardRow[]
  /** `after` of the next page; null on the last one. */
  next_cursor: string | null
  /** "All time" (docs/32 §12.11); absent on a page of a month. */
  period?: 'all_time'
  /** "All time" and a table of a region: the scope and the region shown (null: the whole category). Absent on a page of a whole month. */
  scope?: BoardScopeName
  region?: BoardRegion | null
}

export interface MonthlyMe {
  /** null while the learner has no place yet. */
  rank: number | null
  points: number
  active_days: number
  participant: boolean
  /** Whether the learner takes part in the prize draw; `missing` says what is not enough yet. */
  eligible: boolean
  missing: { active_days: number; account_days: number }
  /** Why the learner has no place (docs/34 §3): `opted_out`, `blocked`; null for a participant. */
  not_counted_reason: 'opted_out' | 'blocked' | null
  /** A v2 month (docs/32 §8.3): the learner's table, the tie-break fact, the points to the next place (null: leader / no place). */
  category?: ScoreCategory | null
  long_words?: number
  next_gap?: number | null
  /** A v2 month: the place in the region of the learner's month (docs/35 §2.4); null without a region; absent on an older server. */
  region?: BoardMyRegion | null
}

export interface MonthlyWinner {
  place: number
  name: string | null
  points: number
}

export interface MonthlyLastMonth {
  starts_on: string
  ends_on: string
  status: MonthlyRatingStatus
  /** A v2 month: the winners of the table shown now (`category` of the answer). */
  winners: MonthlyWinner[]
  me: { rank: number; points: number; category?: ScoreCategory | null } | null
  /** A v2 month: every table under its category; absent in a v1 month. */
  winners_by_category?: { free: MonthlyWinner[]; pro: MonthlyWinner[] }
}

export interface MonthlyRatingResponse {
  enabled: boolean
  opted_out: boolean
  month: {
    starts_on: string
    ends_on: string
    closes_at: string
    days_left: number
    /** null: the month has no row yet (nobody has earned anything). */
    status: MonthlyRatingStatus | null
    participants: number
  }
  rules: MonthlyRatingRules
  /** `[]`: no prizes are announced, the block is not shown. */
  prizes: MonthlyPrize[]
  top: MonthlyTopRow[]
  /** null: the learner has not studied this month yet. */
  me: MonthlyMe | null
  last_month: MonthlyLastMonth | null
  /**
   * A month of the word points v2 (docs/32 §8.3); every key below is absent in a v1 month. `month.participants`, `prizes` and `top`
   * are those of the table shown (`category`: `?category=` or the learner's own).
   */
  rules_version?: number
  category?: ScoreCategory
  my_category?: ScoreCategory
  /** The other table: no place of the learner in it, no neighbours. */
  read_only?: boolean
  /** `?after=` of the page after the top; null: the top is the whole table. */
  next_cursor?: string | null
  /** Two rows above and below the learner in their own table; null: not shown (other table, no place). */
  neighbours?: { above: BoardRow[]; below: BoardRow[] } | null
  /** A v2 month (docs/35 §2.4): `region` - the table of a region shown (`?scope=region`), null for the whole category; no prizes there. */
  scope?: BoardScopeName
  region?: BoardRegion | null
}

/** GET /league/all-time (docs/04 A.19a, docs/32 §8.3, §12.11): the learner's row of the category shown. */
export interface AllTimeMe {
  category: ScoreCategory
  /** null while not a participant (opted out, blocked). */
  rank: number | null
  points: number
  long_words: number
  active_days: number
  participant: boolean
  not_counted_reason: 'opted_out' | 'blocked' | null
  first_day: string
  last_day: string
  /** Points to the next place of the table shown; null: leader, or no place in it. */
  next_gap: number | null
  /** The place in the learner's current region; null without a region. */
  region: BoardMyRegion | null
}

/**
 * GET /league/all-time?category=&scope=&region= . `enabled: false` (the word points not live): no other key. 422 `region_not_set`
 * for `scope=region` without `region` from a learner without one, `invalid_cursor` for a cursor of another table.
 */
export interface AllTimeResponse {
  enabled: boolean
  period: 'all_time'
  rules_version?: number
  /** `Y-m-d`: the points are counted from this day ("Учёт с …"). */
  counted_since?: string | null
  category?: ScoreCategory
  /** The learner's table now: the category of their current month, else of their plan. */
  my_category?: ScoreCategory
  /** The learner has no place in the table shown. */
  read_only?: boolean
  opted_out?: boolean
  scope?: BoardScopeName
  region?: BoardRegion | null
  participants?: number
  top?: BoardRow[]
  next_cursor?: string | null
  neighbours?: { above: BoardRow[]; below: BoardRow[] } | null
  /** null: no points in the category shown. */
  me?: AllTimeMe | null
  /** The learner's own sum over both tables (the opt-out does not hide it from them); a category without points - null. */
  my_total?: { points: number; free: number | null; pro: number | null }
}

/** GET /league/month/me: the light variant for the finish screen. */
export interface MonthlyRatingMeResponse {
  enabled: boolean
  month: { starts_on: string; ends_on: string; days_left: number }
  me: MonthlyMe | null
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
  /** The friend's emoji of Pro (docs/31 §5а) while his Pro is live; absent on an older server. */
  pro_emoji?: string | null
  streak: number
  words_learned: number
  since: string | null
  /** The asset of the camel the friend wears; the key is there only while the game is open to the learner (docs/05 §5b.6). */
  camel_asset?: string | null
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
  /** The word is seen, its meaning picked among four (docs/06 §12.1, rung 1); every plan. */
  meaning: boolean
  reverse: boolean
  audio: boolean
  /** Pro feature: typed answers are served only with `exercise.spelling`. */
  spelling: boolean
  /** Pro feature. */
  gap_fill: boolean
}

/** Body of PATCH /me/settings: only the fields to change; `reminder_time: null` turns the reminders off. */
export type SettingsPatch = Partial<
  Pick<LearnerSettings, 'interface_locale' | 'reminder_time' | 'daily_goal' | 'goal' | 'level' | 'leagues_opt_out' | 'retention_mode'>
> & {
  exercises?: Partial<ExerciseSettings>
  /** the region (null = "rather not say"); `region_via` says which screen chose it, for the product event only */
  region_code?: string | null
  region_via?: 'settings' | 'prompt'
  /** the CODE of an emoji of Pro; null takes it back */
  pro_emoji?: string | null
}

/** The FSRS target retention the learner picked (docs/06 §12.8); shown only to learners scheduled by FSRS. */
export type RetentionMode = 'calm' | 'normal' | 'intense'

/** PUT /words/{word}/note — the learner's own memory hook of a word (docs/06 §12.3). */
export interface WordNoteResponse {
  word_id: number
  note: string | null
}

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
   * "Pro ended" (docs/04 A.2–A.2a): the learner is on Free and their own paid subscription expired
   * lately; null = nothing to offer. Absent in the auth answer.
   */
  lapsed?: Lapsed | null
}

export interface Lapsed {
  /** `trial` = the welcome trial of a new learner ended (the other values are paid subscriptions). */
  provider: string
  /** ISO 8601 moment the subscription expired; also the key of the local "dismissed" mark. */
  ended_at: string
}

export interface Renewal {
  /** The auto-renewal is running (Telegram Stars, status `active`). */
  auto: boolean
  /** `trial` = the welcome Pro of a new learner; otherwise a paid subscription (`telegram_stars`, `payme`, ...). */
  provider: string
  /** null = no end. */
  ends_at: string | null
  /** A "renew soon" reminder was already sent for the current period end. */
  reminder_sent: boolean
}

// ---- billing (docs/04 A.8) ----

export type BillingProviderKey = 'telegram_stars' | 'payme' | 'click'
/** `quarter` = 90 days (Stars and sums, learners only); a period this build does not know is skipped by the screens (`orderedPeriods`). */
export type BillingPeriod = 'month' | 'quarter' | 'year'
/** Prices are minor units: XTR = whole Stars, UZS = tiyin (÷ 100 to show). */
export type BillingCurrency = 'XTR' | 'UZS'
export type Prices = Partial<Record<BillingCurrency, number>>

export interface CatalogItem {
  plan: string
  tier: string | null
  period: BillingPeriod
  /** Length of the period in days; absent on an older server. */
  days?: number
  /** After the open offer. */
  prices: Prices
  /** Before it. */
  list_prices: Prices
  /**
   * Whole percent cheaper than the same time in months at the month's list price (0 for the month); absent on an older server,
   * then the screens work it out from `list_prices` (`periodSavingPct`).
   */
  saving_pct?: number
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
export type DoneReason =
  | 'lesson_complete'
  | 'nothing_due'
  | 'new_quota_exhausted'
  | 'session_cap'
  | 'sprint_complete'
  | 'hard_round_complete'
  /** `lesson_review` (docs/06 §15): every word of the set answered right or out of serves. */
  | 'lesson_review_complete'

/**
 * Free new-word quota after the step (docs/04 A.3); `new_words_remaining` null = no limit applies (Pro, or a group
 * lesson, also for Free). It says nothing about the plan: that comes only from `/me.entitlement`.
 */
export interface Quota {
  new_words_remaining: number | null
}

/**
 * `lesson_review` (docs/04 A.3, docs/06 §15): the review of a lesson studied today - the words met today, objective exercises only;
 * it moves no schedule and brings no points, goal or streak (step 2 of the day plan).
 */
export type TrainingMode = 'group_lesson' | 'lesson' | 'daily_review' | 'hard_review' | 'lesson_review'

/** Where a personal session was opened from (stored for analytics). */
export type SessionSource = 'self' | 'picker' | 'reminder' | 'reminder_quiz' | 'onboarding' | 'assignment' | 'finish' | 'plan'

export interface TrainingStartResponse {
  session_id: number
  mode: TrainingMode
  /** When the session began, server time (ISO-8601, UTC); absent on an older server. */
  started_at?: string
  /** null for `daily_review`: the scope spans every lesson. */
  lesson: LessonHeader | null
  total_words: number
  card?: Card | null
  progress?: { done: number; total: number }
  done_reason?: DoneReason | null
  /** The daily round ended and one more can be played (only with `done_reason = 'sprint_complete'`); absent on an older server. */
  can_continue?: boolean
  quota?: Quota
}

/** Body of POST /training/sessions. */
export interface OpenSessionRequest {
  /**
   * `hard_review`: the round of hard words (docs/06 §14) - spans the learner's words, takes no `lesson_id`.
   * `lesson_review`: the review of a lesson studied today (docs/06 §15) - needs `lesson_id`; 409 `lesson_review_unavailable`
   * `{reason: no_new_words | no_objective_exercise | disabled}` when it cannot open.
   */
  mode: 'lesson' | 'daily_review' | 'hard_review' | 'lesson_review'
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
  /**
   * What held today (docs/04 A.2a): `goal`, `game` (only a run of Karvon - "Streak held by a run"), `freeze`; null while `met_today` is false.
   * Absent on an older server.
   */
  held_by?: 'goal' | 'game' | 'freeze' | null
}

/** Answer of POST /training/sessions/{id}/finish. `goal` / `streak`: state of the whole day, not of the session. */
export interface SessionSummary {
  /** When the session began, server time (ISO-8601, UTC); absent on an older server. */
  started_at?: string
  reviewed: number
  correct: number
  new_words: number
  goal?: DailyGoal
  streak?: Streak
  /** League points earned by this session (docs/13 §7.6); absent on an older server. */
  league_points?: number
  /** Where "Practice of the lesson / course" leads (docs/16 §11.1); null: no entry (daily review, group session, no access). */
  practice_entry?: { course: string; lesson_id: number | null } | null
  /** Growth of the words in the session (docs/06 §13.3); absent on an older server. */
  stages?: SessionStages
  /** The round of hard words (docs/06 §14): how many of its set were won; null / absent in every other mode. */
  hard_round?: { total: number; won: number } | null
  /** One mistake of an earlier session corrected in the round of hard words (docs/06 §14); null / absent: none, or another mode. */
  corrected?: CorrectedMistake | null
  /** The invitation to the round of hard words after a daily round / a lesson; null: none (the round itself, the feature is off). */
  hard_round_offer?: HardRound | null
  /** The review of a lesson (docs/06 §15): words of its set answered right / on the first serve; null / absent in every other mode. */
  lesson_review?: { total: number; won: number; first_try: number } | null
  /** The word points of the session (docs/32 §8.2); absent outside the v2 rules (then `league_points` is the v1 formula). */
  score_session?: SessionScore
}

/** "Today N of 100" (docs/32 §8.2): the rating day as it stands; `month` only when the monthly count differs. */
export interface ScoreTodayCount {
  points: number
  /** null: the count is on the old rules. */
  cap: number | null
  category: ScoreCategory
  new: [number, number | null]
  review: [number, number | null]
  /** What is left of the cap today; null without a cap. */
  remaining: number | null
}

export interface ScoreToday extends ScoreTodayCount {
  /** Midnight of the rating day (Tashkent), ISO 8601 UTC: "the rating day ends at 22:00". */
  day_ends_at: string
  month: ScoreTodayCount | null
}

/** What a training session brought in word points (docs/32 §8.2). */
export interface SessionScore {
  /** By the rules, before the limits ("+70 for the session"). */
  earned: number
  week_added: number
  month_added: number
  /** How much the week's sum of the best days grew ("+40 to the weekly rating"). */
  week_rating_delta: number
  /** What the limits of the day cut off. */
  capped_off: number
  /** The rating day after the session; null: no credit today. */
  today: ScoreToday | null
}

/** A word failed in an earlier session (objective exercise) and answered right at its first grade in the round of hard words. */
export interface CorrectedMistake {
  word_id: number
  word: string
  /** In the learner's language (the ru fallback when the word has no translation in it). */
  translation: string
  /** When the earlier mistake was made (ISO-8601, UTC). */
  mistaken_at: string
  /** What the learner typed then (spelling / gap-fill), when it is safe to show; null otherwise. */
  answer: string | null
}

export type HardRoundReason = 'not_enough' | 'played_today' | 'no_objective_exercise'

/** The round of hard words as `/today` and the finish summary tell it (docs/04 A.2a); `hard_round: null` there means the feature is off. */
export interface HardRound {
  available: boolean
  reason: HardRoundReason | null
  /** How many hard words wait (the set of the round is at most 10 of them). */
  words: number
  /** A round that was opened and not finished: "Continue". */
  open_session_id: number | null
  /** When the next round opens after `played_today` (ISO-8601). */
  resets_at: string | null
}

export interface SessionStages {
  /** Words entered for the first time (left the minute steps). */
  graduated: number
  /** Words that climbed a stage. */
  promoted: number
  /** Words that became learned. */
  mastered: number
}

export interface TodayLesson {
  id: number
  stage: number | null
  lesson: number
  title: string | null
  /** The lesson's words never served to the learner ("5 new words"); absent on an older server. */
  words_left?: number
}

/** Step 2 of the day plan: the review of the lesson studied today (`POST /training/sessions {mode: 'lesson_review', lesson_id}`). */
export interface DayPlanTraining {
  lesson_id: number
  /** The slug of the lesson's course - not necessarily the active one (an assignment, a course switched since); absent on an older server. */
  course?: string | null
  /** In the learner's language; null when the lesson is not in the active course (a deck, an assignment). */
  title: string | null
  /** Words the review asks (met today, at most 20). */
  words: number
  /** A review of this lesson was played to its end today. */
  done: boolean
  /** A live review of this lesson to continue ("Continue"). */
  open_session_id: number | null
}

/** Step 3 of the day plan: the game on the words of the lesson (`POST /practice/sessions {mode: 'pairs_timed', lesson_id, source: 'deeplink'}`). */
export interface DayPlanGame {
  lesson_id: number
  /** The slug of the lesson's course: the practice hub opens there (`/practice/{course}?lesson=`); absent on an older server. */
  course?: string | null
  mode: 'pairs_timed'
  /** A practice session on this lesson was begun today and answered at least once. */
  played: boolean
}

/** `plan` of GET /today (docs/04 A.2a, docs/31 §5б): lesson -> review of the lesson -> game on its words. */
export interface DayPlan {
  /** The step to do now; null - nothing to suggest (the chain is done, or there is no lesson to start). */
  step: 1 | 2 | 3 | null
  /** The chain of today is complete. */
  done: boolean
  lesson: {
    /** A lesson with new words was played to its end today (`lesson_id` - that lesson). */
    done: boolean
    lesson_id: number | null
    /** = `active_course.next_lesson.id`. */
    next_lesson_id: number | null
    words_left: number | null
  }
  /** null until a lesson is done today, or while the review is switched off. */
  training: DayPlanTraining | null
  /** null until a lesson is done today, or while the game is not open to the learner. */
  game: DayPlanGame | null
}

export interface TodayCourse {
  id: number
  slug: string
  title: string
  progress: { learned: number; total: number }
  /** null once every word of the course has been served. */
  next_lesson: TodayLesson | null
  /** Readiness for the level (docs/04 A.20): null while the feature is off; absent on an older server. */
  readiness?: TodayReadiness | null
}

/** "Test of the week" of a course as `/today` and the readiness screen say it. */
export interface ReadinessWeek {
  correct: number
  total: number
  percent: number
}

/** `active_course.readiness` of GET /today: the compact block of Home, no extra request. */
export interface TodayReadiness {
  /** `A1–A2`, `B1`; null = the course names no level. */
  level_label: string | null
  percent: number
  words: { total: number; learned: number; mastered: number; in_progress: number; not_started: number }
  quiz: { enabled: false } | { enabled: true; week_start: string; this_week: ReadinessWeek | null }
}

/** GET /courses/{slug}/readiness (docs/04 A.20). */
export interface ReadinessResponse {
  course: { id: number; slug: string; title: string; level: string | null; level_label: string | null }
  percent: number
  words: { total: number; learned: number; mastered: number; known: number; in_progress: number; not_started: number }
  thresholds: { known_days: number; mastered_days: number }
  modules: ReadinessModule[]
  quiz: ReadinessQuiz
}

export interface ReadinessModule {
  number: number
  title: string | null
  learned: number
  total: number
}

export interface ReadinessHistoryItem {
  /** Monday of the week, `Y-m-d`. */
  week_start: string
  correct: number
  total: number
  percent: number
}

export type ReadinessQuiz =
  | { enabled: false }
  | {
      enabled: true
      questions: number
      seconds: number
      week_start: string
      this_week: (ReadinessWeek & { finished_at: string }) | null
      /** Scored results, oldest first. */
      history: ReadinessHistoryItem[]
      best_percent: number | null
    }

/** Where the readiness screen was opened from (the server's `readiness.viewed` event). */
export type ReadinessSource = 'today' | 'profile' | 'course'

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
  entitlement: Pick<Entitlement, 'plan' | 'source' | 'until' | 'lapsed'>
  onboarding_completed: boolean
  /** The learner's place in this week's league group (docs/13 §7.3); null while not placed. */
  league: TodayLeague | null
  /**
   * IANA id of the learner's time zone (`Asia/Tashkent`): the day of `goal` and `streak` is counted in it, so the
   * header of Home dates itself by it, not by the device (docs/04 A.2a). Always a non-empty string.
   */
  timezone: string
  /** The entry of Karvon (docs/04 A.2a, G2.3): always there; a closed game says `{daily_available: false, active_run: null}`. */
  game?: TodayGame
  /** The practice access without its day limits (docs/04 A.2a): the "Word games" card reads it, `/me` is not asked. Absent: an older server (no practice). */
  practice?: PracticeAccessToday
  /** The round of hard words (docs/06 §14); null: the feature is off; absent: an older server. */
  hard_round?: HardRound | null
  /**
   * Whether `GET /assignments` can have anything (docs/04 A.2a): `false` - surely not (in no group of a center), the list is not asked
   * and Home is not held for it; `true` - maybe; absent: an older server (the list is asked as before).
   */
  assignments_possible?: boolean
  /** The plan of the day (docs/31 §5б); absent on an older server. */
  plan?: DayPlan | null
  /** "Today N of 100" of the word points (docs/32 §8.2); absent outside the v2 rules. */
  score_today?: ScoreToday
}

export interface TodayGame {
  /** The game is open to this learner (the standing entry of the map); absent: an older server - no entry. */
  available?: boolean
  daily_available: boolean
  active_run: GameActiveRun | null
  /** The class caravan that is on (docs/05 §5b.7); `null` or absent: none. */
  class_caravan?: GameClassCard | null
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
 * How the card is presented and who grades it (docs/13 §2.5, docs/04 A.3, docs/06 §12.2):
 * flash → POST /review {quality}; meaning / reverse / audio / pretest → POST /review {answer: the picked option};
 * spelling / gap_fill → POST /review {answer: the typed text}.
 */
export type ExerciseType = 'flash' | 'meaning' | 'reverse' | 'audio' | 'spelling' | 'gap_fill' | 'pretest'

/**
 * The exercises this build of the Mini App can show. A newer server may serve a `prompt.type` outside this list: such a
 * card must not be shown as a flash card (its answer is hidden), the screen asks to update instead (TrainingView).
 */
export const EXERCISE_TYPES: readonly ExerciseType[] = ['flash', 'meaning', 'reverse', 'audio', 'spelling', 'gap_fill', 'pretest']

export interface ExercisePrompt {
  type: ExerciseType
  /** reverse / spelling / gap_fill: the translation; flash / meaning / pretest: the word; audio: '' (the word is only heard). */
  front: string
  /** gap_fill: the example with the word's form replaced by `____`. */
  masked_example: string | null
  /** reverse: four English words; meaning / audio / pretest: four translations (the right one among them). */
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
  /** The translation of exactly that example, in the session's language (the ru one when it has none); null when there is none or the example is hidden. */
  example_translation?: string | null
  /**
   * The pre-generated mp3 of exactly that example (docs/26 §9.11): only the anchor example is voiced, so null for a rotated
   * one, a stale file, an own deck or a hidden example. No Web Speech for it: null → no button. Absent on an older server.
   */
  example_audio_url?: string | null
  transcription: string | null
  /** Pre-generated mp3 (docs/12 task 1.2c); absent/null → Web Speech, else silence (docs/26 §9.9: `GET /tts` is gone). */
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
  /** A "hard word" (docs/06 §12.6): failed `learning.leech.threshold` times as a graduated card. */
  is_leech?: boolean
  /** Failed and not yet passed a day-based review (docs/06 §3.5). */
  is_hard?: boolean
  /** The authored memory hook in the card's language; present on a new, failed or hard card only (docs/06 §12.3). */
  hook?: string | null
  /** The learner's own hook ("my hint"). */
  note?: string | null
  /** The word a hard word is mixed up with (docs/06 §12.6). */
  confused_with?: { word: string; translation: string | null } | null
  /** A phrase card (docs/35 §3.3 d, docs/06 §16); null for a word, absent on an older server. */
  phrase?: PhraseInfo | null
}

/** The labels of a phrase card: kind, register, UK / US and «Буквально: …» (null while the card hides its answer). */
export interface PhraseInfo {
  kind: 'formula' | 'fixed' | 'idiom' | 'phrasal_verb' | 'proverb' | null
  register: 'neutral' | 'informal' | 'formal' | 'slang' | 'rude' | null
  variant: 'both' | 'uk' | 'us'
  literal: string | null
}

export interface NextCardResponse {
  card: Card | null
  progress: { done: number; total: number }
  done_reason?: DoneReason | null
  /** The daily round ended and one more can be played (only with `done_reason = 'sprint_complete'`); absent on an older server. */
  can_continue?: boolean
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

/**
 * A meaning / reverse / audio card is sent WITHOUT what gives the right option away (docs/06 §12.2): reverse — `word`,
 * `example`, `transcription`, `audio_url`; meaning / audio — `translation`, `example`; all — `hook`, `note`, `confused_with`.
 * The answer of POST /review brings them back here, for the screen after the pick.
 */
export interface Revealed {
  word: string
  translation: string
  /** The right option exactly as it stands among the options (reverse: the word; meaning / audio: the translation). */
  correct: string
  example: string | null
  example_translation?: string | null
  /** The mp3 of exactly `example` (docs/26 §9.11); null → no button. Absent on an older server. */
  example_audio_url?: string | null
  transcription: string | null
  audio_url: string | null
  hook: string | null
  note: string | null
  confused_with: { word: string; translation: string | null } | null
  /** The phrase labels with the literal translation; null for a word, absent on an older server. */
  phrase?: PhraseInfo | null
}

/** What the server said about a pick of a meaning / reverse / audio card: the screen colours the options by it. */
export interface ChoiceResult {
  correct: boolean
  /** The right option (`Revealed.correct`; `Graded.expected` when the server sent no `revealed`). */
  right: string
  revealed: Revealed | null
}

export interface ReviewResponse {
  next_review_at: string | null
  new_interval_days: number
  easiness_factor: number
  /** null when the client graded (quality was sent). */
  graded?: Graded | null
  /** meaning / reverse / audio only: what the card was sent without (see {@link Revealed}); null otherwise. */
  revealed?: Revealed | null
  card?: Card | null
  progress?: { done: number; total: number }
  done_reason?: DoneReason | null
  /** The daily round ended and one more can be played (only with `done_reason = 'sprint_complete'`); absent on an older server. */
  can_continue?: boolean
  quota?: Quota
  /** The word points of the answer (docs/32 §8.1); null while they are off / shadow, absent on an older server. Not shown yet. */
  score?: WordScore | null
}

/** One count (weekly or monthly) of the rating day after an answer (docs/32 §8.1 `score.today`). */
export interface WordScoreDay {
  points: number
  /** null while the count is on the old rules. */
  cap: number | null
  category: 'free' | 'pro'
  /** [used, limit] of the day; the limit is null on the old rules. */
  new: [number, number | null]
  review: [number, number | null]
}

/** The `score` block of the answer of the training and of the game (docs/32 §8.1). */
export interface WordScore {
  kind: 'new' | 'review' | 'long' | null
  reason: string
  /** The points of the kind by the rules (0 when nothing was credited). */
  base: number
  /** What the weekly / monthly count really got (limits and the day cap applied). */
  week: number
  month: number
  /** The weekly count of the day; null when the answer credited nothing. */
  today: WordScoreDay | null
  /** The monthly count, only when it differs from the weekly one. */
  today_month: WordScoreDay | null
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

export type ExamMode = 'group_exam' | 'personal_mock' | 'level_quiz'

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

/** POST /courses/{slug}/level-quiz (docs/04 A.20): the test of the week, new (201) or the one that is on (200). */
export interface LevelQuizSession {
  session_id: number
  mode: 'level_quiz'
  course_id: number
  week_start: string
  /** true = the first test of the week (it is recorded); false = practice, not recorded. */
  scored: boolean
  started_at: string | null
  ends_at: string | null
  total_questions: number
  seconds: number
  resumed: boolean
}

/** GET /exam/sessions/{id}/result of a `level_quiz` session. */
export interface LevelQuizResultResponse {
  mode: 'level_quiz'
  correct: number
  total: number
  answered: number
  percent: number
  scored: boolean
  week_start: string
  course_id: number
  course_slug: string
}

export type AnyExamResultResponse = ExamResultResponse | MockExamResultResponse | LevelQuizResultResponse

export function isMockExamResult(result: AnyExamResultResponse): result is MockExamResultResponse {
  return 'mode' in result && result.mode === 'personal_mock'
}

export function isLevelQuizResult(result: AnyExamResultResponse): result is LevelQuizResultResponse {
  return 'mode' in result && result.mode === 'level_quiz'
}

// ---------------- Course catalog ----------------

export interface Progress {
  done: number
  total: number
}

export type CourseSection = 'courses' | 'phrases'

/** Row of GET /courses and the answer of POST /me/course. Texts are localized by the server. */
export interface Course {
  slug: string
  title: string
  description: string | null
  level: string | null
  exam_tag: string | null
  /** The section of the catalog (docs/35 §3.3 a): `phrases` - a course of phrases, `courses` - every other. */
  section?: CourseSection
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
  section?: CourseSection
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

/** The modes this client knows. A newer server may send others: the screens drop them (`isKnownPracticeMode`). */
export type PracticeMode = 'pairs' | 'collocation' | 'sentence' | 'dictation' | 'pairs_timed' | 'word_form' | 'error_hunt'

/** `kind` of an element a screen draws: it equals the mode except for pairs, whose element is one round. */
export type PracticeElementKind = 'pairs_round' | 'collocation' | 'sentence' | 'dictation' | 'word_form' | 'error_hunt'

/** What the Free plan counts per day (docs/18 §3, G1): the sessions of practice and the battles the learner starts. */
export type PracticeLimitKind = 'sessions' | 'battles'

/** One counter of today. `resets_at` is the end of the learner's "today" (ISO 8601, UTC): from then on the counter is full again. */
export interface PracticeLimit {
  limit: number
  used: number
  remaining: number
  resets_at: string
}

/**
 * The Free limits of today, in `practice.limits` of GET /me, `limits` of GET /practice/hub and of the 201 answers that create a session
 * or a battle (the value AFTER the creation). `null` as a whole: Pro, no limit at all, or practice is closed. A repeat of mistakes spends
 * nothing; accepting a battle is never limited.
 *
 * Each kind can be `null` on its own: an empty env on the server means that kind has no limit (`{sessions: null, battles: {...}}` or the
 * other way round). Nothing is shown for such a kind and it is never stopped.
 */
export interface PracticeLimits {
  sessions: PracticeLimit | null
  battles: PracticeLimit | null
}

/** `me.game.limits` (docs/18 §12.7.2): `runs` is null where there is no limit of runs (Pro); the whole block is null until G2.8 and for a closed game. */
export interface GameLimits {
  runs: PracticeLimit | null
  tickets: { balance: number; max: number }
  boosters: PracticeLimit | null
  boss_stages: number | null
}

/** The `game` block of GET /me (docs/18 §12.8). */
export interface GameAccess {
  available: boolean
  limits: GameLimits | null
  pro_promise: 'none' | 'full'
  coins: number
  tickets: number
}

export type GameBossState = 'locked_by_plan' | 'not_ready' | 'ready' | 'defeated'

export interface GameMapStop {
  lesson_id: number
  number: number
  title: string | null
  unlocked: boolean
  /** 0-3. */
  stars: number
  best_score: number
  runs: number
}

export interface GameMapBoss {
  state: GameBossState
  defeated_at: string | null
  introduced: number
  required: number
  cleared_stops: number
  required_stops: number
}

export interface GameMapCity {
  stage_id: number
  number: number
  /** The name on the Silk Road ("Samarkand"). */
  city: string
  /** The module's name; null = the client says "Module N". */
  title: string | null
  unlocked: boolean
  stops: GameMapStop[]
  boss: GameMapBoss
}

export interface GameMapFriend {
  friendship_id: number
  name: string
  city_number: number
  stops_cleared: number
  camel_asset: string | null
}

export interface GameActiveRun {
  id: number
  kind: string
  progress: { answered: number; total: number }
}

/** The caravan of the day on the map (docs/04 A.18): `available` = not played, no run on, something due. */
export interface GameMapDaily {
  available: boolean
  done_today: boolean
  due_count: number
  /** The start of the learner's next local day. */
  resets_at: string
}

/** GET /game/map (docs/04 A.18). `class_caravan`: the caravan of the class that is on its way (docs/05 §5b.7). */
export interface GameMapResponse {
  course: { slug: string; title: string }
  cities: GameMapCity[]
  me: { stops_cleared: number; cities_cleared: number; coins: number; tickets: number }
  /** The caravan of the day; absent on an older server. */
  daily?: GameMapDaily | null
  friends: GameMapFriend[]
  active_run: GameActiveRun | null
  /** The card of the class caravan that is on; `null` or absent (an older server): none. */
  class_caravan?: GameClassCard | null
  limits: GameLimits | null
}

/** `POST /game/runs` (201): the map needs the id (the run screen reads the rest itself) and the limits AFTER this start (a run of the day or a ticket is spent). */
export interface GameRunStarted {
  id: number
  limits?: GameLimits | null
}

export type GameStartRequest =
  | { kind: 'stop'; course: string; lesson_id: number; replace_active?: boolean }
  /** The caravan of the day: the course is the active one, the server needs neither course nor lesson. */
  | { kind: 'daily'; replace_active?: boolean }
  /** The boss of a city (docs/05 §5b.8): the course and the stage of the map. */
  | { kind: 'boss'; course: string; stage_id: number; replace_active?: boolean }
  /** The boss of the class (docs/05 §5b.7): no course, no lesson. */
  | GameClassBossStartRequest

/** `details` of 403 `practice_limit_reached`: which counter is spent, with its numbers, and when it is full again. */
export interface PracticeLimitHit {
  kind: PracticeLimitKind
  limit: number
  used: number
  resets_at: string
}

/**
 * What the "what Pro gives" list may say about practice (docs/18 §3), decided by the server for this learner (`ProPromises`, the rule the
 * landing and the Stars invoice follow too): `both` - practice and battles without a daily limit, `solo` - practice alone, `none` - no line.
 */
export type ProPromise = 'none' | 'solo' | 'both'

/** Block `practice` of GET /me (docs/16 §3.3): `available` false hides every entry; `pro_required` shows them with a Pro chip. */
export interface PracticeAccess {
  available: boolean
  pro_required: boolean
  /** The modes switched on. */
  modes: PracticeMode[]
  battle: boolean
  /** The line of the Pro list about practice: the server says which one (or none); nothing is worked out from the flags above. */
  pro_promise: ProPromise
  /** The Free limits of today; absent: an older server (nothing is shown, nothing is blocked). */
  limits?: PracticeLimits | null
}

/** `practice` of GET /today: the block of `/me` without `limits`. */
export type PracticeAccessToday = Omit<PracticeAccess, 'limits'>

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
  /** Only the timed pairs (`pairs_timed`): the personal best, null before the first game. Absent on the other cards. */
  record?: { score: number; achieved_at: string } | null
}

/**
 * The unfinished solo session (one per learner): where it is by id AND by name, so that "Continue" can say which one it is even for a place
 * this run has not seen (docs/16 §7.1; the same fields are in `details` of the 409 `practice_session_active`, with `session_id` for `id`).
 */
export interface PracticeActiveSession {
  id: number
  mode: PracticeMode
  course: string
  /** The title of the course in the language of the session (the slug when the server has no title). */
  course_title: string
  lesson_id: number | null
  /** Module, number and title of the lesson; null for the practice of a whole course, and when the server has no names for it. */
  lesson: Omit<PracticeLessonRef, 'id'> | null
  progress: Progress
}

/** GET /practice/hub (docs/16 §7.1). */
export interface PracticeHubResponse {
  course: PracticeCourseRef
  lesson: PracticeLessonRef | null
  modes: PracticeModeInfo[]
  active_session: PracticeActiveSession | null
  battle: { enabled: boolean; active_battle_id: number | null; incoming_count: number }
  /** The Free limits of today (`null`: Pro, or no limit); absent: an older server. */
  limits?: PracticeLimits | null
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

/** "Word form": a sentence with one blank (`___`) and the base word to make the needed form of; the learner types it. */
export interface WordFormElement {
  id: string
  kind: 'word_form'
  /** With one blank: `We need to make a ___ by Friday.` */
  text: string
  /** The word the form is made of: `decide`. */
  base: string
}

/** Where the learner looked for the mistake (`located` of an "Error hunt" after its first step). */
export interface ErrorHuntLocated {
  /** The word the learner pressed. */
  token_id: string
  /** The pressed word is the wrong one. */
  ok: boolean
  /** The wrong word (told only once the first step is taken). */
  error_token_id: string
}

/**
 * "Error hunt": a sentence with one wrong word, in TWO answers (docs/16 §5.7). Step `locate`: the words of the sentence (in their order),
 * the learner sends `{token_id}`; `located` and `options` are null. Step `fix`: the same words, where the learner looked and where the
 * mistake is (`located`), and the corrections to pick from (`options`, sent `{option_id}`). The second step comes after a wrong first one too.
 */
export interface ErrorHuntElement {
  id: string
  kind: 'error_hunt'
  step: 'locate' | 'fix'
  tokens: PracticeChoice[]
  located: ErrorHuntLocated | null
  options: PracticeChoice[] | null
}

export type PracticeElement = PairsRoundElement | CollocationElement | SentenceElement | DictationElement | WordFormElement | ErrorHuntElement

/** What the learner sends for one element. `pairs` is the battle form (the whole set at once); `token_id` is the first step of an "Error hunt". */
export type PracticeAnswerPayload =
  | { left_id: string; right_id: string }
  | { option_id: string }
  | { token_id: string }
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

export interface WordFormReveal {
  /** The canonical form. */
  expected: string
  /** Other accepted forms. */
  alternatives: string[]
  your_text: string | null
  /** The learner typed the base word itself: "another form of the word is needed". */
  same_as_base: boolean
  /** The sentence with the form in its blank. */
  full_text: string
  translation: string | null
  explanation: string | null
}

/** The reply to the first step of an "Error hunt" (`element_done: false`): whether the mistake was found. */
export interface ErrorHuntLocateReveal {
  step: 'locate'
  located_ok: boolean
  your_token_id: string
  error_token_id: string
}

/** The review of a whole "Error hunt" (after `fix`, or a skip on any step: then what was not done is null / false). */
export interface ErrorHuntReveal {
  step: 'fix'
  located_ok: boolean
  fixed_ok: boolean
  your_token_id: string | null
  error_token_id: string
  your_option_id: string | null
  correct_option_ids: string[]
  /** The sentence as it was given, with the mistake. */
  wrong_text: string
  /** The sentence corrected. */
  full_text: string
  translation: string | null
  explanation: string | null
}

/** The explanation shown after an answer; its shape follows the kind of the element answered (pairs have none). */
export type PracticeReveal = DictationReveal | SentenceReveal | CollocationReveal | WordFormReveal | ErrorHuntLocateReveal | ErrorHuntReveal

/** One mistake of the summary: a word of the pairs, a phrase, a sentence. */
export interface PracticeMistake {
  element_id: string
  kind: PracticeElementKind
  label: string
  your_answer: string | null
  verdict: 'typo' | 'wrong' | 'skipped'
}

export type PracticeFinishReason = 'completed' | 'expired' | 'replaced' | 'time_over'

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
  /** Only a timed game (`pairs_timed`): the pairs made, the best score now (null: none), whether this game set the record. */
  score?: number
  best_score?: number | null
  is_record?: boolean
}

/** `timed` of a session: the clock of a timed game, kept by the server (docs/16 §5.5a); null for the other modes. */
export interface PracticeTimed {
  seconds: number
  /** The end of the game, server clock; null until `start`. Moves closer with every wrong match. */
  deadline_at: string | null
  started: boolean
  score: number
  penalty_ms: number
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
  /** A timed game's clock; null (or absent in an older server) for the other modes. */
  timed?: PracticeTimed | null
  /** The element to answer; null once the session is finished, and in a timed game that has not been started (`timed.started === false`): the board comes with `start`. */
  current: PracticeElement | null
  summary: PracticeSummary | null
  /** Only in the 201 of `POST /practice/sessions`: the Free limits after the session was created (`null`: Pro, or no limit). */
  limits?: PracticeLimits | null
}

/** Answer of POST /practice/sessions/{id}/answer. Pairs: `verdict` is correct|wrong, `pair` the match tried, `reveal` null. */
export interface PracticeAnswerResponse {
  verdict: PracticeVerdict
  first_attempt: boolean
  reveal: PracticeReveal | null
  pair: { left_id: string; right_id: string } | null
  /** Pairs: the round is complete. Absent for the other modes. */
  round_done?: boolean
  /** Timed pairs: the time a wrong match cost (ms), 0 for a right one. */
  penalty_ms?: number
  /**
   * A task of several answers ("Error hunt"): false - only a step of it was taken, the task goes on (`session.current` is its next step);
   * true - the task is over. Absent for the other modes.
   */
  element_done?: boolean
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
  /** The emoji of Pro next to the name (docs/31 §5а) while that Pro is live; absent on an older server. */
  pro_emoji?: string | null
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
  /** Only in the 201 of `POST /practice/battles` (a rematch too): the Free limits after the battle was created (`null`: Pro, or no limit). */
  limits?: PracticeLimits | null
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

// ---------------------------------------------------------------------------------------------------------------------------------
// The friends' cup of a Telegram group (docs/04 A.23, docs/33 §7.2; K7). Only the types of the server's answers: the screens are K8.
// Every field the server may add later is optional; the codes of the errors are in `CupErrorCode`.

export type CupStatus = 'draft' | 'scheduled' | 'running' | 'finished' | 'canceled'
export type CupPause = 'bot_removed' | 'blocked'
export type CupParticipantStatus = 'active' | 'left'
export type CupTodayState = 'not_started' | 'active' | 'done' | 'not_available'

/**
 * The codes of `error.code` of the routes of the cup. 403 `cup_not_member` is also "no such cup" (one answer); 404
 * `cup_round_not_found` is also "a stranger's attempt"; 403 `cup_unavailable` - the cup is switched off or not open for the chat.
 * `details`: `cup_paused {reason}`, `cup_unverifiable {reason: bot_not_admin | telegram_unavailable}`, `cup_round_done {points}`,
 * `cup_round_expired {points}`, `element_not_current {current}`, `answer_already_accepted {verdict}`,
 * `cup_display_name_invalid {reason: CupDisplayNameReason}`.
 */
export type CupErrorCode =
  | 'cup_unavailable'
  | 'cup_not_member'
  | 'cup_not_chat_admin'
  | 'cup_round_not_found'
  | 'cup_paused'
  | 'cup_unverifiable'
  | 'cup_bot_not_admin'
  | 'cup_already_active'
  | 'cup_not_open'
  | 'cup_already_published'
  | 'cup_not_started'
  | 'cup_round_unavailable'
  | 'cup_round_done'
  | 'cup_round_expired'
  | 'element_not_current'
  | 'answer_already_accepted'
  | 'cup_rename_limit'
  | 'cup_closed'
  | 'cup_material_unavailable'
  | 'cup_display_name_invalid'
  | 'cup_level_invalid'
  | 'invalid_answer_shape'
  | 'unknown_element_id'
  | 'unknown_option_id'
  | 'cup_check_busy'

/**
 * Why a name in the cup was refused (422 `cup_display_name_invalid`, `error.details.reason`): `empty`, `too_long` (over 32 graphemes
 * or 64 code points), `marks` (more than 2 combining marks in a row), `link` (`://`, `www.`, a dot between a letter or digit and a
 * letter - `site.com`, `t.me/x`, `J.R`), `mention` (`@` at the start of a word), `command` (`/` at the start of a word), `tag` (`#word`,
 * `$ABC`). The last four Telegram would make a link of in the chat.
 */
export type CupDisplayNameReason = 'empty' | 'too_long' | 'marks' | 'link' | 'mention' | 'command' | 'tag'

/** The rules of a cup: 2 points for a clean right answer (a typo is 0), the best `counted_days` days count. */
export interface CupRules {
  points_per_answer: number
  typo_points: number
  max_day_points: number
  counted_days: number
  days: number
  max_total: number
}

/** The fields of a cup every answer shares. Times are ISO 8601 UTC; the days are local midnights of `timezone`. */
export interface CupFields {
  id: number
  status: CupStatus
  group_title: string
  level: string
  days: number
  counted_days: number
  round_tasks: number
  round_minutes: number
  timezone: string
  starts_at: string | null
  ends_at: string | null
  /** The day of now while the cup runs (1…days), else null. */
  day: number | null
  summaries_enabled: boolean
  /** `HH:MM` in `timezone`. */
  summary_time: string
  paused: CupPause | null
  /**
   * When the next round opens (ISO 8601 UTC, decided by the server - the local midnight of `timezone`, a change of the clocks
   * included): day 1 before the start, the next day while the cup runs; null on the last day, in a draft and in a closed cup.
   */
  next_day_at?: string | null
}

/** GET /cups/by-code/{code}: the invitation of the link of the chat. */
export interface CupInvite extends CupFields {
  participants_count: number
  rules: CupRules
  me: {
    joined: boolean
    participant_id: number | null
    status: CupParticipantStatus | null
    /** His name in the cup, or the name he would join with (the Telegram first name). */
    display_name: string | null
    left: boolean
  }
}

/** POST /cups/{id}/join (201 new / 200 already in) and PATCH /cups/{id}/me. */
export interface CupParticipantView {
  participant_id: number
  cup_id: number
  display_name: string
  status: CupParticipantStatus
  first_day: number
  renames_left: number
}

/** GET /cups: the open cups first, then the last ten finished or canceled. */
export interface CupListResponse {
  cups: Array<
    CupFields & {
      me: {
        participant_id: number
        display_name: string
        status: CupParticipantStatus
        total: number
        days_played: number
        final_rank: number | null
        /** The round of today (`not_available`: the cup does not run, or he joined later). */
        today?: { state: CupTodayState; day: number | null }
      } | null
    }
  >
}

/** One row of the table of a cup. */
export interface CupStandingRow {
  participant_id: number
  rank: number
  display_name: string
  /** The emoji of Pro next to the name (docs/31 §5а), null without a live Pro. */
  pro_emoji: string | null
  total: number
  round_points: number
  days_played: number
  /** Left the chat: shown as "вышел(а)". */
  left: boolean
  me: boolean
}

/** The state of the round of today (GET /cups/{id}/rounds/today without `server_now`, and `me.today` of the table). */
export interface CupToday {
  state: CupTodayState
  day: number | null
  attempt_id: number | null
  points: number | null
  expires_at: string | null
}

export interface CupDayResult {
  day: number
  points: number
  round_points: number
  duel_points: number
  played: boolean
  /** The day counts in the total (the best `counted_days`). */
  counted: boolean
  /** The participant may play it (`day >= first_day`). */
  available: boolean
}

/** GET /cups/{id}: the table and my day. `me` is null for a member of the chat who has not joined. */
export interface CupBoardResponse {
  cup: CupFields & { rules: CupRules }
  standings: CupStandingRow[]
  me: {
    participant_id: number
    display_name: string
    status: CupParticipantStatus
    first_day: number
    renames_left: number
    total: number
    final_rank: number | null
    today: CupToday
    days: CupDayResult[]
  } | null
  /** A hint for the link to the settings; the server asks Telegram again on every action of an administrator. */
  can_manage: boolean
  server_now: string
}

/** GET /cups/{id}/rounds/today. */
export interface CupTodayResponse extends CupToday {
  server_now: string
}

/** A task of the round: the projection of the game - never the right answer. Options are in the participant's own order. */
export interface CupTask {
  position: number
  id: string
  kind: 'translate_fwd' | 'translate_back' | 'listen' | 'spell' | 'gap'
  prompt?: string
  /** `translate_fwd`: the word has a recording; `listen`: always true. */
  audio?: boolean
  /** `listen`: the clip of the task (the route of the attempt). */
  audio_url?: string
  options?: Array<{ id: string; text: string }>
  answer_length?: number
  masked?: string
  translation?: string
}

export interface CupReviewEntry {
  element_id: string
  kind: CupTask['kind']
  verdict: string | null
  points: number
  answer: Record<string, unknown> | null
  reveal: Record<string, unknown>
  audio_url?: string
}

/** POST /cups/{id}/rounds/today (201 / 200) and GET /cup-rounds/{attempt}: the attempt of the day. */
export interface CupRound {
  attempt_id: number
  cup_id: number
  participant_id: number
  day: number
  status: 'active' | 'finished'
  finish_reason: 'completed' | 'expired' | null
  total: number
  answered: number
  correct: number
  points: number
  max_points: number
  position: number
  started_at: string
  /** The deadline fixed at the start; a reload does not move it. The timer counts against `server_now`. */
  expires_at: string
  server_now: string
  current: CupTask | null
  answers: Array<{ element_id: string; verdict: string; points: number }>
  /** Only once the attempt is over: the right answer of every task. */
  review: CupReviewEntry[] | null
  /** When the round of the next day opens (the end of this attempt's day); null on the last day. */
  next_day_at?: string | null
}

/** Body of POST /cup-rounds/{attempt}/answer: `{option_id}`, `{text}` or `{skip: true}`. */
export interface CupAnswerRequest {
  request_id: string
  element_id: string
  answer: { option_id?: string; text?: string; skip?: boolean }
}

/** Answer of POST /cup-rounds/{attempt}/answer; a repeated `request_id` answers the same (`replayed: true`). */
export interface CupAnswerResponse {
  verdict: 'correct' | 'typo' | 'wrong' | 'skipped' | string
  points: number
  /** The right answer of the task just answered. */
  reveal: Record<string, unknown> | null
  finished: boolean
  total_points: number
  replayed: boolean
  round: CupRound
}

/** The screens of an administrator of the chat: setup, publish, settings, cancel. */
export interface CupSetup extends CupFields {
  locale: string
  summary_minute: number
  cancel_reason: 'draft_expired' | 'chat_admin' | 'platform_admin' | 'no_players' | null
  options: { levels: string[]; locales: string[] }
  rules: CupRules
}

/** Body of PATCH /cups/{id}/settings: `level` and `locale` in a draft only. */
export interface CupSettingsPatch {
  level?: string
  locale?: string
  summaries_enabled?: boolean
  /** The minute of the local day, 0…1439. */
  summary_minute?: number
}
