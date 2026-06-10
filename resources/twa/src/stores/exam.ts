import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type {
  ExamAnswerResponse,
  ExamLeaderboardEntry,
  ExamQuestionResponse,
} from '@/api/types'

export type ExamStatus =
  | 'idle'
  | 'joining'
  | 'loading'       // fetching next question
  | 'answering'     // waiting for student to click an option
  | 'submitting'    // POST /answer in flight
  | 'feedback'      // showing correct/incorrect highlight
  | 'finished'
  | 'closed'
  | 'error'

export interface ExamStoreState {
  sessionId: number | null
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
}

export const useExamStore = defineStore('exam', {
  state: (): ExamStoreState => ({
    sessionId: null,
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
  }),

  getters: {
    overallSecondsLeft: (s): number => {
      if (s.endsAt === null) return 0
      return Math.max(0, Math.floor((s.endsAt - Date.now()) / 1000))
    },
  },

  actions: {
    async join(sessionId: number): Promise<void> {
      this.resetForSession(sessionId)
      this.status = 'joining'
      try {
        const res = await api.exam.join(sessionId)
        this.endsAt = res.ends_at ? Date.parse(res.ends_at) : null
        this.totalQuestions = res.total_questions
        this.secondsPerQuestion = res.seconds_per_question
        if (res.question) {
          this.applyQuestion(res.question)
          return
        }
        await this.loadQuestion(0)
      } catch (err) {
        this.handleApiError(err)
      }
    },

    async loadQuestion(index: number): Promise<void> {
      if (this.sessionId === null) return
      if (index >= this.totalQuestions) {
        await this.finish()
        return
      }

      this.status = 'loading'
      this.selectedIndex = null
      this.lastAnswer = null
      this.currentQuestion = null

      try {
        const q = await api.exam.question(this.sessionId, index)
        this.currentIndex = index
        this.currentQuestion = q
        this.questionStartedAt = Date.now()
        this.status = 'answering'
      } catch (err) {
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
        this.lastAnswer = res
        this.totalScore = res.total_score
        this.status = 'feedback'
      } catch (err) {
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
      this.status = 'finished'
      await this.loadResults()
    },

    async loadResults(): Promise<void> {
      if (this.sessionId === null) return
      try {
        const res = await api.exam.result(this.sessionId)
        this.finalScore = res.student_score
        this.finalRank = res.rank
        this.totalParticipants = res.total_participants
        this.leaderboard = res.leaderboard
      } catch (err) {
        // A 409 "exam_still_open" is expected while server-side grace
        // period hasn't elapsed. Leave leaderboard empty in that case.
        if (!(err instanceof ApiClientError) || err.kind !== 'conflict') {
          this.handleApiError(err)
        }
      }
    },

    resetForSession(sessionId: number): void {
      this.sessionId = sessionId
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
      if (err instanceof ApiClientError) {
        if (err.kind === 'gone') {
          this.status = 'closed'
          this.errorMessage = 'Экзамен был завершён учителем.'
          return
        }
        if (err.kind === 'network') {
          this.status = 'error'
          this.errorMessage = 'Нет связи с сервером. Попробуйте ещё раз.'
          return
        }
        if (err.kind === 'forbidden') {
          this.status = 'error'
          this.errorMessage = 'У вас нет доступа к этому экзамену.'
          return
        }
        this.status = 'error'
        this.errorMessage = err.message || 'Что-то пошло не так.'
        return
      }
      this.status = 'error'
      this.errorMessage = 'Непредвиденная ошибка.'
    },
  },
})
