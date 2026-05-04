import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { Card, LessonHeader, Quality } from '@/api/types'

export type TrainingStatus =
  | 'idle'          // initial
  | 'starting'      // POST /start in flight
  | 'loading'       // GET /next in flight
  | 'ready'         // card visible, waiting for student input
  | 'submitting'    // POST /review in flight
  | 'finished'      // card === null after /next
  | 'closed'        // 410 Gone from server
  | 'error'

interface TrainingState {
  sessionId: number | null
  lesson: LessonHeader | null
  totalWords: number
  card: Card | null
  showTranslation: boolean
  cardShownAt: number       // epoch ms of when the card was rendered
  progress: { done: number; total: number }
  reviewedCount: number
  lastIntervalDays: number
  status: TrainingStatus
  errorMessage: string
}

export const useTrainingStore = defineStore('training', {
  state: (): TrainingState => ({
    sessionId: null,
    lesson: null,
    totalWords: 0,
    card: null,
    showTranslation: false,
    cardShownAt: 0,
    progress: { done: 0, total: 0 },
    reviewedCount: 0,
    lastIntervalDays: 0,
    status: 'idle',
    errorMessage: '',
  }),

  actions: {
    async start(sessionId: number): Promise<void> {
      this.resetForSession(sessionId)
      this.status = 'starting'
      try {
        const res = await api.training.start(sessionId)
        this.lesson = res.lesson
        this.totalWords = res.total_words
        this.progress = { done: 0, total: res.total_words }
        if (res.progress) this.progress = res.progress
        if ('card' in res) {
          this.applyCard(res.card ?? null)
          return
        }
        await this.loadNext()
      } catch (err) {
        this.handleApiError(err)
      }
    },

    async loadNext(): Promise<void> {
      if (this.sessionId === null) return
      this.status = 'loading'
      this.showTranslation = false
      try {
        const res = await api.training.next(this.sessionId)
        this.progress = res.progress
        this.applyCard(res.card)
      } catch (err) {
        this.handleApiError(err)
      }
    },

    reveal(): void {
      if (this.status === 'ready') this.showTranslation = true
    },

    async submitReview(quality: Quality): Promise<void> {
      if (this.sessionId === null || this.card === null || this.status !== 'ready') return
      const wordId = this.card.word_id
      const timeSpent = Math.max(0, Date.now() - this.cardShownAt)
      this.status = 'submitting'
      try {
        const res = await api.training.review(this.sessionId, wordId, quality, timeSpent)
        this.reviewedCount += 1
        this.lastIntervalDays = res.new_interval_days
        if (res.progress) this.progress = res.progress
        if ('card' in res) {
          this.applyCard(res.card ?? null)
          return
        }
        await this.loadNext()
      } catch (err) {
        this.handleApiError(err)
      }
    },

    resetForSession(sessionId: number): void {
      this.sessionId = sessionId
      this.lesson = null
      this.totalWords = 0
      this.card = null
      this.showTranslation = false
      this.cardShownAt = 0
      this.progress = { done: 0, total: 0 }
      this.reviewedCount = 0
      this.lastIntervalDays = 0
      this.errorMessage = ''
    },

    applyCard(card: Card | null): void {
      this.showTranslation = false
      if (card === null) {
        this.card = null
        this.status = 'finished'
        return
      }

      this.card = card
      this.cardShownAt = Date.now()
      this.status = 'ready'
    },

    handleApiError(err: unknown): void {
      if (err instanceof ApiClientError) {
        if (err.kind === 'gone') {
          this.status = 'closed'
          this.errorMessage = 'Учитель завершил тренировку.'
          return
        }
        if (err.kind === 'network') {
          this.status = 'error'
          this.errorMessage = 'Нет связи с сервером. Попробуйте ещё раз.'
          return
        }
        if (err.kind === 'forbidden') {
          this.status = 'error'
          this.errorMessage = 'У вас нет доступа к этой тренировке.'
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
