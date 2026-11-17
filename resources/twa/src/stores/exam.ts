import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { t } from '@/i18n'
import { errorMessage } from '@/i18n/errors'
import {
  isLevelQuizResult,
  isMockExamResult,
  type ExamAnswerResponse,
  type ExamLeaderboardEntry,
  type ExamMode,
  type ExamQuestionResponse,
  type LevelQuizResultResponse,
  type MockExamResultResponse,
} from '@/api/types'

/** The 403 of `POST /courses/{slug}/mock-exam` for a Free learner: show the paywall, not an error. */
const MOCK_PAYWALL_CODE = 'exam_simulator_pro_required'

export type ExamStatus =
  | 'idle'
  | 'joining'
  | 'loading'       // fetching next question
  | 'answering'     // waiting for learner to click an option
  | 'submitting'    // POST /answer in flight
  | 'feedback'      // showing correct/incorrect highlight
  | 'finished'
  | 'closed'
  | 'error'

export interface ExamStoreState {
  generation: number
  sessionId: number | null
  /** `personal_mock` (docs/13 §8.4): one timer for the whole exam, percent + percentile at the end. */
  mode: ExamMode
  endsAt: number | null               // epoch ms
  totalQuestions: number
  secondsPerQuestion: number

  currentIndex: number
  currentQuestion: ExamQuestionResponse | null
  questionStartedAt: number           // epoch ms
  selectedIndex: number | null
  lastAnswer: ExamAnswerResponse | null
  totalScore: number

  status: ExamStatus
  errorMessage: string

  // Final results
  finalScore: number
  finalRank: number | null
  totalParticipants: number
  leaderboard: ExamLeaderboardEntry[]
  /** Result of a personal mock (null for group exams). */
  mockResult: MockExamResultResponse | null
  /** Result of the test of the week (null otherwise). */
  quizResult: LevelQuizResultResponse | null

  // Opening a personal mock from the course screen
  openingMock: boolean
  /** The server refused the mock with the Pro paywall reason. */
  mockPaywall: boolean
  mockError: string

  // Opening the test of the week from the readiness screen
  openingQuiz: boolean
  /** Why the test could not be opened (localized); '' = no failure. */
  quizError: string
}

export const useExamStore = defineStore('exam', {
  state: (): ExamStoreState => ({
    generation: 0,
    sessionId: null,
    mode: 'group_exam',
    endsAt: null,
    totalQuestions: 0,
    secondsPerQuestion: 10,

    currentIndex: 0,
    currentQuestion: null,
    questionStartedAt: 0,
    selectedIndex: null,
    lastAnswer: null,
    totalScore: 0,

    status: 'idle',
    errorMessage: '',

    finalScore: 0,
    finalRank: null,
    totalParticipants: 0,
    leaderboard: [],
    mockResult: null,
    quizResult: null,

    openingMock: false,
    mockPaywall: false,
    mockError: '',
    openingQuiz: false,
    quizError: '',
  }),

  getters: {
    isMock: (s): boolean => s.mode === 'personal_mock',
    isQuiz: (s): boolean => s.mode === 'level_quiz',
    /** One timer for the whole test (a mock and the test of the week), no per-question countdown. */
    singleTimer: (s): boolean => s.mode === 'personal_mock' || s.mode === 'level_quiz',
  },

  actions: {
    /**
     * Opens (or resumes) the learner's mock of a course; returns the session id to navigate to,
     * null when refused: `mockPaywall` for a Free learner, `mockError` for anything else.
     */
    async openMock(slug: string): Promise<number | null> {
      if (this.openingMock) return null
      this.openingMock = true
      this.mockPaywall = false
      this.mockError = ''
      try {
        const res = await api.exam.openMock(slug)
        return res.session_id
      } catch (err) {
        if (err instanceof ApiClientError && err.code === MOCK_PAYWALL_CODE) {
          this.mockPaywall = true
        } else {
          this.mockError = errorMessage(err, 'exam')
        }
        return null
      } finally {
        this.openingMock = false
      }
    },

    /**
     * Opens (or resumes) the test of the week of a course; returns the session id to navigate to, null when refused
     * (`quizError` says why: too few words / answer options, the feature off).
     */
    async openLevelQuiz(slug: string): Promise<number | null> {
      if (this.openingQuiz) return null
      this.openingQuiz = true
      this.quizError = ''
      try {
        const res = await api.exam.openLevelQuiz(slug)
        return res.session_id
      } catch (err) {
        // Too little material is explained in the words of the test of the week; the rest by the usual error codes.
        if (err instanceof ApiClientError && err.code === 'not_enough_words') this.quizError = t('readiness.not_enough_words')
        else if (err instanceof ApiClientError && err.code === 'not_enough_distractors') this.quizError = t('readiness.not_enough_distractors')
        else this.quizError = errorMessage(err, 'exam')
        return null
      } finally {
        this.openingQuiz = false
      }
    },

    clearQuizError(): void {
      this.quizError = ''
    },

    dismissMockPaywall(): void {
      this.mockPaywall = false
    },

    clearMockError(): void {
      this.mockError = ''
      this.mockPaywall = false
    },

    async join(sessionId: number, mode: ExamMode = 'group_exam'): Promise<void> {
      this.resetForSession(sessionId)
      const generation = this.generation
      this.mode = mode
      this.status = 'joining'
      try {
        const res = await api.exam.join(sessionId)
        if (generation !== this.generation) return
        this.endsAt = res.ends_at ? Date.parse(res.ends_at) : null
        this.totalQuestions = res.total_questions
        this.secondsPerQuestion = res.seconds_per_question
        if (res.question) {
          this.applyQuestion(res.question)
          return
        }
        // Learner sessions resume at the first unanswered question; no question = everything answered.
        if (this.singleTimer) {
          await this.finish()
          return
        }
        await this.loadQuestion(0)
      } catch (err) {
        if (generation !== this.generation) return
        this.handleApiError(err)
      }
    },

    async loadQuestion(index: number): Promise<void> {
      if (this.sessionId === null) return
      if (index >= this.totalQuestions) {
        await this.finish()
        return
      }

      const generation = this.generation
      this.status = 'loading'
      this.selectedIndex = null
      this.lastAnswer = null
      this.currentQuestion = null

      try {
        const q = await api.exam.question(this.sessionId, index)
        if (generation !== this.generation) return
        this.currentIndex = index
        this.currentQuestion = q
        this.questionStartedAt = Date.now()
        this.status = 'answering'
      } catch (err) {
        if (generation !== this.generation) return
        this.handleApiError(err)
      }
    },

    async submitAnswer(optionIndex: number | null): Promise<void> {
      if (
        this.sessionId === null
        || this.currentQuestion === null
        || (this.status !== 'answering' && this.status !== 'loading')
      ) {
        return
      }

      const generation = this.generation
      this.selectedIndex = optionIndex
      this.status = 'submitting'
      const timeSpentMs = Math.max(0, Date.now() - this.questionStartedAt)

      try {
        const res = await api.exam.answer(this.sessionId, {
          question_index: this.currentIndex,
          word_id: this.currentQuestion.word_id,
          selected_option_index: optionIndex,
          time_spent_ms: timeSpentMs,
        })
        if (generation !== this.generation) return
        this.lastAnswer = res
        this.totalScore = res.total_score
        this.status = 'feedback'
      } catch (err) {
        if (generation !== this.generation) return
        this.handleApiError(err)
      }
    },

    async advance(): Promise<void> {
      if (this.lastAnswer === null) return
      if (!this.lastAnswer.has_next) {
        await this.finish()
        return
      }
      if (this.lastAnswer.next_question) {
        this.applyQuestion(this.lastAnswer.next_question)
        return
      }
      await this.loadQuestion(this.currentIndex + 1)
    },

    async finish(): Promise<void> {
      if (this.sessionId === null) return
      // In-flight answers must not reopen the exam after its overall timer expires.
      if (this.status !== 'finished') this.generation += 1
      this.status = 'finished'
      await this.loadResults()
    },

    async loadResults(): Promise<void> {
      if (this.sessionId === null) return
      const generation = this.generation
      try {
        const res = await api.exam.result(this.sessionId)
        if (generation !== this.generation) return
        if (isLevelQuizResult(res)) {
          this.mode = 'level_quiz'
          this.quizResult = res
          this.finalScore = res.correct
          this.finalRank = null
          this.totalParticipants = 0
          this.leaderboard = []
          return
        }
        if (isMockExamResult(res)) {
          this.mode = 'personal_mock'
          this.mockResult = res
          this.finalScore = res.correct
          this.finalRank = null
          this.totalParticipants = res.attempts
          this.leaderboard = []
          return
        }
        this.finalScore = res.learner_score
        this.finalRank = res.rank
        this.totalParticipants = res.total_participants
        this.leaderboard = res.leaderboard
      } catch (err) {
        if (generation !== this.generation) return
        // A 409 "exam_still_open" is expected while server-side grace
        // period hasn't elapsed. Leave leaderboard empty in that case.
        if (!(err instanceof ApiClientError) || err.kind !== 'conflict') {
          this.handleApiError(err)
        }
      }
    },

    resetForSession(sessionId: number): void {
      this.generation += 1
      this.sessionId = sessionId
      this.mode = 'group_exam'
      this.endsAt = null
      this.totalQuestions = 0
      this.secondsPerQuestion = 10
      this.currentIndex = 0
      this.currentQuestion = null
      this.questionStartedAt = 0
      this.selectedIndex = null
      this.lastAnswer = null
      this.totalScore = 0
      this.errorMessage = ''
      this.finalScore = 0
      this.finalRank = null
      this.totalParticipants = 0
      this.leaderboard = []
      this.mockResult = null
      this.quizResult = null
    },

    applyQuestion(question: ExamQuestionResponse): void {
      this.currentIndex = question.question_index
      this.currentQuestion = question
      this.questionStartedAt = Date.now()
      this.selectedIndex = null
      this.lastAnswer = null
      this.status = 'answering'
    },

    handleApiError(err: unknown): void {
      this.status = err instanceof ApiClientError && err.kind === 'gone' ? 'closed' : 'error'
      this.errorMessage = errorMessage(err, 'exam')
    },
  },
})
