import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import { markTiming } from '@/composables/useTimingMarks'
import { useTodayStore } from '@/stores/today'
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
  type SessionSource,
  type SessionSummary,
  type TrainingMode,
  type TrainingStartResponse,
  type WordScore,
  type WordScoreDay,
} from '@/api/types'

type Progress = { done: number; total: number }

/**
 * Codes meaning "the server expects the other kind of grade for this card" (docs/04 A.3): 422 for an answer of the wrong
 * kind, 409 `exercise_mismatch` when the exercise the card was shown with is not the one the server serves now (Pro -> Free).
 */
const EXERCISE_MISMATCH_CODES = ['answer_not_accepted', 'quality_not_accepted', 'exercise_pro_required', 'exercise_mismatch']

/**
 * The progress of a daily review is that of the round (`total >= done`, docs/04 A.3); a safety net for an older server
 * that counted against the daily goal, so the bar never shows "12 / 10".
 */
function clampProgress(p: Progress): Progress {
  return p.done > p.total ? { done: p.total, total: p.total } : p
}

/** The run of good answers (the "combo" chip): shown from this many in a row, and celebrated on every `COMBO_STEP`-th. */
export const COMBO_MIN = 3
export const COMBO_STEP = 5

/** How the last answer counts for the run: `right` and `wrong` (an objective check), `again` ("not yet" on a flash card). */
export type OutcomeKind = 'right' | 'wrong' | 'again'

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
  /** The daily round is over and the server can serve another one (`can_continue`, only with `sprint_complete`). */
  canContinue: boolean
  /** Server-side summary of a finished personal session; null for group sessions or when finish failed. */
  summary: SessionSummary | null
  opening: boolean
  openError: string
  /** The server's code of the last refused `open()` (`lesson_review_unavailable`, ...); null: none, or not an answer of the server. */
  openErrorCode: string | null
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
  deferred: { card: Card | null; doneReason: DoneReason | null; canContinue: boolean } | null
  /** The learner already moved on from a four-choice card whose grade is still on its way: show the next card on arrival. */
  advanceWhenAnswered: boolean
  /** The run of good answers in a row in this session (client only); 0 after a miss. */
  combo: number
  /** The longest run of the session: the finish screen says it. */
  bestCombo: number
  /** The last answer that counted for the run; `seq` grows with each, so the screen can react to every one. */
  outcome: { seq: number; kind: OutcomeKind }
  /**
   * The word points of the last answer (docs/32 §8.1, `score` of `/review`) for the small chip «+3»: `points` - what the weekly count
   * got (the monthly one when the week gave none); `capped` - this answer found the day at its cap, said once a session. null: the
   * answer brought nothing worth saying (no points, the rules v2 off, an older server), and while the next answer is on its way.
   */
  wordScore: { seq: number; points: number; capped: boolean } | null
  /** The cap of the day has been said in this session: later answers at the cap stay quiet. */
  scoreCapNoted: boolean
  /**
   * When the session began, epoch ms: the SERVER's `started_at` (the start / open answer), so the finish screen can tell "achieved in
   * this session" by one clock; `Date.now()` of the device only for an older server that sends none.
   */
  startedAt: number
  /** Counts the sessions the store has been reset for: an answer that comes back for an earlier one is dropped. */
  generation: number
  /** The answer that waits for the network (F5); at most one: no next card is served until it is accepted. */
  pending: PendingAnswer | null
  /** The last error was a lost connection: the return of the network repeats what failed. */
  networkError: boolean
}

/**
 * An answer that did not reach the server for want of a connection (docs/40 §2.6, F5): everything needed to send the very same request
 * again - the same `serve_token`, so the server accepts it once (a repeat of an answer that did arrive is a 409 `card_not_due`, docs/06
 * §5.2). `quality` for a flash card, `answer` for the others (the pick, the typed text). `hold`, `introduction` - how `submit` treats its
 * reply. Kept in the store and in `sessionStorage` (`training:pending-review:<sessionId>`), so a reload of the tab sends it first.
 */
export interface PendingAnswer {
  sessionId: number
  wordId: number
  serveToken: string | null
  exercise: ExerciseType
  timeSpentMs: number
  quality: Quality | null
  answer: string | null
  hold: boolean
  introduction: boolean
  /** epoch ms of the first try */
  at: number
}

const pendingKey = (sessionId: number): string => `training:pending-review:${sessionId}`

/** Best effort: storage can be full, switched off or gone (iOS clears it in the background); the answer in the store is enough then. */
function storePending(answer: PendingAnswer): void {
  try {
    window.sessionStorage.setItem(pendingKey(answer.sessionId), JSON.stringify(answer))
  } catch {
    // the copy in the store still waits for the network
  }
}

function dropPending(sessionId: number): void {
  try {
    window.sessionStorage.removeItem(pendingKey(sessionId))
  } catch {
    // nothing to drop
  }
}

/** The answer of `sessionId` left by an earlier load of the tab; null when there is none or it is not one this build wrote. */
function readPending(sessionId: number): PendingAnswer | null {
  let raw: string | null
  try {
    raw = window.sessionStorage.getItem(pendingKey(sessionId))
  } catch {
    return null
  }
  if (raw === null) return null
  try {
    const v = JSON.parse(raw) as Partial<PendingAnswer> | null
    const valid =
      v !== null
      && typeof v === 'object'
      && v.sessionId === sessionId
      && typeof v.wordId === 'number'
      && (v.serveToken === null || typeof v.serveToken === 'string')
      && typeof v.exercise === 'string'
      && typeof v.timeSpentMs === 'number'
      && ((typeof v.quality === 'number' && v.answer === null) || (v.quality === null && typeof v.answer === 'string'))
      && typeof v.hold === 'boolean'
      && typeof v.introduction === 'boolean'
      && typeof v.at === 'number'
    if (valid) return v as PendingAnswer
  } catch {
    // not JSON
  }
  dropPending(sessionId)
  return null
}

/** Grows with every chip of the word points, so the same «+2» twice in a row is drawn anew. */
let scoreSeq = 0

/** The finish request in flight, so the eager close at the last card and the one of the finish screen are one request. */
let finishing: { generation: number; promise: Promise<void> } | null = null

/** `started_at` of the server as epoch ms; null when absent or not a date. */
function parseStartedAt(value: string | undefined): number | null {
  if (value === undefined) return null
  const at = Date.parse(value)
  return Number.isFinite(at) ? at : null
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
    canContinue: false,
    summary: null,
    opening: false,
    openError: '',
    openErrorCode: null,
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
    combo: 0,
    bestCombo: 0,
    outcome: { seq: 0, kind: 'right' },
    wordScore: null,
    scoreCapNoted: false,
    startedAt: 0,
    generation: 0,
    pending: null,
    networkError: false,
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
      this.openErrorCode = null
      try {
        const res = await api.training.open(body)
        this.preloaded = { sessionId: res.session_id, response: res }
        this.opened = { sessionId: res.session_id, request: { ...body } }
        return res.session_id
      } catch (err) {
        this.openError = errorMessage(err, 'training')
        this.openErrorCode = err instanceof ApiClientError && err.code !== '' ? err.code : null
        // The round of hard words was played in another tab (or the words went): "Today" is read again, so its invitation matches the server.
        // The same for the review of a lesson (`lesson_review_unavailable`): the plan of the day is read again.
        if (err instanceof ApiClientError && (err.code === 'hard_round_unavailable' || err.code === 'lesson_review_unavailable')) void useTodayStore().load()
        return null
      } finally {
        this.opening = false
      }
    },

    /**
     * Step 2 of the day plan (docs/31 §5б): the review of a lesson studied today (`mode: lesson_review`, docs/06 §15). The session is
     * played on the ordinary training screen; it moves no schedule and brings no points. Returns the session id, or null with `openError`.
     */
    openLessonReview(lessonId: number, source: SessionSource = 'plan'): Promise<number | null> {
      return this.open({ mode: 'lesson_review', lesson_id: lessonId, source })
    },

    async start(sessionId: number): Promise<void> {
      const preloaded = this.preloaded?.sessionId === sessionId ? this.preloaded.response : null
      this.preloaded = null
      this.resetForSession(sessionId)
      const generation = this.generation
      this.status = 'starting'
      try {
        // An answer of this session that waited for the network when the tab was closed or reloaded goes first (docs/40 §2.6): the start
        // after it shows the card the server serves now. The open's answer is not used then - it was given before this answer was taken.
        const waiting = readPending(sessionId)
        if (waiting !== null && !(await this.flushRestored(waiting))) return
        if (generation !== this.generation) return
        const res = (waiting === null ? preloaded : null) ?? (await api.training.start(sessionId))
        if (generation !== this.generation) return
        await this.applyStart(res)
      } catch (err) {
        if (generation !== this.generation) return
        this.handleApiError(err)
      }
    },

    async applyStart(res: TrainingStartResponse): Promise<void> {
      this.mode = res.mode
      this.startedAt = parseStartedAt(res.started_at) ?? this.startedAt
      this.lesson = res.lesson
      this.totalWords = res.total_words
      this.progress = { done: 0, total: res.total_words }
      if (res.progress) this.progress = clampProgress(res.progress)
      if ('card' in res) {
        this.doneReason = res.done_reason ?? null
        this.canContinue = res.can_continue === true
        this.applyCard(res.card ?? null)
        return
      }
      await this.loadNext()
    },

    /** Forgets a failed-open message when the learner leaves the screen that showed it. */
    clearOpenError(): void {
      this.openError = ''
      this.openErrorCode = null
    },

    async loadNext(): Promise<void> {
      if (this.sessionId === null) return
      const generation = this.generation
      this.status = 'loading'
      this.showTranslation = false
      try {
        const res = await api.training.next(this.sessionId)
        if (generation !== this.generation) return
        // A new serve: an answer still waiting was for the card the server no longer holds (it would be refused anyway).
        this.forgetPending()
        this.progress = clampProgress(res.progress)
        this.doneReason = res.done_reason ?? null
        this.canContinue = res.can_continue === true
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
      await this.submit({ quality, answer: null }, false)
    },

    /**
     * A four-choice card (meaning / reverse / audio): the picked option goes to the server as `answer` and the SERVER grades it
     * (docs/06 §12.2) — the card carries nothing that gives the right option away, so the screen colours the options by
     * `choiceResult` when the answer arrives. The pick is final, so it is sent at once — it is saved even if the learner
     * leaves the screen right away. The card the review answers with is held back (`continueAfterChoice()`) until the
     * learner has seen the right answer.
     */
    async submitChoice(picked: string): Promise<void> {
      await this.submit({ quality: null, answer: picked }, true)
    },

    /**
     * A typed answer (spelling / gap_fill): the server grades it. The answer's next card is held back
     * until the learner has seen the verdict (`continueAfterVerdict()`).
     */
    async submitAnswer(answer: string): Promise<void> {
      await this.submit({ quality: null, answer }, false)
    },

    /**
     * @param grade the learner's own grade of a flash card (`quality`), or the pick / typed text the server grades (`answer`)
     * @param hold keep the card of the answer back until `continueAfterChoice()` (a four-choice card)
     */
    async submit(grade: { quality: Quality; answer: null } | { quality: null; answer: string }, hold: boolean): Promise<void> {
      if (this.sessionId === null || this.card === null || this.status !== 'ready') return
      // The exercise this card was shown with: the server refuses (409 `exercise_mismatch`) an answer of another kind.
      const shown = this.exercise
      const answer: PendingAnswer = {
        sessionId: this.sessionId,
        wordId: this.card.word_id,
        // Learn-ahead ticket of *this* serve; the server rejects a replay without it.
        serveToken: this.card.serve_token ?? null,
        exercise: shown,
        timeSpentMs: Math.max(0, Date.now() - this.cardShownAt),
        quality: grade.quality,
        answer: grade.answer,
        hold,
        // A new word's flash card shows its answer at once: "Got it" there is not an answer to a question (see `recordOutcome`).
        introduction: shown === 'flash' && this.card.card_kind === 'new',
        at: Date.now(),
      }
      this.advanceWhenAnswered = false
      await this.send(answer)
    },

    /**
     * "Retry" (the error state, the screen F5) and the return of the network (docs/40 §2.6): the answer that waits goes again, the very
     * same request. Its card is no longer on screen (the error state took its place), so a four-choice answer does not stop on the colours
     * of the options: what it brought is shown at once.
     */
    async resendPending(): Promise<void> {
      const answer = this.pending
      if (answer === null || answer.sessionId !== this.sessionId || this.status !== 'error') return
      if (answer.hold) this.advanceWhenAnswered = true
      await this.send(answer)
    },

    /** Sends an answer and shows what it brought; a lost connection keeps it for `resendPending()`. */
    async send(answer: PendingAnswer): Promise<void> {
      const generation = this.generation
      const { exercise: shown, hold, introduction } = answer
      const selfGrade = answer.quality ?? undefined
      this.lastExercise = shown
      this.choiceResult = null
      this.wordScore = null
      this.status = 'submitting'
      try {
        const res = await this.request(answer)
        // The learner has moved on to another session while the answer was on its way: it is no use to this one.
        if (generation !== this.generation) return
        this.forgetPending()
        // The first answer of the open the server accepted (docs/04 A.21): this sends the timing marks of a sampled open.
        markTiming('first_answer_ack')
        this.reviewedCount += 1
        this.lastIntervalDays = res.new_interval_days
        if (!introduction) this.recordOutcome(shown, selfGrade, res.graded ?? null)
        this.noteScore(res.score ?? null)
        if (res.progress) this.progress = clampProgress(res.progress)
        if ('card' in res) {
          const doneReason = res.done_reason ?? null
          const canContinue = res.can_continue === true
          // No card after this answer: the round is over, so it is closed now - the verdict / answer screen may be left by closing the app.
          if (!res.card) void this.finalize()
          // A four-choice card is graded by the server too, but its screen has shown the answer already: no verdict screen.
          if (res.graded && !hold) {
            this.verdict = res.graded
            this.deferred = { card: res.card ?? null, doneReason, canContinue }
            this.status = 'verdict'
            return
          }
          if (hold) {
            const deferred = { card: res.card ?? null, doneReason, canContinue }
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
          this.canContinue = canContinue
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
        // No connection (docs/40 §2.6): the answer waits - in the store and in `sessionStorage` - for "Retry" or the network.
        if (err instanceof ApiClientError && err.kind === 'network') {
          this.keepPending(answer)
          this.handleApiError(err)
          return
        }
        // A repeat of an answer whose first try got no reply (the time ran out, the connection broke).
        const repeated = this.pending !== null
        this.forgetPending()
        // The server already consumed/re-issued this serve (rare race or network replay - a repeat of an answer that did arrive):
        // the grade was not counted now, so silently fetch the actual card. Once per submit —
        // loadNext() never re-submits, and its own failure goes through the usual error path.
        // The same for an exercise the server no longer agrees with (the plan changed mid-session,
        // an older card on screen): the fresh card carries the exercise the server expects.
        if (err instanceof ApiClientError && err.kind === 'conflict' && err.code === 'card_not_due') {
          // For a repeat it is the first try that the server took: the answer counts (`reviewedCount` - the fallback of the finish
          // screen when `/finish` gave no summary; the server's numbers are the truth).
          if (repeated) this.reviewedCount += 1
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

    /** The request of an answer: a flash grade goes as `quality`, the rest as `answer`. */
    request(answer: PendingAnswer): Promise<ReviewResponse> {
      const { sessionId, wordId, timeSpentMs, serveToken, exercise } = answer
      return answer.quality !== null
        ? api.training.review(sessionId, wordId, answer.quality, timeSpentMs, serveToken, exercise)
        : api.training.answer(sessionId, wordId, answer.answer ?? '', timeSpentMs, serveToken, exercise)
    },

    /**
     * The answer left by an earlier load of the tab (`start()`): sent before the session is shown. Its reply is not shown - the start
     * that follows brings the card and the progress. Returns false when the start must not follow: the network is still gone (the answer
     * keeps waiting, "Retry" starts again), the session is closed (410) or the server refused otherwise.
     */
    async flushRestored(answer: PendingAnswer): Promise<boolean> {
      const generation = this.generation
      this.pending = answer
      try {
        const res = await this.request(answer)
        if (generation !== this.generation) return false
        this.forgetPending()
        this.reviewedCount += 1
        this.lastIntervalDays = res.new_interval_days
        return true
      } catch (err) {
        if (generation !== this.generation) return false
        if (err instanceof ApiClientError && err.kind === 'network') {
          this.handleApiError(err)
          return false
        }
        this.forgetPending()
        // Taken already (409 `card_not_due`: the first try of this answer reached the server, so it counts) or of an exercise the server
        // no longer serves: nothing is lost, the session goes on.
        if (err instanceof ApiClientError && err.code === 'card_not_due') {
          this.reviewedCount += 1
          return true
        }
        if (err instanceof ApiClientError && EXERCISE_MISMATCH_CODES.includes(err.code)) return true
        this.handleApiError(err)
        return false
      }
    },

    keepPending(answer: PendingAnswer): void {
      this.pending = answer
      storePending(answer)
    },

    forgetPending(): void {
      if (this.pending === null) return
      dropPending(this.pending.sessionId)
      this.pending = null
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

    /**
     * The run of good answers (client only, for the chip and the finish screen): a right pick / typed answer or "got it" /
     * "knew it" on a flash card goes on, a wrong one and "not yet" stop it. The pretest is a guess before learning, not a
     * check: it neither goes on nor breaks the run. Nor does the introduction card of a new word (a flash card with its answer
     * open, `card_kind = new`; `submit` does not report it): "Got it" there answers no question.
     */
    /**
     * The chip of the word points of an answer (docs/32 §8.1): the points the weekly count got (`score.week`; the monthly one when only
     * it counts on the v2 rules); the cap of the day (of both counts) - the one reason of "no points" worth a word - once a session. Every other reason
     * (already today, early, the introduction of a word...) says nothing. The answer itself is not touched.
     */
    noteScore(score: WordScore | null): void {
      if (score === null) {
        this.wordScore = null
        return
      }
      const points = score.week > 0 ? score.week : Math.max(0, score.month)
      const day = score.today
      // The day is at its cap only when the monthly count (`today_month`, sent when it differs from the weekly one) is full too: a
      // Free week at its cap with a Pro month that still grows gets points.
      const full = (count: WordScoreDay | null): boolean => count !== null && count.cap !== null && count.points >= count.cap
      const atCap = full(day) && (score.today_month === null || full(score.today_month))
      const capped = atCap && !this.scoreCapNoted
      if (capped) this.scoreCapNoted = true
      this.wordScore = points > 0 || capped ? { seq: (scoreSeq += 1), points, capped } : null
    },

    recordOutcome(shown: ExerciseType, selfGrade: Quality | undefined, graded: Graded | null): void {
      if (shown === 'pretest') return
      const right = selfGrade !== undefined ? selfGrade >= 4 : graded?.correct
      if (right === undefined) return
      const kind: OutcomeKind = right ? 'right' : selfGrade !== undefined ? 'again' : 'wrong'
      this.combo = right ? this.combo + 1 : 0
      this.bestCombo = Math.max(this.bestCombo, this.combo)
      this.outcome = { seq: this.outcome.seq + 1, kind }
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
      this.canContinue = deferred.canContinue
      this.applyCard(deferred.card)
    },

    /**
     * "One more round" (docs/06 §13.2): the finished session is closed first (without it the server hands back the same finished
     * one; the finish screen already did it, so only a failed finish is repeated), then a new daily review is opened. Returns the id
     * of the new session, or null with `openError` set.
     */
    async nextRound(): Promise<number | null> {
      if (this.sessionId === null || this.opening) return null
      if (this.summary === null && this.mode !== 'group_lesson') {
        this.opening = true
        this.openError = ''
        try {
          this.summary = await api.training.finish(this.sessionId)
        } catch (err) {
          this.openError = errorMessage(err, 'training')
          return null
        } finally {
          this.opening = false
        }
      }
      return this.open({ mode: 'daily_review', source: 'self' })
    },

    /**
     * Closes a finished personal session on the server and keeps its summary. Best effort:
     * the learner already saw the last card, so a failure only costs the server-side numbers.
     * Group sessions are closed by the teacher, never from here.
     */
    async finalize(): Promise<void> {
      if (this.sessionId === null || this.mode === null || this.mode === 'group_lesson') return
      // Closed already (at the last card, or by an earlier call): the summary is in hand, there is nothing to ask again.
      if (this.summary !== null) return
      const generation = this.generation
      if (finishing !== null && finishing.generation === generation) return finishing.promise
      const promise = this.finishNow(generation)
      const entry = { generation, promise }
      finishing = entry
      try {
        await promise
      } finally {
        if (finishing === entry) finishing = null
      }
    },

    async finishNow(generation: number): Promise<void> {
      if (this.sessionId === null) return
      try {
        const summary = await api.training.finish(this.sessionId)
        if (generation !== this.generation) return
        this.summary = summary
        this.startedAt = parseStartedAt(summary.started_at) ?? this.startedAt
      } catch {
        if (generation !== this.generation) return
        this.summary = null
      }
    },

    resetForSession(sessionId: number): void {
      this.generation += 1
      this.sessionId = sessionId
      // An answer of the previous session stays in `sessionStorage` for when that session is opened again.
      this.pending = null
      this.networkError = false
      this.mode = null
      this.doneReason = null
      this.canContinue = false
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
      this.combo = 0
      this.bestCombo = 0
      this.wordScore = null
      this.scoreCapNoted = false
      this.startedAt = Date.now()
    },

    applyCard(card: Card | null): void {
      this.showTranslation = false
      this.choiceResult = null
      if (card === null) {
        this.card = null
        this.status = 'finished'
        // The round is closed at once, not when the learner presses "Continue" on the last verdict: closing the Mini App there
        // would leave it open. Idempotent and best effort; the summary reaches the finish screen whenever it is ready.
        void this.finalize()
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
      this.networkError = err instanceof ApiClientError && err.kind === 'network'
    },
  },
})
