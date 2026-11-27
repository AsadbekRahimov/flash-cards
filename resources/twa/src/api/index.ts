import { createApiClient } from './client'
import { useAuthStore } from '@/stores/auth'
import type {
  AchievementsResponse,
  ExerciseType,
  GameMapResponse,
  GameRunStarted,
  GameStartRequest,
  AssignmentsResponse,
  AuthContext,
  AuthResponse,
  Battle,
  BattleAnswerRequest,
  BattleAnswerResponse,
  BattleAudioFailedRequest,
  BattleAudioFailedResponse,
  BattleInviteRequest,
  BattleListResponse,
  BattleOptionsResponse,
  BattlePoll,
  BattleRound,
  BillingCatalog,
  BillingPeriod,
  BillingProviderKey,
  CheckoutResponse,
  CupAnswerRequest,
  CupAnswerResponse,
  CupBoardResponse,
  CupInvite,
  CupListResponse,
  CupParticipantView,
  CupLeaveResponse,
  CupRound,
  CupSettingsPatch,
  CupSetup,
  CupTodayResponse,
  CupDuel,
  CupDuelChallengeRequest,
  CupDuelListResponse,
  Course,
  CourseDetail,
  CoursesResponse,
  Deck,
  DeckDetail,
  DeckImportFormat,
  DeckImportResult,
  DecksResponse,
  AnyExamResultResponse,
  ExamAnswerResponse,
  ExamJoinResponse,
  ExamQuestionResponse,
  LevelQuizSession,
  MockExamOpenResponse,
  ReadinessResponse,
  ReadinessSource,
  MeResponse,
  AccountDeletedResponse,
  NextCardResponse,
  OrderStatusResponse,
  OrganizationsResponse,
  OpenSessionRequest,
  PracticeAnswerRequest,
  PracticeAnswerResponse,
  PracticeHubResponse,
  PracticeOpenRequest,
  PracticeReplaceRequest,
  PracticeSession,
  Quality,
  ReferralsResponse,
  LeagueBoardPage,
  LeagueResponse,
  MonthlyRatingResponse,
  MonthlyRatingMeResponse,
  AllTimeResponse,
  BoardQuery,
  FriendsResponse,
  ChallengesResponse,
  ChallengeItem,
  ChallengeMetric,
  ReviewResponse,
  SessionSummary,
  SettingsPatch,
  TodayResponse,
  TrainingStartResponse,
  WordNoteResponse,
} from './types'
import type {
  GameAnswerRequest,
  GameAnswerResponse,
  GameHintRequest,
  GameHintResponse,
  GameRunView,
} from './gameRunTypes'
import type { GameClass, GameClassList } from './gameClassTypes'
import type { ShareEventRequest, ShareKind, SharePrepared, StoryShare, StoryShareKind } from './shareTypes'
import type {
  OnboardingCompleteResponse,
  OnboardingSavedStep,
  OnboardingState,
  OnboardingStepBodies,
  PlacementResult,
  PlacementTest,
} from './onboardingTypes'
import type { VocabularyPage, VocabularyQuery } from './vocabularyTypes'
import type { OfferPlacement } from '@/composables/offerViewed'
import type { ClientTimingPayload } from '@/composables/useTimingMarks'
import type { GameBoosterRequest, GameBuyRequest, GameEquipRequest, GameProfile, GameShop, GameWallet } from './gameShopTypes'

/**
 * How long a READ of a battle (the poll, the battle, a round, the list, the options) may take: past it the request is given up like a
 * lost connection (`network`, code `timeout`) and the poll slows down. Without a limit a request on a dead connection hangs, the poll
 * (which does not start another beside one that is on its way) goes quiet, and the server counts the silence against the player.
 */
export const BATTLE_READ_TIMEOUT_MS = 8_000
/** How long an ACTION of a battle (an answer, "Ready", an invitation, the report of a clip) may take: the same, a little more patient. */
export const BATTLE_ACTION_TIMEOUT_MS = 10_000

const READ = { timeoutMs: BATTLE_READ_TIMEOUT_MS }
const ACT = { timeoutMs: BATTLE_ACTION_TIMEOUT_MS }

/**
 * Lazy-built singleton API client. Constructed on first access so Pinia
 * is already installed when `useAuthStore()` is called.
 */
let clientInstance: ReturnType<typeof createApiClient> | null = null

function client() {
  if (clientInstance) return clientInstance
  const auth = useAuthStore()
  clientInstance = createApiClient({
    getToken: () => auth.token,
    onUnauthorized: async () => {
      try {
        await auth.login()
      } catch {
        return ''
      }
      return auth.token
    },
  })
  return clientInstance
}

export const api = {
  auth: {
    authenticate: (initData: string, context: AuthContext | null) =>
      client().postPublic<AuthResponse>('/auth', {
        init_data: initData,
        ...(context ? { context: { type: context.type, session_id: context.sessionId } } : {}),
      }),
  },
  telemetry: {
    /** The timing marks of a sampled open (docs/04 A.21): `keepalive`, no token refresh, no retry; the caller swallows a failure. */
    clientTiming: (payload: ClientTimingPayload) => client().postKeepalive('/client-timing', { ...payload }),
  },
  me: {
    get: () => client().get<MeResponse>('/me'),
    updateSettings: (patch: SettingsPatch) => client().patch<MeResponse>('/me/settings', { ...patch }),
    /** Takes back the last change of the region within 10 minutes (docs/35 §2.3); 422 `region_revert_unavailable` later. */
    revertRegion: () => client().post<MeResponse>('/me/settings/region/revert'),
    /** "Not now" on the region card of the Rating tab: hidden for 30 days; idempotent, 204. */
    dismissRegionPrompt: () => client().post<void>('/me/region-prompt/dismiss'),
    /** "Delete account": soft delete with the explicit confirmation; the token stops working at once. */
    /** The "account restored" sheet was shown; idempotent, 204. */
    accountRestoredSeen: () => client().post<void>('/me/account-restored/seen'),
    deleteAccount: () => client().post<AccountDeletedResponse>('/me/delete', { confirm: true }),
  },
  today: {
    get: () => client().get<TodayResponse>('/today'),
  },
  onboarding: {
    /** The onboarding of the Mini App (docs/04 A.24): the saved answers, the options and the step to show next. */
    get: () => client().get<OnboardingState>('/onboarding'),
    /** Saves one step, in any order; 409 `onboarding_completed`, 422 `display_name_invalid {reason}`, 403 `daily_goal_pro_required {max}`. */
    saveStep: <S extends OnboardingSavedStep>(step: S, body: OnboardingStepBodies[S]) =>
      client().put<OnboardingState>(`/onboarding/steps/${step}`, { ...body }),
    /** Finishes the onboarding (idempotent); 422 `onboarding_incomplete {missing}`. */
    complete: () => client().post<OnboardingCompleteResponse>('/onboarding/complete'),
    /** The placement test of O5 (docs/04 A.27): a new set of questions; 409 `onboarding_completed`, 422 `placement_unavailable`. */
    placement: () => client().post<PlacementTest>('/onboarding/placement'),
    /** The answers of the test, all at once (null - skipped); 410 `placement_expired` (20 min or not started), 409, 422. A repeat answers the same. */
    placementAnswers: (answers: (number | null)[]) => client().post<PlacementResult>('/onboarding/placement/answers', { answers }),
  },
  vocabulary: {
    /**
     * "My dictionary" (docs/04 A.25): one page; `signal` drops a request a newer search has replaced. 422 `invalid_cursor` for a bad
     * `after`, `validation` for `q` / `limit` out of range.
     */
    list: (query: VocabularyQuery, signal?: AbortSignal) =>
      client().get<VocabularyPage>('/vocabulary', { ...query }, signal !== undefined ? { signal } : undefined),
  },
  words: {
    /** The learner's own memory hook of a word they study (docs/06 §12.3); `null` clears it. */
    saveNote: (wordId: number, note: string | null) =>
      client().put<WordNoteResponse>(`/words/${wordId}/note`, { note }),
  },
  assignments: {
    list: () => client().get<AssignmentsResponse>('/assignments'),
  },
  referrals: {
    get: () => client().get<ReferralsResponse>('/referrals'),
  },
  league: {
    get: () => client().get<LeagueResponse>('/league'),
    /**
     * `query`: the table to show (docs/04 A.19, v2): the category and the region; empty - the learner's own category, the whole of it
     * (and the v1 month knows no tables). 422 `region_not_set`, `region_scope_unavailable` for `scope: 'region'`.
     */
    month: (query: BoardQuery = {}) =>
      Object.keys(query).length === 0
        ? client().get<MonthlyRatingResponse>('/league/month')
        : client().get<MonthlyRatingResponse>('/league/month', { ...query }),
    /** The page of a v2 table after `next_cursor`; 422 `invalid_cursor` for a cursor that is not of this table any more. */
    monthPage: (query: BoardQuery, after: string) => client().get<LeagueBoardPage>('/league/month', { ...query, after }),
    /** "All time" (docs/04 A.19a): `{enabled: false}` while the word points are not live; 422 `region_not_set` as the month. */
    allTime: (query: BoardQuery = {}) =>
      Object.keys(query).length === 0
        ? client().get<AllTimeResponse>('/league/all-time')
        : client().get<AllTimeResponse>('/league/all-time', { ...query }),
    allTimePage: (query: BoardQuery, after: string) => client().get<LeagueBoardPage>('/league/all-time', { ...query, after }),
    monthMe: () => client().get<MonthlyRatingMeResponse>('/league/month/me'),
  },
  achievements: {
    list: () => client().get<AchievementsResponse>('/achievements'),
    /** The learner opened the share dialog of an unlocked key; answers with the refreshed list. */
    shared: (key: string) => client().post<AchievementsResponse>(`/achievements/${encodeURIComponent(key)}/shared`),
  },
  share: {
    /** A message "through the bot" with the card and the button (docs/04 A.22); 403/404/422/429/503 -> the plain share dialog. */
    prepare: (kind: ShareKind, subject: string | null) =>
      client().post<SharePrepared>('/share/prepare', subject === null ? { kind } : { kind, subject }),
    /**
     * The 9:16 card of a story (docs/04 A.26, docs/40 §2.4): only on the tap "Share to a story". 403 `share_unavailable` / 404 -> the plain
     * share; 429 `rate_limited` and the network -> "Retry".
     */
    story: (kind: StoryShareKind, subject: string | null) =>
      client().post<StoryShare>('/share/story', subject === null ? { kind } : { kind, subject }),
    /** The client's analytics of a share (204); sent without waiting, a failure is swallowed by the caller. */
    event: (body: ShareEventRequest) => client().post<void>('/share/events', { ...body }),
  },
  friends: {
    get: () => client().get<FriendsResponse>('/friends'),
    /** Each action answers with the refreshed list. */
    accept: (id: number) => client().post<FriendsResponse>(`/friends/${id}/accept`),
    decline: (id: number) => client().post<FriendsResponse>(`/friends/${id}/decline`),
    remove: (id: number) => client().post<FriendsResponse>(`/friends/${id}/remove`),
  },
  challenges: {
    list: () => client().get<ChallengesResponse>('/challenges'),
    /** To a friend, addressed by the friendship id. */
    propose: (friendshipId: number, metric: ChallengeMetric) =>
      client().post<ChallengeItem>('/challenges', { friendship_id: friendshipId, metric }),
    accept: (id: number) => client().post<ChallengeItem>(`/challenges/${id}/accept`),
    decline: (id: number) => client().post<ChallengeItem>(`/challenges/${id}/decline`),
  },
  organizations: {
    list: () => client().get<OrganizationsResponse>('/me/organizations'),
    /** Leaves the center; answers with the refreshed list. */
    leave: (membershipId: number) => client().delete<OrganizationsResponse>(`/me/organizations/${membershipId}`),
  },
  promo: {
    redeem: (code: string) => client().post<MeResponse>('/promo/redeem', { code }),
  },
  billing: {
    catalog: () => client().get<BillingCatalog>('/billing/catalog'),
    /** The amount is the server's; only what to buy and how to pay is sent. */
    checkout: (period: BillingPeriod, provider: BillingProviderKey, placement?: OfferPlacement) =>
      client().post<CheckoutResponse>('/billing/checkout', { plan: 'pro', period, provider, ...(placement ? { placement } : {}) }),
    /** A Pro offer became visible (docs/13 §5.1): 204, counted once a day per placement by the server. */
    offerViewed: (placement: OfferPlacement) => client().post<void>('/billing/offer-viewed', { placement }),
    order: (token: string) => client().get<OrderStatusResponse>(`/billing/orders/${encodeURIComponent(token)}`),
    cancel: () => client().post<MeResponse>('/billing/subscription/cancel'),
    resume: () => client().post<MeResponse>('/billing/subscription/resume'),
  },
  courses: {
    list: () => client().get<CoursesResponse>('/courses'),
    get: (slug: string) => client().get<CourseDetail>(`/courses/${encodeURIComponent(slug)}`),
    select: (slug: string) => client().post<Course>('/me/course', { slug }),
  },
  decks: {
    list: () => client().get<DecksResponse>('/decks'),
    get: (slug: string) => client().get<DeckDetail>(`/decks/${encodeURIComponent(slug)}`),
    create: (title: string) => client().post<Deck>('/decks', { title }),
    rename: (slug: string, title: string) =>
      client().patch<Deck>(`/decks/${encodeURIComponent(slug)}`, { title }),
    /** Archives the deck; the progress stays. */
    remove: (slug: string) => client().delete<void>(`/decks/${encodeURIComponent(slug)}`),
    addWords: (slug: string, text: string, format: DeckImportFormat) =>
      client().post<DeckImportResult>(`/decks/${encodeURIComponent(slug)}/words`, { text, format }),
    removeWord: (slug: string, wordId: number) =>
      client().delete<void>(`/decks/${encodeURIComponent(slug)}/words/${wordId}`),
  },
  training: {
    /** Opens (or returns the already open) personal session; answers like `start`. */
    open: (body: OpenSessionRequest) =>
      client().post<TrainingStartResponse>('/training/sessions', { ...body }),
    start: (sessionId: number) =>
      client().post<TrainingStartResponse>(`/training/sessions/${sessionId}/start`),
    next: (sessionId: number) =>
      client().get<NextCardResponse>(`/training/sessions/${sessionId}/next`),
    finish: (sessionId: number) =>
      client().post<SessionSummary>(`/training/sessions/${sessionId}/finish`),
    review: (
      sessionId: number,
      wordId: number,
      quality: Quality,
      timeSpentMs: number,
      serveToken: string | null = null,
      exercise: ExerciseType | null = null,
    ) =>
      client().post<ReviewResponse>(`/training/sessions/${sessionId}/review`, {
        word_id: wordId,
        quality,
        time_spent_ms: timeSpentMs,
        serve_token: serveToken,
        // The exercise the card was shown with: the server answers 409 `exercise_mismatch` when it serves another one now.
        exercise,
      }),
    /** A typed answer to a server-graded card (spelling / gap_fill): the server sets the quality. */
    answer: (
      sessionId: number,
      wordId: number,
      answer: string,
      timeSpentMs: number,
      serveToken: string | null = null,
      exercise: ExerciseType | null = null,
    ) =>
      client().post<ReviewResponse>(`/training/sessions/${sessionId}/review`, {
        word_id: wordId,
        answer,
        time_spent_ms: timeSpentMs,
        serve_token: serveToken,
        exercise,
      }),
  },
  exam: {
    join: (sessionId: number) =>
      client().post<ExamJoinResponse>(`/exam/sessions/${sessionId}/join`),
    question: (sessionId: number, index: number) =>
      client().get<ExamQuestionResponse>(`/exam/sessions/${sessionId}/question`, { index }),
    answer: (
      sessionId: number,
      body: {
        question_index: number
        word_id: number
        selected_option_index: number | null
        time_spent_ms: number
      },
    ) => client().post<ExamAnswerResponse>(`/exam/sessions/${sessionId}/answer`, body),
    result: (sessionId: number) =>
      client().get<AnyExamResultResponse>(`/exam/sessions/${sessionId}/result`),
    /** IELTS / SAT mock of a course (Pro; 403 `exam_simulator_pro_required` is the paywall). */
    openMock: (slug: string) =>
      client().post<MockExamOpenResponse>(`/courses/${encodeURIComponent(slug)}/mock-exam`),
    /** The test of the week (docs/04 A.20); free for everybody. 201 new, 200 the one that is on. */
    openLevelQuiz: (slug: string) =>
      client().post<LevelQuizSession>(`/courses/${encodeURIComponent(slug)}/level-quiz`),
  },
  readiness: {
    /** Readiness for the level of a course (docs/04 A.20); `source` is the screen the learner came from (analytics). */
    get: (slug: string, source: ReadinessSource) =>
      client().get<ReadinessResponse>(`/courses/${encodeURIComponent(slug)}/readiness`, { source }),
  },
  gameRuns: {
    /** A run of "Karvon" as the server has it (the restart of an interrupted screen, the re-read after a conflict). 410 `game_run_closed`: it is over. */
    get: (id: number) => client().get<GameRunView>(`/game/runs/${id}`),
    /** One answer; a repeat with the same `request_id` returns the first result. */
    answer: (id: number, body: GameAnswerRequest) => client().post<GameAnswerResponse>(`/game/runs/${id}/answer`, { ...body }, ACT),
    /** The hint of the bazaar: coins for options taken away or the first letters. */
    hint: (id: number, body: GameHintRequest) => client().post<GameHintResponse>(`/game/runs/${id}/hint`, { ...body }, ACT),
    /** A booster of the stock on the run (`hint`: the current element; the others: the whole run). The reply is the run; a repeat with the same `request_id` costs nothing. */
    booster: (id: number, body: GameBoosterRequest) => client().post<GameRunView>(`/game/runs/${id}/booster`, { ...body }, ACT),
    /** "Leave the run": it does not count (finished / abandoned). */
    abandon: (id: number) => client().post<GameRunView>(`/game/runs/${id}/abandon`, { confirm: true }, ACT),
    /** The clip of an element (`audio_url` of its projection): fetched with the token, played from a blob. */
    audioBlob: (audioUrl: string, signal?: AbortSignal) => client().getBlob(audioUrl, signal),
  },
  practice: {
    /** The hub of a course (or of one lesson of it): four modes, the unfinished session, the battle badge. */
    hub: (course: string, lessonId: number | null = null) =>
      client().get<PracticeHubResponse>('/practice/hub', { course, ...(lessonId !== null ? { lesson_id: lessonId } : {}) }),
    /** Opens a solo session (201). 409 `practice_session_active` carries the unfinished one in `details`. */
    open: (body: PracticeOpenRequest) => client().post<PracticeSession>('/practice/sessions', { ...body }),
    get: (id: number) => client().get<PracticeSession>(`/practice/sessions/${id}`),
    /** Starts the clock of a timed game (no body); idempotent: a second call returns the same deadline. */
    start: (id: number) => client().post<PracticeSession>(`/practice/sessions/${id}/start`),
    /** One answer; a repeat with the same `request_id` returns the first result. */
    answer: (id: number, body: PracticeAnswerRequest) =>
      client().post<PracticeAnswerResponse>(`/practice/sessions/${id}/answer`, { ...body }),
    /** Swaps a dictation whose clip does not play, without a penalty (docs/16 §10.4). */
    replace: (id: number, body: PracticeReplaceRequest) =>
      client().post<PracticeSession>(`/practice/sessions/${id}/replace`, { ...body }),
    /**
     * The clip of a dictation: `audioUrl` is the `audio_url` of the element; fetched with the token, played from a blob.
     * Gives up after 15 s (a `network` error); `signal` stops it sooner - the screen was left, a newer clip is wanted.
     */
    audioBlob: (audioUrl: string, signal?: AbortSignal) => client().getBlob(audioUrl, signal),
  },
  game: {
    /** The map of a course: cities (modules), stops (lessons), bosses, friends, the unfinished run. 404 `course_not_found`, 403 `game_unavailable`. */
    map: (course?: string) => client().get<GameMapResponse>('/game/map', course ? { course } : undefined),
    /** Starts a run of a stop (201). 409 `game_run_active` carries the unfinished run in `details`. */
    startRun: (body: GameStartRequest) => client().post<GameRunStarted>('/game/runs', { ...body }, ACT),
    /** The card of a city that was reached was shared (docs/04 A.18): once, a repeat answers the same. 404 `stage_not_found`. */
    cityShared: (stageId: number) => client().post<{ stage_id: number; shared_at: string | null }>(`/game/cities/${stageId}/shared`),
  },
  gameClass: {
    /** The caravans of the learner's classes as short rows. 403 `game_unavailable`. */
    list: () => client().get<GameClassList>('/game/class'),
    /** One caravan with the table of contributions. 404 `class_caravan_not_found`, 403 `game_unavailable`. */
    show: (id: number) => client().get<GameClass>(`/game/class/${id}`),
  },
  gameShop: {
    /** The coins, the tickets and the last entries of the ledger. 403 `game_unavailable`. */
    wallet: () => client().get<GameWallet>('/game/wallet'),
    /** The catalogue with what the learner owns, has in stock and wears, and the balance. */
    shop: () => client().get<GameShop>('/game/shop'),
    /** Buys an item (the reply is the wallet after it); a repeat with the same `request_id` charges once. 404 `item_not_found`, 409 `item_not_purchasable` / `item_already_owned` / `coins_insufficient`. */
    buy: (key: string, body: GameBuyRequest) => client().post<GameWallet>(`/game/shop/${encodeURIComponent(key)}/buy`, { ...body }),
    /** What the learner wears and how far they have come. */
    profile: () => client().get<GameProfile>('/game/profile'),
    /** Puts an item on (`item_key`) or takes the slot's item off (`null`); the reply is the profile. 404 `item_not_found`. */
    equip: (body: GameEquipRequest) => client().post<GameProfile>('/game/profile/equip', { ...body }),
  },
  battles: {
    options: (friendshipId: number, course: string, lessonId: number | null = null) =>
      client().get<BattleOptionsResponse>(
        '/practice/battles/options',
        {
          friendship_id: friendshipId,
          course,
          ...(lessonId !== null ? { lesson_id: lessonId } : {}),
        },
        READ,
      ),
    list: () => client().get<BattleListResponse>('/practice/battles', undefined, READ),
    invite: (body: BattleInviteRequest) => client().post<Battle>('/practice/battles', { ...body }, ACT),
    get: (id: number) => client().get<Battle>(`/practice/battles/${id}`, undefined, READ),
    /**
     * The heartbeat of a battle: read every two seconds while the room or the match is on screen. After a battle is over it is
     * also the cheap way to ask whether a rematch has been offered (it carries `rematch`, and runs outside the `twa-api` budget).
     */
    poll: (id: number) => client().get<BattlePoll>(`/practice/battles/${id}/poll`, undefined, READ),
    round: (id: number, no: number) => client().get<BattleRound>(`/practice/battles/${id}/round`, { no }, READ),
    accept: (id: number) => client().post<Battle>(`/practice/battles/${id}/accept`, undefined, ACT),
    decline: (id: number) => client().post<Battle>(`/practice/battles/${id}/decline`, undefined, ACT),
    cancel: (id: number) => client().post<Battle>(`/practice/battles/${id}/cancel`, undefined, ACT),
    ready: (id: number) => client().post<Battle>(`/practice/battles/${id}/ready`, undefined, ACT),
    surrender: (id: number) => client().post<Battle>(`/practice/battles/${id}/surrender`, { confirm: true }, ACT),
    answer: (id: number, body: BattleAnswerRequest) =>
      client().post<BattleAnswerResponse>(`/practice/battles/${id}/answer`, { ...body }, ACT),
    audioFailed: (id: number, body: BattleAudioFailedRequest) =>
      client().post<BattleAudioFailedResponse>(`/practice/battles/${id}/audio-failed`, { ...body }, ACT),
    /** A clip of a dictation round (`audio_url` of the element, or one of `audio_preload` in the room). */
    audioBlob: (audioUrl: string) => client().getBlob(audioUrl),
  },
  /**
   * The friends' cup of a group (docs/04 A.23). By id every refusal of a stranger is 403 `cup_not_member` (also "no such cup"); a
   * stranger's attempt is 404 `cup_round_not_found`; 403 `cup_unavailable` - the cup is switched off.
   */
  cups: {
    /** The invitation of the link of the chat. 403 `cup_not_member`; 409 `cup_paused` / `cup_unverifiable`; 503; 429 `cup_check_busy`. */
    invite: (code: string) => client().get<CupInvite>(`/cups/by-code/${encodeURIComponent(code)}`),
    /** My cups: the open first, then the last ten over. */
    list: () => client().get<CupListResponse>('/cups'),
    /** The table, my day and my days. */
    show: (id: number) => client().get<CupBoardResponse>(`/cups/${id}`),
    /** Joins (201) or answers the row already there (200). 409 `cup_not_open`, 410 `cup_closed`, 422 `cup_display_name_invalid`. */
    join: (id: number, displayName?: string) =>
      client().post<CupParticipantView>(`/cups/${id}/join`, displayName !== undefined ? { display_name: displayName } : {}),
    /** The name in the tournament, at most 3 changes: 409 `cup_rename_limit`. */
    rename: (id: number, displayName: string) => client().patch<CupParticipantView>(`/cups/${id}/me`, { display_name: displayName }),
    /** Leaves the cup for good (docs/33 §11.12): 200, a repeat too; 403 `cup_not_member`; 410 `cup_closed`. */
    leave: (id: number) => client().post<CupLeaveResponse>(`/cups/${id}/leave`, undefined, ACT),
    today: (id: number) => client().get<CupTodayResponse>(`/cups/${id}/rounds/today`),
    /** Starts the round of today (201) or returns the open one (200). 409 `cup_round_done {points}` / `cup_not_started` / `cup_paused`. */
    startRound: (id: number) => client().post<CupRound>(`/cups/${id}/rounds/today`, undefined, ACT),
    /** Resumes an attempt: the same tasks, the original deadline. */
    round: (attemptId: number) => client().get<CupRound>(`/cup-rounds/${attemptId}`, undefined, READ),
    /** 409 `answer_already_accepted` / `element_not_current` / `cup_round_expired`; 410 `cup_closed`. */
    answer: (attemptId: number, body: CupAnswerRequest) => client().post<CupAnswerResponse>(`/cup-rounds/${attemptId}/answer`, { ...body }, ACT),
    /** The clip of a `listen` task (`audio_url` of the task). */
    audioBlob: (audioUrl: string, signal?: AbortSignal) => client().getBlob(audioUrl, signal),
    /** The setup of a draft by an administrator of the chat. 403 `cup_not_chat_admin`. */
    setup: (code: string) => client().get<CupSetup>(`/cups/setup/${encodeURIComponent(code)}`),
    /** 409 `cup_bot_not_admin`; 422 `cup_level_invalid` / `cup_material_unavailable`. */
    publish: (code: string, body: { level?: string; locale?: string } = {}) =>
      client().post<CupSetup>(`/cups/setup/${encodeURIComponent(code)}/publish`, { ...body }),
    settings: (id: number, patch: CupSettingsPatch) => client().patch<CupSetup>(`/cups/${id}/settings`, { ...patch }),
    cancel: (id: number) => client().post<CupSetup>(`/cups/${id}/cancel`),
    /**
     * The duels of a cup (docs/04 A.23d). 409 `cup_duel_disabled` while off; 403 `cup_not_member` (also no cup, a participant who
     * left); 404 `cup_duel_not_found` (also a duel of another cup or addressed to somebody else).
     */
    duels: {
      /** Challenges to me, open ones to anybody, mine, my duels of today, and whether I may challenge. */
      list: (id: number) => client().get<CupDuelListResponse>(`/cups/${id}/duels`),
      /** One duel: the screen of a challenge and the poll of the waiting screen (every 3 s; `battle_id` appears once accepted). */
      show: (id: number, duelId: number) => client().get<CupDuel>(`/cups/${id}/duels/${duelId}`, undefined, READ),
      /**
       * Challenges (201) or answers the same `request_id` again (200). 409 `cup_duel_already_open {duel_id}`, `cup_duel_limit {scope,
       * limit}`, `cup_paused`, `cup_not_started`, `cup_round_unavailable`; 403 `cup_duel_unavailable`, `practice_limit_reached`; 410
       * `cup_closed`; 422 `cup_duel_self`, `cup_duel_mode_invalid`.
       */
      challenge: (id: number, body: CupDuelChallengeRequest) => client().post<CupDuel>(`/cups/${id}/duels`, { ...body }, ACT),
      /**
       * Accepts (201; 200 accepted before): go to `/battles/{battle_id}` at once. 409 `cup_duel_rating_changed {rated}` (show the new
       * status, accept again with it), `cup_duel_closed {status, reason}`, `cup_duel_own`, `battle_busy {battle_id}`,
       * `cup_duel_opponent_busy`; 403 `cup_duel_unavailable`; 422 `battle_material_unavailable`.
       */
      accept: (id: number, duelId: number, expectRated: boolean) =>
        client().post<CupDuel>(`/cups/${id}/duels/${duelId}/accept`, { expect_rated: expectRated }, ACT),
      /** The one challenged says no. 403 `cup_duel_not_recipient`; 409 `cup_duel_closed`. */
      decline: (id: number, duelId: number) => client().post<CupDuel>(`/cups/${id}/duels/${duelId}/decline`, undefined, ACT),
      /** The challenger withdraws. 409 `cup_duel_closed`. */
      cancel: (id: number, duelId: number) => client().post<CupDuel>(`/cups/${id}/duels/${duelId}/cancel`, undefined, ACT),
    },
  },
}
