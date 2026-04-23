import { createApiClient } from './client'
import { useAuthStore } from '@/stores/auth'
import type {
  AuthResponse,
  ExamAnswerResponse,
  ExamJoinResponse,
  ExamQuestionResponse,
  ExamResultResponse,
  MeResponse,
  NextCardResponse,
  Quality,
  ReviewResponse,
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
    authenticate: (initData: string) =>
      client().postPublic<AuthResponse>('/auth', { init_data: initData }),
  },
  me: {
    get: () => client().get<MeResponse>('/me'),
  },
  training: {
    start: (sessionId: number) =>
      client().post<TrainingStartResponse>(`/training/sessions/${sessionId}/start`),
    next: (sessionId: number) =>
      client().get<NextCardResponse>(`/training/sessions/${sessionId}/next`),
    review: (sessionId: number, wordId: number, quality: Quality, timeSpentMs: number) =>
      client().post<ReviewResponse>(`/training/sessions/${sessionId}/review`, {
        word_id: wordId,
        quality,
        time_spent_ms: timeSpentMs,
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
