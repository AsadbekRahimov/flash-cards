import { createApiClient } from './client'
import { useAuthStore } from '@/stores/auth'
import type {
  AuthContext,
  AuthResponse,
  Course,
  CourseDetail,
  CoursesResponse,
  ExamAnswerResponse,
  ExamJoinResponse,
  ExamQuestionResponse,
  ExamResultResponse,
  MeResponse,
  NextCardResponse,
  OpenSessionRequest,
  Quality,
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
  },
  today: {
    get: () => client().get<TodayResponse>('/today'),
  },
  promo: {
    redeem: (code: string) => client().post<MeResponse>('/promo/redeem', { code }),
  },
  courses: {
    list: () => client().get<CoursesResponse>('/courses'),
    get: (slug: string) => client().get<CourseDetail>(`/courses/${encodeURIComponent(slug)}`),
    select: (slug: string) => client().post<Course>('/me/course', { slug }),
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
      client().get<ExamResultResponse>(`/exam/sessions/${sessionId}/result`),
  },
}
