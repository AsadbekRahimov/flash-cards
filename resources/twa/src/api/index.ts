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
  MockExamOpenResponse,
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
  LeagueResponse,
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
  me: {
    get: () => client().get<MeResponse>('/me'),
    updateSettings: (patch: SettingsPatch) => client().patch<MeResponse>('/me/settings', { ...patch }),
    revertTimezone: () => client().post<MeResponse>('/me/settings/timezone/revert'),
    /** "Delete account": soft delete with the explicit confirmation; the token stops working at once. */
    /** The "account restored" sheet was shown; idempotent, 204. */
    accountRestoredSeen: () => client().post<void>('/me/account-restored/seen'),
    deleteAccount: () => client().post<AccountDeletedResponse>('/me/delete', { confirm: true }),
  },
  today: {
    get: () => client().get<TodayResponse>('/today'),
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
  },
  achievements: {
    list: () => client().get<AchievementsResponse>('/achievements'),
    /** The learner opened the share dialog of an unlocked key; answers with the refreshed list. */
    shared: (key: string) => client().post<AchievementsResponse>(`/achievements/${encodeURIComponent(key)}/shared`),
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
    checkout: (period: BillingPeriod, provider: BillingProviderKey) =>
      client().post<CheckoutResponse>('/billing/checkout', { plan: 'pro', period, provider }),
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
}
