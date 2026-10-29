import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type {
  Card,
  DoneReason,
  ExerciseType,
  Graded,
  LessonHeader,
  OpenSessionRequest,
  Quality,
  ReviewResponse,
  SessionSummary,
  TrainingMode,
  TrainingStartResponse,
} from '@/api/types'

type Progress = { done: number; total: number }

/** 422 codes meaning "the server expects the other kind of grade for this card" (docs/04 A.3). */
const EXERCISE_MISMATCH_CODES = ['answer_not_accepted', 'quality_not_accepted', 'exercise_pro_required']

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
  | 'verdict'       // a typed answer was graded by the server; the next card waits in `deferred`
  | 'held'          // a four-choice grade is saved; the next card waits in `deferred` until the learner has seen the answer
  | 'finished'      // card === null after /next
  | 'closed'        // 410 Gone from server
  | 'error'

interface TrainingState {
  sessionId: number | null
  mode: TrainingMode | null
  lesson: LessonHeader | null
  totalWords: number
  card: Card | null
  /**
   * Grows with every card the server serves. The same word can come back at once (a failed learning card is served
   * ahead when nothing else is left), so the screen keys the exercise by this number, not by `word_id`: a new serve
   * is always a fresh component, with no pick left over from the previous one.
   */
  serveSeq: number
  showTranslation: boolean
  cardShownAt: number       // epoch ms of when the card was rendered
  progress: Progress
  /** Why the last answer had no card; drives the text of the finish screen. */
  doneReason: DoneReason | null
  /** Server-side summary of a finished personal session; null for group sessions or when finish failed. */
  summary: SessionSummary | null
  opening: boolean
  openError: string
  /**
   * What `open()` got back for a session the training screen has not entered yet: `start()`
   * consumes it instead of asking the server for the same answer again.
   */
  preloaded: { sessionId: number; response: TrainingStartResponse } | null
  /**
   * How the personal session `sessionId` was opened: lets the 410 screen offer "start again"
   * (the same lesson / the daily review). Unknown for a session this run did not open
   * (page reload, group session).
   */
  opened: { sessionId: number; request: OpenSessionRequest } | null
  reviewedCount: number
  lastIntervalDays: number
  status: TrainingStatus
  errorMessage: string
  /** The server's grade of the last typed answer (spelling / gap_fill), shown before the next card. */
  verdict: Graded | null
  /**
   * The answer of the review that is held back: for a typed answer until the verdict is seen (`continueAfterVerdict()`),
   * for a four-choice card until the right answer is seen (`continueAfterChoice()`). `null` while nothing is held, and in
   * the held state of an older server that sent no `card`: then the next card is fetched when the learner moves on.
   */
  deferred: { card: Card | null; doneReason: DoneReason | null } | null
  /** The learner already moved on from a four-choice card whose grade is still on its way: show the next card on arrival. */
  advanceWhenAnswered: boolean
  /** Counts the sessions the store has been reset for: an answer that comes back for an earlier one is dropped. */
  generation: number
}

export const useTrainingStore = defineStore('training', {
  state: (): TrainingState => ({
    sessionId: null,
    mode: null,
    lesson: null,
    totalWords: 0,
    card: null,
    serveSeq: 0,
    showTranslation: false,
    cardShownAt: 0,
    progress: { done: 0, total: 0 },
    doneReason: null,
    summary: null,
    opening: false,
    openError: '',
    preloaded: null,
    opened: null,
    reviewedCount: 0,
    lastIntervalDays: 0,
    status: 'idle',
    errorMessage: '',
    verdict: null,
    deferred: null,
    advanceWhenAnswered: false,
    generation: 0,
  }),

  getters: {
    /** The exercise the current card is served with; a card without `prompt` (older server) is a flash card. */
    exercise: (s): ExerciseType => s.card?.prompt?.type ?? 'flash',

    /**
     * Request to open the same kind of session again after a 410, or null when this run did not
     * open the current session itself (then there is nothing to restart: the screen just closes).
     */
    restartRequest: (s): OpenSessionRequest | null =>
      s.opened !== null && s.opened.sessionId === s.sessionId ? s.opened.request : null,
  },

  actions: {
    /**
     * Opens (or reuses) a personal session. Returns its id for the training route, or null
     * with `openError` set. The answer carries the first card: it is kept for `start()`, so
     * the training screen does not ask the server for it a second time.
     */
    async open(body: OpenSessionRequest): Promise<number | null> {
      if (this.opening) return null
      this.opening = true
      this.openError = ''
      try {
        const res = await api.training.open(body)
        this.preloaded = { sessionId: res.session_id, response: res }
        this.opened = { sessionId: res.session_id, request: { ...body } }
        return res.session_id
      } catch (err) {
        this.openError = errorMessage(err, 'training')
        return null
      } finally {
        this.opening = false
      }
    },

    async start(sessionId: number): Promise<void> {
      const preloaded = this.preloaded?.sessionId === sessionId ? this.preloaded.response : null
      this.preloaded = null
      this.resetForSession(sessionId)
      this.status = 'starting'
      try {
        await this.applyStart(preloaded ?? (await api.training.start(sessionId)))
      } catch (err) {
        this.handleApiError(err)
      }
    },

    async applyStart(res: TrainingStartResponse): Promise<void> {
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
    },

    /** Forgets a failed-open message when the learner leaves the screen that showed it. */
    clearOpenError(): void {
      this.openError = ''
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

    /** A self-graded (flash) card: the card the review answers with is shown at once. */
    async submitReview(quality: Quality): Promise<void> {
      await this.submit(
        (sessionId, wordId, timeSpent, serveToken) =>
          api.training.review(sessionId, wordId, quality, timeSpent, serveToken),
        false,
      )
    },

    /**
     * A four-choice card (reverse / audio), graded by the client. The grade is final at the pick, so it goes to the
     * server at once — it is saved even if the learner leaves the screen right away. The card the review answers with
     * is held back (`continueAfterChoice()`) until the learner has seen the right answer.
     */
    async submitChoice(quality: Quality): Promise<void> {
      await this.submit(
        (sessionId, wordId, timeSpent, serveToken) =>
          api.training.review(sessionId, wordId, quality, timeSpent, serveToken),
        true,
      )
    },

    /**
     * A typed answer (spelling / gap_fill): the server grades it. The answer's next card is held back
     * until the learner has seen the verdict (`continueAfterVerdict()`).
     */
    async submitAnswer(answer: string): Promise<void> {
      await this.submit(
        (sessionId, wordId, timeSpent, serveToken) =>
          api.training.answer(sessionId, wordId, answer, timeSpent, serveToken),
        false,
      )
    },

    /** @param hold keep the card of the answer back until `continueAfterChoice()` (a four-choice card) */
    async submit(
      send: (sessionId: number, wordId: number, timeSpent: number, serveToken: string | null) => Promise<ReviewResponse>,
      hold: boolean,
    ): Promise<void> {
      if (this.sessionId === null || this.card === null || this.status !== 'ready') return
      const generation = this.generation
      const wordId = this.card.word_id
      // Learn-ahead ticket of *this* serve; the server rejects a replay without it.
      const serveToken = this.card.serve_token ?? null
      const timeSpent = Math.max(0, Date.now() - this.cardShownAt)
      this.advanceWhenAnswered = false
      this.status = 'submitting'
      try {
        const res = await send(this.sessionId, wordId, timeSpent, serveToken)
        // The learner has moved on to another session while the answer was on its way: it is no use to this one.
        if (generation !== this.generation) return
        this.reviewedCount += 1
        this.lastIntervalDays = res.new_interval_days
        if (res.progress) this.progress = clampProgress(res.progress)
        if ('card' in res) {
          const doneReason = res.done_reason ?? null
          if (res.graded) {
            this.verdict = res.graded
            this.deferred = { card: res.card ?? null, doneReason }
            this.status = 'verdict'
            return
          }
          if (hold) {
            this.holdAnswer({ card: res.card ?? null, doneReason })
            return
          }
          this.doneReason = doneReason
          this.applyCard(res.card ?? null)
          return
        }
        if (hold) {
          this.holdAnswer(null)
          return
        }
        await this.loadNext()
      } catch (err) {
        if (generation !== this.generation) return
        // The server already consumed/re-issued this serve (rare race or network replay):
        // the grade was not counted, so silently fetch the actual card. Once per submit —
        // loadNext() never re-submits, and its own failure goes through the usual error path.
        // The same for an exercise the server no longer agrees with (the plan changed mid-session,
        // an older card on screen): the fresh card carries the exercise the server expects.
        if (err instanceof ApiClientError && err.kind === 'conflict' && err.code === 'card_not_due') {
          await this.loadNext()
          return
        }
        if (err instanceof ApiClientError && EXERCISE_MISMATCH_CODES.includes(err.code)) {
          await this.loadNext()
          return
        }
        this.handleApiError(err)
      }
    },

    /** The learner has seen the verdict: show what the review answered with. */
    continueAfterVerdict(): void {
      if (this.status !== 'verdict') return
      this.verdict = null
      this.applyDeferred()
    },

    /**
     * The learner has seen the right answer of a four-choice card (the pause after a right pick is over, or "Next" was
     * pressed after a wrong one): show what the review answered with. If the answer has not come back yet, it is shown
     * the moment it does. Anything else (an answer already shown, a second press) changes nothing.
     */
    continueAfterChoice(): void {
      if (this.status === 'held') this.applyDeferred()
      else if (this.status === 'submitting') this.advanceWhenAnswered = true
    },

    /** Keeps the answer of a four-choice review back, or shows it at once when the learner has already moved on. */
    holdAnswer(deferred: TrainingState['deferred']): void {
      this.deferred = deferred
      this.status = 'held'
      if (this.advanceWhenAnswered) this.applyDeferred()
    },

    /** Shows what the held review answered with: its card, or the end of the session. */
    applyDeferred(): void {
      const deferred = this.deferred
      this.deferred = null
      this.advanceWhenAnswered = false
      if (deferred === null) {
        void this.loadNext()
        return
      }
      this.doneReason = deferred.doneReason
      this.applyCard(deferred.card)
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
      this.generation += 1
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
      this.verdict = null
      this.deferred = null
      this.advanceWhenAnswered = false
    },

    applyCard(card: Card | null): void {
      this.showTranslation = false
      if (card === null) {
        this.card = null
        this.status = 'finished'
        return
      }

      this.card = card
      this.serveSeq += 1
      this.cardShownAt = Date.now()
      this.status = 'ready'
    },

    handleApiError(err: unknown): void {
      this.status = err instanceof ApiClientError && err.kind === 'gone' ? 'closed' : 'error'
      this.errorMessage = errorMessage(err, 'training')
    },
  },
})
