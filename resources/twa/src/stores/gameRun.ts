import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { newRequestId } from '@/composables/requestId'
import { t } from '@/i18n'
import { errorMessage } from '@/i18n/errors'
import type {
  GameAnswerPayload,
  GameAnswerResponse,
  GameElement,
  GameFinishReason,
  GameReveal,
  GameRunEvent,
  GameRunView,
  GameSummary,
} from '@/api/gameRunTypes'
import type { GameBoosterLimitHit } from '@/api/gameShopTypes'
import type { PracticeVerdict } from '@/api/types'

/**
 * loading (the run is being read) → event (the banner of a sandstorm / oasis / bazaar, ~1.5 s) → playing ⇄ submitting → reveal
 * (the verdict and the explanation, "Next") → … → summary; closed (410, or a run that was left / ran out of time) and error end it early.
 */
export type GameRunStatus =
  | 'idle'
  | 'loading'
  | 'event'
  | 'playing'
  | 'submitting' // an answer, a hint or the re-read after a conflict is on its way
  | 'reveal'
  | 'summary'
  | 'closed'
  | 'error'

/** What came back for the last answer: kept for the reveal (and, for pairs, for the board). */
export interface GameOutcome {
  /** The element as it was answered. */
  element: GameElement
  answer: GameAnswerPayload
  verdict: PracticeVerdict
  points: number
  reveal: GameReveal | null
  flaskLost: boolean
  combo: number
  /** `oasis`: a flask came back with this answer. */
  eventApplied: GameRunEvent | null
  /** Pairs: the match that was tried, and whether it completed the round. */
  pair: { left_id: string; right_id: string } | null
  roundDone: boolean
  /** The place of the answered element: the header keeps it while the reveal is read (the run has moved on). */
  position: number
  /** The server had an answer for this element already (409 `answer_already_accepted`): `verdict` is the one it counted, nothing else is known. */
  earlier?: boolean
}

interface GameRunState {
  runId: number | null
  run: GameRunView | null
  status: GameRunStatus
  errorMessage: string
  /** The refusal of the last action that leaves the learner on the same screen (localized): shown as a toast. */
  actionError: string
  outcome: GameOutcome | null
  /** The coins the learner has, once the server has told (a hint's reply, a refusal for lack of them); null: not known. */
  coins: number | null
  /** Why a closed run is closed (`details.finish_reason` of the 410, or the run's own); null when the server did not say. */
  closedReason: GameFinishReason | null
  /** The ids of the elements whose banner was shown: a re-read of the same element does not show it again. */
  eventShown: string[]
  /**
   * The `request_id` of the answer that is on its way or failed without a reply (network, 5xx, 429). It lives until the server has
   * answered for good, and a repeat takes the same id, so the server counts it once. One answer per element (a pair is an attempt of its
   * own: `pendingPairs`).
   */
  pendingRequestId: string | null
  pendingKey: string | null
  /** What the pending request carries: a repeat sends THIS, whatever the screen hands over now. */
  pendingAnswer: GameAnswerPayload | null
  /** Pairs: the `request_id` of every match of the current round that got no reply, by "left_id|right_id". */
  pendingPairs: { elementId: string; ids: Record<string, string> } | null
  /** The hint that is on its way or got no reply: its own id, apart from the answer's. */
  pendingHint: { elementId: string; requestId: string } | null
  /** The booster that is on its way or got no reply: its own id (one per booster and element), apart from the answer's. */
  pendingBooster: { key: string; elementId: string; requestId: string } | null
  /** 409 `booster_limit_reached`: the numbers for the paywall; the screen shows it and clears it. */
  boosterLimit: GameBoosterLimitHit | null
  abandoning: boolean
  /** Counts the runs the store has been reset for: a reply that comes back for an earlier one is dropped. */
  generation: number
  /** Grows with every new element (and after a refusal that left nothing counted): the screen keys the task component by it. */
  elementSeq: number
  /** Epoch ms when the element appeared: `time_spent_ms` is telemetry measured from it. */
  elementShownAt: number
}

/** Telemetry only (docs/16 §2.6): an hour in front of an element says nothing more than ten minutes. */
const MAX_TIME_SPENT_MS = 600_000

/** A failure after which the server may or may not have counted the request: the same request is repeated as it was. */
function isUncertain(err: unknown): boolean {
  return err instanceof ApiClientError && (err.kind === 'network' || err.kind === 'server' || err.kind === 'rate_limited')
}

const FINISH_REASONS: readonly string[] = ['completed', 'failed', 'abandoned', 'expired']
const finishReasonOf = (value: unknown): GameFinishReason | null => (typeof value === 'string' && FINISH_REASONS.includes(value) ? (value as GameFinishReason) : null)

const isVerdict = (value: unknown): value is PracticeVerdict =>
  value === 'correct' || value === 'typo' || value === 'wrong' || value === 'skipped'

/** "left_id|right_id" of a match of pairs: the key of its request; the whole payload when it is not a match. */
function pairKey(payload: GameAnswerPayload): string {
  return 'left_id' in payload ? `${payload.left_id}|${payload.right_id}` : JSON.stringify(payload)
}

/**
 * A run of "Karvon" (docs/18 §12.9, docs/05 §5b.3): read it, answer element by element, see the verdict, finish with the summary.
 *
 * The server grades everything; this store keeps what the screen needs and defends the learner's answer, as `practice` does:
 * - one `request_id` per answer, kept across a network error so that a repeat counts once (`pendingRequestId`), with the very answer
 *   that went first (`pendingAnswer`);
 * - `generation`: every reply is dropped if the learner has moved to another run meanwhile;
 * - `status` ≠ `playing` ignores a second tap, so a double tap is one request;
 * - 409 `element_not_current` re-reads the run; 410 is `closed`.
 *
 * Pairs go on in the same round after each match (`playing`); every other kind, and the end of a round, stops at `reveal` until
 * `continueAfterReveal()`. An element with an event (sandstorm / oasis / bazaar) is preceded by its banner (`event`).
 */
export const useGameRunStore = defineStore('gameRun', {
  state: (): GameRunState => ({
    runId: null,
    run: null,
    status: 'idle',
    errorMessage: '',
    actionError: '',
    outcome: null,
    coins: null,
    closedReason: null,
    eventShown: [],
    pendingRequestId: null,
    pendingKey: null,
    pendingAnswer: null,
    pendingPairs: null,
    pendingHint: null,
    pendingBooster: null,
    boosterLimit: null,
    abandoning: false,
    generation: 0,
    elementSeq: 0,
    elementShownAt: 0,
  }),

  getters: {
    /** The element to answer. */
    element: (s): GameElement | null => s.run?.current?.element ?? null,

    /** The event of the element on screen. */
    event: (s): GameRunEvent | null => s.run?.current?.event ?? null,

    summary: (s): GameSummary | null => s.run?.summary ?? null,

    /** The place shown in the header: the answered element while its reveal is read, else the current one. */
    position: (s): number => (s.status === 'reveal' && s.outcome !== null ? s.outcome.position : (s.run?.position ?? 0)),

    /** The bazaar's hint, when it can be bought now. */
    hintOffer: (s): { price: number } | null =>
      s.status === 'playing' && s.run !== null && s.run.current !== null && s.run.hint.available ? { price: s.run.hint.price } : null,
  },

  actions: {
    /** Enters a run: reads it as the server has it. */
    async load(runId: number): Promise<void> {
      this.resetForRun(runId)
      const generation = this.generation
      this.status = 'loading'
      try {
        const run = await api.gameRuns.get(runId)
        if (generation !== this.generation) return
        this.applyRun(run)
      } catch (err) {
        if (generation !== this.generation) return
        this.handleError(err)
      }
    },

    resetForRun(runId: number): void {
      this.generation += 1
      this.runId = runId
      this.run = null
      this.status = 'idle'
      this.errorMessage = ''
      this.actionError = ''
      this.outcome = null
      this.coins = null
      this.closedReason = null
      this.eventShown = []
      this.abandoning = false
      this.clearPending()
      this.pendingHint = null
      this.pendingBooster = null
      this.boosterLimit = null
      this.elementShownAt = 0
    },

    /** Takes the run as the server has it: the banner of its event, the element to answer, or the end. */
    applyRun(run: GameRunView): void {
      const previous = this.run?.current?.element.id ?? null
      this.run = run
      this.runId = run.id
      this.outcome = null
      const current = run.current
      if (run.status === 'finished' || current === null) {
        this.finish(run)
        return
      }
      // The same element again (a re-read after a conflict, the hint) is not a new one: the task component keeps what the learner did.
      if (current.element.id !== previous) {
        this.elementSeq += 1
        this.elementShownAt = Date.now()
      }
      this.status = current.event !== null && !this.eventShown.includes(current.element.id) ? 'event' : 'playing'
    },

    /** The run is over: its summary, or - for a run that was left or ran out of time - the closed screen (it does not count). */
    finish(run: GameRunView): void {
      const reason = run.finish_reason
      if (reason === 'abandoned' || reason === 'expired') {
        this.closedReason = reason
        this.status = 'closed'
        return
      }
      this.status = 'summary'
    },

    /** The banner of the event was shown long enough: the task. */
    dismissEvent(): void {
      if (this.status !== 'event') return
      const id = this.run?.current?.element.id
      if (id !== undefined) this.eventShown.push(id)
      this.elementShownAt = Date.now()
      this.status = 'playing'
    },

    clearActionError(): void {
      this.actionError = ''
    },

    // ------------------------------------------------------------ answer

    /**
     * One answer. Pairs: one match (`{left_id, right_id}`). A second call while one is on its way does nothing.
     *
     * Every other kind has ONE answer per element: its request id belongs to the element, and after a lost reply the repeat carries the
     * same id AND the same answer - the one that went first - so the server, which may have counted it, tells its verdict again.
     */
    async answer(payload: GameAnswerPayload): Promise<void> {
      const run = this.run
      const element = run?.current?.element ?? null
      if (this.status !== 'playing' || run === null || element === null) return

      const generation = this.generation
      const single = element.kind !== 'pairs_round'
      const pair = single ? null : pairKey(payload)
      const sent = single && this.pendingKey === element.id && this.pendingAnswer !== null ? this.pendingAnswer : payload
      const requestId = pair === null ? this.requestIdFor(element.id) : this.pairRequestId(element.id, pair)
      if (single) this.pendingAnswer = sent
      const timeSpent = Math.min(MAX_TIME_SPENT_MS, Math.max(0, Date.now() - this.elementShownAt))
      const position = run.position
      this.status = 'submitting'
      this.actionError = ''
      try {
        const res = await api.gameRuns.answer(run.id, { request_id: requestId, element_id: element.id, time_spent_ms: timeSpent, answer: sent })
        if (generation !== this.generation) return
        this.clearPending(pair)
        this.applyAnswer(res, element, sent, position)
      } catch (err) {
        if (generation !== this.generation) return
        await this.refuseAnswer(err, element, sent, position)
      }
    },

    /** "I don't know". */
    async skip(): Promise<void> {
      await this.answer({ skip: true })
    },

    /** The id of the request for `key` (the element): the one still pending for it (a repeat), else a new one. */
    requestIdFor(key: string): string {
      if (this.pendingRequestId === null || this.pendingKey !== key) {
        this.pendingRequestId = newRequestId()
        this.pendingKey = key
        this.pendingAnswer = null
      }
      return this.pendingRequestId
    },

    /** The id of the request for the match `pair` of the round `elementId`: the one still pending for THAT pair, else a new one. */
    pairRequestId(elementId: string, pair: string): string {
      if (this.pendingPairs === null || this.pendingPairs.elementId !== elementId) this.pendingPairs = { elementId, ids: {} }
      return (this.pendingPairs.ids[pair] ??= newRequestId())
    },

    /** The server has answered for good (or the run is gone): the request is forgotten - for a pair `pair` only its own. */
    clearPending(pair: string | null = null): void {
      this.pendingRequestId = null
      this.pendingKey = null
      this.pendingAnswer = null
      if (pair !== null && this.pendingPairs !== null) {
        delete this.pendingPairs.ids[pair]
        if (Object.keys(this.pendingPairs.ids).length > 0) return
      }
      this.pendingPairs = null
    },

    applyAnswer(res: GameAnswerResponse, element: GameElement, payload: GameAnswerPayload, position: number): void {
      const outcome: GameOutcome = {
        element,
        answer: payload,
        verdict: res.verdict,
        points: res.points,
        reveal: res.reveal,
        flaskLost: res.flask_lost,
        combo: res.combo,
        eventApplied: res.event_applied,
        pair: res.pair ?? null,
        roundDone: res.round_done === true,
        position,
      }
      this.run = res.run
      this.outcome = outcome
      // A match of pairs inside a round: the board goes on (it reads the new `matched` / `mismatches` from the element) - unless the run ended on it.
      this.status = element.kind === 'pairs_round' && !outcome.roundDone && res.run.status === 'active' ? 'playing' : 'reveal'
    },

    /** The answer was not counted as given. A conflict re-reads the run; a lost reply is left for a repeat. */
    async refuseAnswer(err: unknown, element: GameElement, payload: GameAnswerPayload, position: number): Promise<void> {
      if (isUncertain(err)) {
        // The server may or may not have it: the same answer will carry the same id and the same content, and it is counted once.
        this.status = 'playing'
        this.actionError = errorMessage(err, 'game')
        return
      }
      this.clearPending(element.kind === 'pairs_round' ? pairKey(payload) : null)

      if (err instanceof ApiClientError) {
        const generation = this.generation
        if (err.code === 'element_not_current' || err.code === 'unknown_element_id' || err.code === 'pair_already_matched') {
          // Another tab (or a repeat) got there first: show what is true now.
          if (err.code !== 'pair_already_matched') this.actionError = t('error.code.element_not_current')
          const fresh = await this.reread(generation)
          if (fresh !== null) this.applyRun(fresh)
          return
        }
        if (err.code === 'answer_already_accepted') {
          const fresh = await this.reread(generation)
          if (fresh === null) return
          const verdict = err.details?.verdict
          this.applyRun(fresh)
          // The verdict of the answer that was counted is told; its explanation and the answer itself are not at hand.
          if (isVerdict(verdict) && element.kind !== 'pairs_round') {
            this.outcome = {
              element,
              answer: payload,
              verdict,
              points: 0,
              reveal: null,
              flaskLost: false,
              combo: fresh.combo,
              eventApplied: null,
              pair: null,
              roundDone: false,
              position,
              earlier: true,
            }
            this.status = 'reveal'
          }
          return
        }
        if (err.kind === 'validation' || err.kind === 'conflict') {
          // The server refused this answer, not the run: nothing was counted, the learner can answer otherwise and the task starts afresh.
          this.status = 'playing'
          this.actionError = errorMessage(err, 'game')
          this.elementSeq += 1
          return
        }
      }
      this.handleError(err)
    },

    /** The run as the server has it now; null (and the error shown) when it cannot be read. */
    async reread(generation: number): Promise<GameRunView | null> {
      if (this.runId === null) return null
      try {
        const run = await api.gameRuns.get(this.runId)
        return generation === this.generation ? run : null
      } catch (err) {
        if (generation === this.generation) this.handleError(err)
        return null
      }
    },

    /** The learner has seen the reveal: the next element (with its banner), or the end. */
    continueAfterReveal(): void {
      if (this.status !== 'reveal') return
      this.outcome = null
      const run = this.run
      if (run === null || run.status === 'finished' || run.current === null) {
        if (run !== null) this.finish(run)
        else this.status = 'summary'
        return
      }
      this.elementSeq += 1
      this.elementShownAt = Date.now()
      this.status = run.current.event !== null && !this.eventShown.includes(run.current.element.id) ? 'event' : 'playing'
    },

    // -------------------------------------------------------------- hint

    /**
     * The hint of the bazaar (docs/18 §12.5.2): coins for options taken away or the first letters. The element stays as it is - the
     * task component keeps what was typed - with the hint on it. A lost reply is repeated under the same id, so the coins go once.
     */
    async hint(): Promise<void> {
      const run = this.run
      const element = run?.current?.element ?? null
      if (this.hintOffer === null || run === null || element === null) return

      const generation = this.generation
      const pending = this.pendingHint?.elementId === element.id ? this.pendingHint : { elementId: element.id, requestId: newRequestId() }
      this.pendingHint = pending
      this.status = 'submitting'
      this.actionError = ''
      try {
        const res = await api.gameRuns.hint(run.id, { request_id: pending.requestId, element_id: element.id })
        if (generation !== this.generation) return
        this.pendingHint = null
        this.coins = res.coins.balance
        this.applyRun(res.run)
      } catch (err) {
        if (generation !== this.generation) return
        if (isUncertain(err)) {
          this.status = 'playing'
          this.actionError = errorMessage(err, 'game')
          // The server may have applied (and charged) the hint before the reply was lost: read the run again before the learner
          // answers, so a hint that is there is shown. The request stays pending: a repeat carries the same id.
          try {
            const fresh = await api.gameRuns.get(run.id)
            if (generation === this.generation && this.status === 'playing') this.applyRun(fresh)
          } catch {
            // the connection is still down: the learner sees the error above and may repeat the hint
          }
          return
        }
        this.pendingHint = null
        if (err instanceof ApiClientError) {
          if (err.code === 'coins_insufficient') {
            const balance = err.details?.balance
            if (typeof balance === 'number') this.coins = balance
            this.status = 'playing'
            this.actionError = errorMessage(err, 'game')
            return
          }
          if (err.code === 'hint_not_available' || err.code === 'element_not_current') {
            // The button was out of date: the run is read again and has none.
            this.actionError = errorMessage(err, 'game')
            const fresh = await this.reread(generation)
            if (fresh !== null) this.applyRun(fresh)
            return
          }
        }
        this.handleError(err)
      }
    },

    // ----------------------------------------------------------- booster

    /**
     * A booster of the learner's stock (docs/18 §12.8): `hint` for the current element, `extra_flask` / `second_chance` for the whole run.
     * The reply is the run; the element stays as it is (the task keeps what was typed). A lost reply is repeated under the same id.
     * 409 `booster_limit_reached` leaves `boosterLimit` for the paywall; the other refusals are a toast, and a conflict that says the row was
     * out of date reads the run again.
     */
    async useBooster(key: string, effect: string): Promise<void> {
      const run = this.run
      const element = run?.current?.element ?? null
      if (this.status !== 'playing' || run === null || element === null) return

      const generation = this.generation
      const pending = this.pendingBooster?.key === key && this.pendingBooster.elementId === element.id ? this.pendingBooster : { key, elementId: element.id, requestId: newRequestId() }
      this.pendingBooster = pending
      this.status = 'submitting'
      this.actionError = ''
      try {
        const res = await api.gameRuns.booster(run.id, {
          request_id: pending.requestId,
          item_key: key,
          ...(effect === 'hint' ? { element_id: element.id } : {}),
        })
        if (generation !== this.generation) return
        this.pendingBooster = null
        this.applyRun(res)
      } catch (err) {
        if (generation !== this.generation) return
        if (isUncertain(err)) {
          this.status = 'playing'
          this.actionError = errorMessage(err, 'game')
          return
        }
        this.pendingBooster = null
        if (err instanceof ApiClientError) {
          if (err.code === 'booster_limit_reached') {
            const { limit, used, resets_at: resetsAt, upsell } = err.details ?? {}
            this.status = 'playing'
            // `upsell: false` - the learner is Pro already: there is nothing to sell, the screen tells when the new boosters come.
            if (typeof limit === 'number' && typeof used === 'number' && typeof resetsAt === 'string') this.boosterLimit = { limit, used, resets_at: resetsAt, upsell: upsell !== false }
            else this.actionError = errorMessage(err, 'game')
            return
          }
          if (['booster_not_owned', 'booster_already_used', 'booster_not_applicable', 'hint_not_available', 'element_not_current', 'answer_already_accepted'].includes(err.code)) {
            this.actionError = errorMessage(err, 'game')
            const fresh = await this.reread(generation)
            if (fresh !== null) this.applyRun(fresh)
            return
          }
          if (err.kind === 'validation' || err.kind === 'conflict' || err.kind === 'not_found') {
            this.status = 'playing'
            this.actionError = errorMessage(err, 'game')
            return
          }
        }
        this.handleError(err)
      }
    },

    clearBoosterLimit(): void {
      this.boosterLimit = null
    },

    // ------------------------------------------------------------- leave

    /** "Leave": the run does not count. True when it is over (also when it had ended already: 410). */
    async abandon(): Promise<boolean> {
      const run = this.run
      if (run === null || this.abandoning) return false
      const generation = this.generation
      this.abandoning = true
      this.actionError = ''
      try {
        await api.gameRuns.abandon(run.id)
        return generation === this.generation
      } catch (err) {
        if (generation !== this.generation) return false
        if (err instanceof ApiClientError && err.kind === 'gone') return true
        this.actionError = errorMessage(err, 'game')
        return false
      } finally {
        if (generation === this.generation) this.abandoning = false
      }
    },

    handleError(err: unknown): void {
      if (err instanceof ApiClientError && err.kind === 'gone') {
        this.closedReason = finishReasonOf(err.details?.finish_reason)
        this.status = 'closed'
      } else {
        this.status = 'error'
      }
      this.errorMessage = errorMessage(err, 'game')
    },
  },
})
