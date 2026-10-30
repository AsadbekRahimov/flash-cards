import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { newRequestId } from '@/composables/requestId'
import { errorMessage } from '@/i18n/errors'
import { PRACTICE_MODE_ORDER, lessonHeader } from '@/composables/practiceLabels'
import type {
  PracticeActiveSession,
  PracticeAnswerPayload,
  PracticeAnswerResponse,
  PracticeElement,
  PracticeHubResponse,
  PracticeMode,
  PracticeOpenRequest,
  PracticeReveal,
  PracticeSession,
  PracticeVerdict,
  Progress,
} from '@/api/types'

/** idle → starting (the session is being read) → ready ⇄ submitting → verdict → ready … → finished; closed (410) and error end it early. */
export type PracticeStatus =
  | 'idle'
  | 'starting'   // the session is being read (GET, or the answer of the open that the hub keeps)
  | 'ready'      // an element is on screen, waiting for the learner
  | 'submitting' // an answer / a replacement is on its way (or the screen is being re-read after a conflict)
  | 'verdict'    // the answer is graded and shown; the next element waits behind "Next"
  | 'finished'   // the summary
  | 'closed'     // 410: the session is over
  | 'error'

export type HubStatus = 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'

/** The clip of the element on screen (docs/16 §11.4): `usePracticeAudio` loads it and plays it. */
export type AudioState = 'idle' | 'loading' | 'ready' | 'failed'

export interface AudioSlot {
  state: AudioState
  /** `blob:` URL of the loaded clip; revoked when the clip is replaced or the screen leaves. */
  blobUrl: string | null
}

/** What came back for the last answer: kept for the verdict screen (and, for pairs, for the board). */
export interface PracticeOutcome {
  /** The element as it was answered. */
  element: PracticeElement
  /** What was sent for it. */
  answer: PracticeAnswerPayload
  verdict: PracticeVerdict
  firstAttempt: boolean
  reveal: PracticeReveal | null
  /** Pairs: the match that was tried. */
  pair: { left_id: string; right_id: string } | null
  /** Pairs: that match completed the round. */
  roundDone: boolean
  /**
   * The server had an answer for this element already (409 `answer_already_accepted`: another tab, a reply that was lost for good):
   * `verdict` is the one it counted, `answer` is only what was just sent - NOT the answer that counted - and there is no explanation.
   */
  earlier?: boolean
}

interface HubScope {
  course: string
  lessonId: number | null
}

interface PracticeState {
  /** The hub being shown, with the scope it was read for. */
  hub: PracticeHubResponse | null
  hubScope: HubScope | null
  hubStatus: HubStatus
  hubError: string
  /** Number of the newest hub request: an older answer that comes late is dropped. */
  hubSeq: number
  /** The mode whose session is being opened (its card shows the wait); null = nothing is being opened. */
  opening: PracticeMode | null
  /** Why the last open was refused (localized); the hub shows it as a toast. */
  openError: string
  /** Counts the screens that opened a session and the ones left: a refusal that comes back after its screen was left is dropped. */
  openSeq: number
  /**
   * The unfinished session that the learner dropped on purpose ("No sound for now" on a dictation): the hub they land on starts
   * the mode they choose over it at once (`replace_active`), without asking about a session they have just walked out of.
   */
  abandoned: number | null
  /** The learner's unfinished session that `open` ran into (409 `practice_session_active`): the hub asks what to do. */
  conflict: PracticeActiveSession | null
  /** Show the paywall (`practice_pro_required`). */
  paywall: boolean
  /** The retry of mistakes was refused (nothing worth repeating): "Repeat mistakes" gives way to "Again". */
  retryUnavailable: boolean

  /** What `open` got back, for `load` to use instead of asking the server for the same session again. */
  preloaded: PracticeSession | null
  sessionId: number | null
  session: PracticeSession | null
  status: PracticeStatus
  errorMessage: string
  /** The refusal of the last answer that leaves the learner on the same screen (localized): shown as a toast. */
  actionError: string
  outcome: PracticeOutcome | null
  /**
   * The `request_id` of the answer that is on its way or failed without a reply — network, 5xx, 429. It lives until the server
   * has answered for good (a verdict or a refusal), and a repeat takes the same id, so the server counts it once.
   * A collocation, a sentence and a dictation have ONE answer: the key is the element, whatever is sent for it. A pair is an
   * attempt of its own: the key is the element and the pair, so that a second attempt is counted too.
   */
  pendingRequestId: string | null
  /** What `pendingRequestId` belongs to: the element (one answer per element), or the element and the pair. */
  pendingKey: string | null
  /**
   * What the pending request carries (one answer per element only). A repeat sends THIS, whatever the screen hands over: the
   * server tells the first answer again for the same id, and the screen must not show another one as if it were that.
   */
  pendingAnswer: PracticeAnswerPayload | null
  /** The replacement of the clip that is on its way or got no reply: its own id, apart from the answer's, so that neither forgets the other. */
  replacing: { elementId: string; requestId: string } | null
  /** Counts the sessions the store has been reset for: a reply that comes back for an earlier one is dropped. */
  generation: number
  /** Grows with every new element: the screen keys the mode component by it, so a new element has no pick left over. */
  elementSeq: number
  /** Epoch ms when the element appeared: `time_spent_ms` of the answer is telemetry measured from it. */
  elementShownAt: number
  audio: AudioSlot
}

/** Telemetry only (docs/16 §2.6): an hour in front of an element says nothing more than ten minutes. */
const MAX_TIME_SPENT_MS = 600_000

/** A failure after which the server may or may not have counted the request: the same request is repeated as it was. */
function isUncertain(err: unknown): boolean {
  return err instanceof ApiClientError && (err.kind === 'network' || err.kind === 'server' || err.kind === 'rate_limited')
}

const isMode = (value: unknown): value is PracticeMode => PRACTICE_MODE_ORDER.includes(value as PracticeMode)

const isProgress = (value: unknown): value is Progress =>
  typeof value === 'object' && value !== null && typeof (value as Progress).done === 'number' && typeof (value as Progress).total === 'number'

/** The unfinished session out of `details` of 409 `practice_session_active`; null when the server's fields do not add up. */
function conflictFrom(details: Record<string, unknown> | null, fallback: PracticeActiveSession | null): PracticeActiveSession | null {
  if (details === null || typeof details.session_id !== 'number' || !isMode(details.mode)) return fallback
  return {
    id: details.session_id,
    mode: details.mode,
    course: typeof details.course === 'string' ? details.course : (fallback?.course ?? ''),
    lesson_id: typeof details.lesson_id === 'number' ? details.lesson_id : null,
    progress: isProgress(details.progress) ? details.progress : (fallback?.progress ?? { done: 0, total: 0 }),
  }
}

/** The "Continue" card of the hub for a session. */
const activeOf = (session: PracticeSession): PracticeActiveSession => ({
  id: session.id,
  mode: session.mode,
  course: session.course.slug,
  lesson_id: session.lesson?.id ?? null,
  progress: session.progress,
})

const isVerdict = (value: unknown): value is PracticeVerdict =>
  value === 'correct' || value === 'typo' || value === 'wrong' || value === 'skipped'

/**
 * Solo practice (docs/16 §11.4): the hub of a course or lesson, and the session the learner plays — open it, answer
 * element by element, see the verdict, finish with the summary.
 *
 * The server grades everything; this store only keeps what the screens need and defends the learner's answer:
 * - one `request_id` per answer, kept across a network error so that a repeat counts once (`pendingRequestId`); a collocation,
 *   a sentence and a dictation have one answer per ELEMENT, so after a lost reply only that very answer can be sent again
 *   (`pendingAnswer`) - a different one would be refused as "already accepted" and shown as the verdict of the first;
 * - `generation`: every reply is dropped if the learner has moved to another session meanwhile (and `openSeq`: a refusal to
 *   open a session that comes after its screen was left);
 * - `status` ≠ `ready` ignores a second tap, so a double tap is one request.
 *
 * Pairs go on in the same round after each match (`ready`); the other kinds, and the end of a round, stop at `verdict`
 * until `continueAfterVerdict()`.
 */
export const usePracticeStore = defineStore('practice', {
  state: (): PracticeState => ({
    hub: null,
    hubScope: null,
    hubStatus: 'idle',
    hubError: '',
    hubSeq: 0,
    opening: null,
    openError: '',
    openSeq: 0,
    abandoned: null,
    conflict: null,
    paywall: false,
    retryUnavailable: false,
    preloaded: null,
    sessionId: null,
    session: null,
    status: 'idle',
    errorMessage: '',
    actionError: '',
    outcome: null,
    pendingRequestId: null,
    pendingKey: null,
    pendingAnswer: null,
    replacing: null,
    generation: 0,
    elementSeq: 0,
    elementShownAt: 0,
    audio: { state: 'idle', blobUrl: null },
  }),

  getters: {
    /** The element to answer. */
    current: (s): PracticeElement | null => s.session?.current ?? null,

    progress: (s): Progress => s.session?.progress ?? { done: 0, total: 0 },

    /** The verdict of the last answer, while it is on screen (docs/16 §11.4: `verdict` / `reveal`). */
    verdict: (s): PracticeVerdict | null => s.outcome?.verdict ?? null,

    /** The explanation that came with it. */
    reveal: (s): PracticeReveal | null => s.outcome?.reveal ?? null,

    /** Where the screens lead back to: the course and the lesson of the session, else of the hub that was open. */
    scope: (s): HubScope | null =>
      s.session !== null
        ? { course: s.session.course.slug, lessonId: s.session.lesson?.id ?? null }
        : s.hubScope,

    /**
     * Where an unfinished session is, in words ("Module 2 · Lesson 4 · Food", or the course): so that the "Continue" card and the
     * sheet of the unfinished practice say which one it is. The server tells the slug of the course and the id of the lesson,
     * not their names, so only the session this run has seen, or the very place the hub is about, can be named; null otherwise.
     */
    placeOf:
      (s) =>
      (active: PracticeActiveSession): string | null => {
        const seen = s.session !== null && s.session.id === active.id ? s.session : null
        if (seen !== null) return (seen.lesson ? lessonHeader(seen.lesson) : '') || seen.course.title

        const hub = s.hub
        if (hub === null || hub.course.slug !== active.course) return null
        if (active.lesson_id === null) return hub.course.title
        return hub.lesson?.id === active.lesson_id ? lessonHeader(hub.lesson) || hub.course.title : null
      },
  },

  actions: {
    // ---------------------------------------------------------------- hub

    async loadHub(course: string, lessonId: number | null = null): Promise<void> {
      const seq = ++this.hubSeq
      const sameScope = this.hubScope?.course === course && this.hubScope.lessonId === lessonId
      this.hubScope = { course, lessonId }
      // The hub of another course / lesson is not shown while this one loads; the same hub stays (it refreshes in place).
      if (!sameScope) this.hub = null
      this.hubStatus = this.hub === null ? 'loading' : 'ready'
      this.hubError = ''
      try {
        const res = await api.practice.hub(course, lessonId)
        if (seq !== this.hubSeq) return
        this.hub = res
        this.hubStatus = 'ready'
      } catch (err) {
        if (seq !== this.hubSeq) return
        if (err instanceof ApiClientError && err.code === 'practice_unavailable') {
          this.hub = null
          this.hubStatus = 'unavailable'
          return
        }
        // A hub already on screen stays: its numbers are a little old, which the learner can live with.
        if (this.hub !== null) return
        this.hubStatus = 'error'
        this.hubError = errorMessage(err, 'practice')
      }
    },

    /** The hub could not be asked for (the login failed before the request). */
    failHub(err: unknown): void {
      this.hubStatus = 'error'
      this.hubError = errorMessage(err, 'practice')
    },

    /**
     * Opens a solo session. Returns its id for the route, or null with the reason in `conflict` (an unfinished session:
     * the hub asks what to do), `paywall`, or `openError`. The answer is kept for `load()`.
     */
    async open(request: PracticeOpenRequest, { canAsk = true }: { canAsk?: boolean } = {}): Promise<number | null> {
      if (this.opening !== null) return null
      const seq = ++this.openSeq
      this.opening = request.mode
      this.openError = ''
      this.conflict = null
      this.paywall = false
      try {
        const session = await api.practice.open(request)
        // The session exists on the server whether or not the screen is still there: it is kept, and the hub shows it to continue.
        this.preloaded = session
        this.retryUnavailable = false
        // The hub the learner comes back to shows this session as the one to continue (the server has one per learner).
        if (this.hub !== null) this.hub.active_session = activeOf(session)
        return session.id
      } catch (err) {
        // A refusal that comes after its screen was left is nobody's: the next hub must not open with its sheet, paywall or toast.
        if (seq === this.openSeq) this.refuseOpen(err, canAsk)
        return null
      } finally {
        if (seq === this.openSeq) this.opening = null
      }
    },

    /**
     * The screen that opens sessions (the hub, the summary of a session) is left. What it asked for may still come back; what it
     * would have been told - the unfinished session, the paywall, the reason of a refusal - is not told to the next screen.
     */
    leaveOpen(): void {
      this.openSeq += 1
      this.opening = null
      this.openError = ''
      this.conflict = null
      this.paywall = false
    },

    /** "No sound for now": this session is dropped on purpose; the hub the learner comes to starts another mode over it. */
    abandonSession(): void {
      this.abandoned = this.sessionId
    },

    /** The unfinished session of the hub is the one the learner walked out of on purpose (so "Start a new one" is not asked). */
    isAbandoned(activeId: number | null): boolean {
      return this.abandoned !== null && this.abandoned === activeId
    },

    /** The new session is open (or the hub is left): the next hub asks, as it always did. */
    clearAbandoned(): void {
      this.abandoned = null
    },

    /**
     * Why a session was not opened. An unfinished session of the learner (`practice_session_active`) becomes `conflict`
     * for a screen that can ask what to do (`canAsk`, the hub); any other screen gets the sentence in `openError`.
     */
    refuseOpen(err: unknown, canAsk = true): void {
      if (err instanceof ApiClientError) {
        if (err.code === 'practice_session_active' && canAsk) {
          const conflict = conflictFrom(err.details, this.hub?.active_session ?? null)
          if (conflict !== null) {
            this.conflict = conflict
            return
          }
        }
        if (err.code === 'practice_pro_required') {
          this.paywall = true
          return
        }
        if (err.code === 'practice_unavailable') this.hubStatus = 'unavailable'
        if (err.code === 'retry_not_available') this.retryUnavailable = true
      }
      this.openError = errorMessage(err, 'practice')
    },

    clearOpenError(): void {
      this.openError = ''
    },

    clearConflict(): void {
      this.conflict = null
    },

    dismissPaywall(): void {
      this.paywall = false
    },

    clearActionError(): void {
      this.actionError = ''
    },

    // ------------------------------------------------------------ session

    /** Enters a session: the answer of the `open` that created it, or the session as the server has it. */
    async load(sessionId: number): Promise<void> {
      const preloaded = this.preloaded?.id === sessionId ? this.preloaded : null
      this.preloaded = null
      this.resetForSession(sessionId)
      const generation = this.generation
      this.status = 'starting'
      try {
        const session = preloaded ?? (await api.practice.get(sessionId))
        if (generation !== this.generation) return
        this.applySession(session)
      } catch (err) {
        if (generation !== this.generation) return
        this.handleError(err)
      }
    },

    resetForSession(sessionId: number): void {
      this.generation += 1
      this.sessionId = sessionId
      this.session = null
      this.status = 'idle'
      this.errorMessage = ''
      this.actionError = ''
      this.outcome = null
      this.clearPending()
      this.replacing = null
      this.paywall = false
      this.elementShownAt = 0
      this.releaseAudio()
    },

    /** Takes the session as the server has it: the element to answer, or the summary. */
    applySession(session: PracticeSession): void {
      const previous = this.session?.current?.id ?? null
      this.session = session
      this.sessionId = session.id
      this.outcome = null
      this.syncHub(session)
      if (session.status === 'finished' || session.current === null) {
        this.status = 'finished'
        return
      }
      // The same element again (a re-read after a conflict) is not a new one: the mode component keeps what the learner did.
      if (session.current.id !== previous) {
        this.elementSeq += 1
        this.elementShownAt = Date.now()
      }
      this.status = 'ready'
    },

    /**
     * One answer. Pairs: one match (`{left_id, right_id}`). A second call while one is on its way does nothing.
     *
     * A collocation, a sentence and a dictation have ONE answer, so its request id belongs to the element (docs/16 §11.4): after a
     * lost reply the repeat carries the same id AND the same answer - the one that went first, whatever is handed over now -
     * and the server, which may have counted it, tells its verdict and its explanation again. A pair is an attempt of its own.
     */
    async answer(payload: PracticeAnswerPayload): Promise<void> {
      const session = this.session
      const element = session?.current ?? null
      if (this.status !== 'ready' || session === null || element === null) return

      const generation = this.generation
      const single = element.kind !== 'pairs_round'
      const key = single ? element.id : `${element.id}|${JSON.stringify(payload)}`
      const sent = single && this.pendingKey === key && this.pendingAnswer !== null ? this.pendingAnswer : payload
      const requestId = this.requestIdFor(key)
      this.pendingAnswer = single ? sent : null
      const timeSpent = Math.min(MAX_TIME_SPENT_MS, Math.max(0, Date.now() - this.elementShownAt))
      this.status = 'submitting'
      this.actionError = ''
      try {
        const res = await api.practice.answer(session.id, {
          request_id: requestId,
          element_id: element.id,
          time_spent_ms: timeSpent,
          answer: sent,
        })
        if (generation !== this.generation) return
        this.clearPending()
        this.applyAnswer(res, element, sent)
      } catch (err) {
        if (generation !== this.generation) return
        await this.refuseAnswer(err, element, sent)
      }
    },

    /** "I don't know": a hint (docs/16 §5.2); the server shows the answer. */
    async skip(): Promise<void> {
      await this.answer({ skip: true })
    },

    /** The id of the request for `key`: the one still pending for it (a repeat), else a new one. */
    requestIdFor(key: string): string {
      if (this.pendingRequestId === null || this.pendingKey !== key) {
        this.pendingRequestId = newRequestId()
        this.pendingKey = key
        this.pendingAnswer = null
      }
      return this.pendingRequestId
    },

    clearPending(): void {
      this.pendingRequestId = null
      this.pendingKey = null
      this.pendingAnswer = null
    },

    applyAnswer(res: PracticeAnswerResponse, element: PracticeElement, payload: PracticeAnswerPayload): void {
      const outcome: PracticeOutcome = {
        element,
        answer: payload,
        verdict: res.verdict,
        firstAttempt: res.first_attempt,
        reveal: res.reveal,
        pair: res.pair,
        roundDone: res.round_done === true,
      }
      this.session = res.session
      this.outcome = outcome
      this.syncHub(res.session)
      // A match of pairs inside a round: the board goes on (it reads the new `matched` / `mismatches` from the element).
      this.status = element.kind === 'pairs_round' && !outcome.roundDone ? 'ready' : 'verdict'
    },

    /** The hub the learner goes back to must not show a session that is over, or an old count, as the one to continue. */
    syncHub(session: PracticeSession): void {
      const active = this.hub?.active_session
      if (this.hub === null || !active || active.id !== session.id) return
      this.hub.active_session = session.status === 'active' ? activeOf(session) : null
    },

    /** The answer was not counted as given. A conflict re-reads the session; a lost reply is left for a repeat. */
    async refuseAnswer(err: unknown, element: PracticeElement, payload: PracticeAnswerPayload): Promise<void> {
      if (isUncertain(err)) {
        // The server may or may not have it: the same answer will carry the same id and the same content, and it is counted once.
        // The screen keeps what was sent where it was (a pick, a line, a text) and offers to send it again - not to change it.
        this.status = 'ready'
        this.actionError = errorMessage(err, 'practice')
        return
      }
      this.clearPending()

      if (err instanceof ApiClientError) {
        const generation = this.generation
        if (err.code === 'element_not_current' || err.code === 'pair_already_matched') {
          // Another tab (or a repeat) got there first: show what is true now.
          if (err.code === 'element_not_current') this.actionError = errorMessage(err, 'practice')
          const fresh = await this.reread(generation)
          if (fresh !== null) this.applySession(fresh)
          return
        }
        if (err.code === 'answer_already_accepted') {
          const fresh = await this.reread(generation)
          if (fresh === null) return
          const verdict = err.details?.verdict
          this.applySession(fresh)
          // The verdict of the answer that was counted is told; the explanation is not at hand, only the verdict, and the
          // answer that counted is not known either (it is NOT what was just sent): the screen says "an answer sent earlier
          // was counted", and shows no right answer and no pick. "Next" goes on to what the re-read session has.
          if (isVerdict(verdict) && element.kind !== 'pairs_round') {
            this.outcome = { element, answer: payload, verdict, firstAttempt: false, reveal: null, pair: null, roundDone: false, earlier: true }
            this.status = 'verdict'
          }
          return
        }
        if (err.code === 'practice_pro_required') {
          this.paywall = true
          this.status = 'ready'
          return
        }
        if (err.kind === 'validation' || err.kind === 'conflict') {
          // The server refused this answer, not the session: the learner can still answer.
          this.status = 'ready'
          this.actionError = errorMessage(err, 'practice')
          return
        }
      }
      this.handleError(err)
    },

    /** The session as the server has it now; null (and the error shown) when it cannot be read. */
    async reread(generation: number): Promise<PracticeSession | null> {
      if (this.sessionId === null) return null
      try {
        const session = await api.practice.get(this.sessionId)
        return generation === this.generation ? session : null
      } catch (err) {
        if (generation === this.generation) this.handleError(err)
        return null
      }
    },

    /** The learner has seen the verdict: the next element, or the summary. */
    continueAfterVerdict(): void {
      if (this.status !== 'verdict') return
      this.outcome = null
      const session = this.session
      if (session === null || session.status === 'finished' || session.current === null) {
        this.status = 'finished'
        return
      }
      this.elementSeq += 1
      this.elementShownAt = Date.now()
      this.status = 'ready'
    },

    /**
     * The clip of a dictation does not play: swap the task for another, no penalty (docs/16 §10.4). True when the
     * element was replaced; false when it was refused (no replacements left, …) — the reason is in `actionError`.
     */
    async replace(): Promise<boolean> {
      const session = this.session
      const element = session?.current ?? null
      if (this.status !== 'ready' || session === null || element === null || element.kind !== 'dictation') return false

      const generation = this.generation
      // The replacement has an id of its own: an answer of this element that got no reply keeps its id (and may still be repeated).
      const replacing = this.replacing?.elementId === element.id ? this.replacing : { elementId: element.id, requestId: newRequestId() }
      this.replacing = replacing
      const requestId = replacing.requestId
      this.status = 'submitting'
      this.actionError = ''
      try {
        const replaced = await api.practice.replace(session.id, {
          request_id: requestId,
          element_id: element.id,
          reason: 'audio_unavailable',
        })
        if (generation !== this.generation) return false
        this.replacing = null
        this.clearPending() // the element that was answered, or not, is gone
        this.releaseAudio()
        this.applySession(replaced)
        return true
      } catch (err) {
        if (generation !== this.generation) return false
        if (isUncertain(err)) {
          this.status = 'ready'
          this.actionError = errorMessage(err, 'practice')
          return false
        }
        this.replacing = null
        if (err instanceof ApiClientError && err.code === 'replace_not_allowed' && err.details?.reason === 'not_current') {
          // Another tab went on: the element on this screen is not the current one. Nothing the learner did is wrong, so there is no
          // message - the screen follows the server.
          const fresh = await this.reread(generation)
          if (fresh !== null) this.applySession(fresh)
          return false
        }
        if (err instanceof ApiClientError && (err.code === 'replace_not_allowed' || err.kind === 'conflict' || err.kind === 'validation')) {
          this.status = 'ready'
          this.actionError = errorMessage(err, 'practice')
          return false
        }
        this.handleError(err)
        return false
      }
    },

    /** "Repeat mistakes": a new session out of the mistakes of the finished one. Returns its id, or null (see `openError`). */
    async retry(): Promise<number | null> {
      const session = this.session
      if (session === null || session.status !== 'finished') return null
      return this.open(
        {
          mode: session.mode,
          course: session.course.slug,
          lesson_id: session.lesson?.id ?? null,
          source: 'retry',
          retry_of: session.id,
        },
        { canAsk: false },
      )
    },

    /** "Again": a new session of the same mode in the same place. */
    async again(): Promise<number | null> {
      const session = this.session
      if (session === null || session.status !== 'finished') return null
      return this.open(
        {
          mode: session.mode,
          course: session.course.slug,
          lesson_id: session.lesson?.id ?? null,
          source: 'hub',
        },
        { canAsk: false },
      )
    },

    /** The `blob:` URL of the clip is given back to the browser, the slot is empty again. */
    releaseAudio(): void {
      if (this.audio.blobUrl !== null) URL.revokeObjectURL(this.audio.blobUrl)
      this.audio.blobUrl = null
      this.audio.state = 'idle'
    },

    handleError(err: unknown): void {
      if (err instanceof ApiClientError && err.kind === 'gone') {
        this.status = 'closed'
      } else if (err instanceof ApiClientError && err.code === 'practice_pro_required') {
        // Free learner, `plan = pro` switched on in the middle: the paywall opens over the session, which stays as it is.
        this.paywall = true
        this.status = this.session !== null && this.session.status === 'active' ? 'ready' : 'error'
      } else {
        this.status = 'error'
      }
      this.errorMessage = errorMessage(err, 'practice')
    },
  },
})
