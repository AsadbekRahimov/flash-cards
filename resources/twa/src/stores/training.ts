import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type {
  Card,
  DoneReason,
  LessonHeader,
  OpenSessionRequest,
  Quality,
  SessionSummary,
  TrainingMode,
} from '@/api/types'

type Progress = { done: number; total: number }

/**
 * A daily review may go past its goal (`done` = grades in the session, `total` = daily goal),
 * and the bar must not show "12 / 10".
 */
function clampProgress(p: Progress): Progress {
  return p.done > p.total ? { done: p.total, total: p.total } : p
}

export type TrainingStatus =
  | 'idle'          // initial
  | 'starting'      // POST /start in flight
  | 'loading'       // GET /next in flight
  | 'ready'         // card visible, waiting for learner input
  | 'submitting'    // POST /review in flight
  | 'finished'      // card === null after /next
  | 'closed'        // 410 Gone from server
  | 'error'

interface TrainingState {
  sessionId: number | null
  mode: TrainingMode | null
  lesson: LessonHeader | null
  totalWords: number
  card: Card | null
  showTranslation: boolean
  cardShownAt: number       // epoch ms of when the card was rendered
  progress: Progress
  /** Why the last answer had no card; drives the text of the finish screen. */
  doneReason: DoneReason | null
  /** Server-side summary of a finished personal session; null for group sessions or when finish failed. */
  summary: SessionSummary | null
  opening: boolean
  openError: string
  reviewedCount: number
  lastIntervalDays: number
  status: TrainingStatus
  errorMessage: string
}

export const useTrainingStore = defineStore('training', {
  state: (): TrainingState => ({
    sessionId: null,
    mode: null,
    lesson: null,
    totalWords: 0,
    card: null,
    showTranslation: false,
    cardShownAt: 0,
    progress: { done: 0, total: 0 },
    doneReason: null,
    summary: null,
    opening: false,
    openError: '',
    reviewedCount: 0,
    lastIntervalDays: 0,
    status: 'idle',
    errorMessage: '',
  }),

  actions: {
    /**
     * Opens (or reuses) a personal session. Returns its id for the training route, or null
     * with `openError` set. The first card comes with the answer but the training screen
     * asks for it again through `start()` (idempotent on the server).
     */
    async open(body: OpenSessionRequest): Promise<number | null> {
      if (this.opening) return null
      this.opening = true
      this.openError = ''
      try {
        return (await api.training.open(body)).session_id
      } catch (err) {
        this.openError = errorMessage(err, 'training')
        return null
      } finally {
        this.opening = false
      }
    },

    async start(sessionId: number): Promise<void> {
      this.resetForSession(sessionId)
      this.status = 'starting'
      try {
        const res = await api.training.start(sessionId)
        this.mode = res.mode
        this.lesson = res.lesson
        this.totalWords = res.total_words
        this.progress = { done: 0, total: res.total_words }
        if (res.progress) this.progress = clampProgress(res.progress)
        if ('card' in res) {
          this.doneReason = res.done_reason ?? null
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
        this.progress = clampProgress(res.progress)
        this.doneReason = res.done_reason ?? null
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
      // Learn-ahead ticket of *this* serve; the server rejects a replay without it.
      const serveToken = this.card.serve_token ?? null
      const timeSpent = Math.max(0, Date.now() - this.cardShownAt)
      this.status = 'submitting'
      try {
        const res = await api.training.review(this.sessionId, wordId, quality, timeSpent, serveToken)
        this.reviewedCount += 1
        this.lastIntervalDays = res.new_interval_days
        if (res.progress) this.progress = clampProgress(res.progress)
        if ('card' in res) {
          this.doneReason = res.done_reason ?? null
          this.applyCard(res.card ?? null)
          return
        }
        await this.loadNext()
      } catch (err) {
        // The server already consumed/re-issued this serve (rare race or network replay):
        // the grade was not counted, so silently fetch the actual card. Once per submit —
        // loadNext() never re-submits, and its own failure goes through the usual error path.
        if (err instanceof ApiClientError && err.kind === 'conflict' && err.code === 'card_not_due') {
          await this.loadNext()
          return
        }
        this.handleApiError(err)
      }
    },

    /**
     * Closes a finished personal session on the server and keeps its summary. Best effort:
     * the learner already saw the last card, so a failure only costs the server-side numbers.
     * Group sessions are closed by the teacher, never from here.
     */
    async finalize(): Promise<void> {
      if (this.sessionId === null || this.mode === null || this.mode === 'group_lesson') return
      try {
        this.summary = await api.training.finish(this.sessionId)
      } catch {
        this.summary = null
      }
    },

    resetForSession(sessionId: number): void {
      this.sessionId = sessionId
      this.mode = null
      this.doneReason = null
      this.summary = null
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
      this.status = err instanceof ApiClientError && err.kind === 'gone' ? 'closed' : 'error'
      this.errorMessage = errorMessage(err, 'training')
    },
  },
})
