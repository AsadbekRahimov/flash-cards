import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import {
  EXERCISE_TYPES,
  type Card,
  type ChoiceResult,
  type DoneReason,
  type ExerciseType,
  type Graded,
  type LessonHeader,
  type OpenSessionRequest,
  type Quality,
  type ReviewResponse,
  type SessionSummary,
  type TrainingMode,
  type TrainingStartResponse,
} from '@/api/types'

type Progress = { done: number; total: number }

/**
 * Codes meaning "the server expects the other kind of grade for this card" (docs/04 A.3): 422 for an answer of the wrong
 * kind, 409 `exercise_mismatch` when the exercise the card was shown with is not the one the server serves now (Pro -> Free).
 */
const EXERCISE_MISMATCH_CODES = ['answer_not_accepted', 'quality_not_accepted', 'exercise_pro_required', 'exercise_mismatch']

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
  /** The server's grade of the last typed answer (spelling / gap_fill) or pretest pick, shown before the next card. */
  verdict: Graded | null
  /** The exercise of the card the last answer was sent for: the verdict screen of a pretest differs from a typed one. */
  lastExercise: ExerciseType | null
  /**
   * The server's word on the pick of a meaning / reverse / audio card (the card carries nothing that gives the right option away, so
   * the screen colours the options only when this arrives); null before the answer and for the other exercises.
   */
  choiceResult: ChoiceResult | null
  /** "My hint" is being saved (PUT /words/{id}/note). */
  savingNote: boolean
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
    lastExercise: null,
    savingNote: false,
    choiceResult: null,
    deferred: null,
    advanceWhenAnswered: false,
    generation: 0,
  }),

  getters: {
    /** The exercise the current card is served with; a card without `prompt` (older server) is a flash card. */
    exercise: (s): ExerciseType => s.card?.prompt?.type ?? 'flash',

    /**
     * The card on screen carries an exercise this build does not know (a newer server): it cannot be shown as anything —
     * a flash card would have no answer to reveal (the server hides it) — so the screen asks to update the Mini App.
     */
    unsupportedExercise: (s): boolean => s.card?.prompt != null && !EXERCISE_TYPES.includes(s.card.prompt.type),

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
      const generation = this.generation
      this.status = 'starting'
      try {
        const res = preloaded ?? (await api.training.start(sessionId))
        if (generation !== this.generation) return
        await this.applyStart(res)
      } catch (err) {
        if (generation !== this.generation) return
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
      const generation = this.generation
      this.status = 'loading'
      this.showTranslation = false
      try {
        const res = await api.training.next(this.sessionId)
        if (generation !== this.generation) return
        this.progress = clampProgress(res.progress)
        this.doneReason = res.done_reason ?? null
        this.applyCard(res.card)
      } catch (err) {
        if (generation !== this.generation) return
        this.handleApiError(err)
      }
    },

    reveal(): void {
      if (this.status === 'ready') this.showTranslation = true
    },

    /** A self-graded (flash) card: the card the review answers with is shown at once. */
    async submitReview(quality: Quality): Promise<void> {
      await this.submit(
        (sessionId, wordId, timeSpent, serveToken, exercise) =>
          api.training.review(sessionId, wordId, quality, timeSpent, serveToken, exercise),
        false,
      )
    },

    /**
     * A four-choice card (meaning / reverse / audio): the picked option goes to the server as `answer` and the SERVER grades it
     * (docs/06 §12.2) — the card carries nothing that gives the right option away, so the screen colours the options by
     * `choiceResult` when the answer arrives. The pick is final, so it is sent at once — it is saved even if the learner
     * leaves the screen right away. The card the review answers with is held back (`continueAfterChoice()`) until the
     * learner has seen the right answer.
     */
    async submitChoice(picked: string): Promise<void> {
      await this.submit(
        (sessionId, wordId, timeSpent, serveToken, exercise) =>
          api.training.answer(sessionId, wordId, picked, timeSpent, serveToken, exercise),
        true,
      )
    },

    /**
     * A typed answer (spelling / gap_fill): the server grades it. The answer's next card is held back
     * until the learner has seen the verdict (`continueAfterVerdict()`).
     */
    async submitAnswer(answer: string): Promise<void> {
      await this.submit(
        (sessionId, wordId, timeSpent, serveToken, exercise) =>
          api.training.answer(sessionId, wordId, answer, timeSpent, serveToken, exercise),
        false,
      )
    },

    /** @param hold keep the card of the answer back until `continueAfterChoice()` (a four-choice card) */
    async submit(
      send: (
        sessionId: number,
        wordId: number,
        timeSpent: number,
        serveToken: string | null,
        exercise: ExerciseType,
      ) => Promise<ReviewResponse>,
      hold: boolean,
    ): Promise<void> {
      if (this.sessionId === null || this.card === null || this.status !== 'ready') return
      const generation = this.generation
      const wordId = this.card.word_id
      // Learn-ahead ticket of *this* serve; the server rejects a replay without it.
      const serveToken = this.card.serve_token ?? null
      const timeSpent = Math.max(0, Date.now() - this.cardShownAt)
      this.advanceWhenAnswered = false
      // The exercise this card was shown with: the server refuses (409 `exercise_mismatch`) an answer of another kind.
      const shown = this.exercise
      this.lastExercise = shown
      this.choiceResult = null
      this.status = 'submitting'
      try {
        const res = await send(this.sessionId, wordId, timeSpent, serveToken, shown)
        // The learner has moved on to another session while the answer was on its way: it is no use to this one.
        if (generation !== this.generation) return
        this.reviewedCount += 1
        this.lastIntervalDays = res.new_interval_days
        if (res.progress) this.progress = clampProgress(res.progress)
        if ('card' in res) {
          const doneReason = res.done_reason ?? null
          // A four-choice card is graded by the server too, but its screen has shown the answer already: no verdict screen.
          if (res.graded && !hold) {
            this.verdict = res.graded
            this.deferred = { card: res.card ?? null, doneReason }
            this.status = 'verdict'
            return
          }
          if (hold) {
            const deferred = { card: res.card ?? null, doneReason }
            if (res.graded) {
              this.choiceResult = {
                correct: res.graded.correct,
                right: res.revealed?.correct ?? res.graded.expected,
                revealed: res.revealed ?? null,
              }
              this.holdAnswer(deferred)
            } else {
              // No verdict to show (not expected of a server that grades the pick): go on to what the answer brought.
              this.deferred = deferred
              this.applyDeferred()
            }
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

    /**
     * Saves (or clears, with an empty text) the learner's own memory hook of the card on screen (docs/06 §12.3). The card
     * keeps the saved text; a failure leaves it as it was and tells the caller.
     */
    async saveNote(note: string): Promise<boolean> {
      const card = this.card
      if (card === null || this.savingNote) return false
      const wordId = card.word_id
      const generation = this.generation
      this.savingNote = true
      try {
        const saved = await api.words.saveNote(wordId, note.trim() === '' ? null : note)
        if (generation === this.generation && this.card?.word_id === wordId) this.card = { ...this.card, note: saved.note }
        return true
      } catch {
        return false
      } finally {
        this.savingNote = false
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
      const generation = this.generation
      try {
        const summary = await api.training.finish(this.sessionId)
        if (generation !== this.generation) return
        this.summary = summary
      } catch {
        if (generation !== this.generation) return
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
      this.lastExercise = null
      this.choiceResult = null
      this.deferred = null
      this.advanceWhenAnswered = false
    },

    applyCard(card: Card | null): void {
      this.showTranslation = false
      this.choiceResult = null
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
