import { createApiClient } from './client'
import { useAuthStore } from '@/stores/auth'
import type {
  AchievementsResponse,
  AssignmentsResponse,
  AuthContext,
  AuthResponse,
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
  NextCardResponse,
  OrderStatusResponse,
  OrganizationsResponse,
  OpenSessionRequest,
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
} from './types'

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
      await auth.login().catch(() => {})
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
  },
  today: {
    get: () => client().get<TodayResponse>('/today'),
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
    ) =>
      client().post<ReviewResponse>(`/training/sessions/${sessionId}/review`, {
        word_id: wordId,
        quality,
        time_spent_ms: timeSpentMs,
        serve_token: serveToken,
      }),
    /** A typed answer to a server-graded card (spelling / gap_fill): the server sets the quality. */
    answer: (
      sessionId: number,
      wordId: number,
      answer: string,
      timeSpentMs: number,
      serveToken: string | null = null,
    ) =>
      client().post<ReviewResponse>(`/training/sessions/${sessionId}/review`, {
        word_id: wordId,
        answer,
        time_spent_ms: timeSpentMs,
        serve_token: serveToken,
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
}
