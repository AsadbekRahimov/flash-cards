import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { cupFailure, cupTaskElement } from '@/composables/cupInfo'
import { newRequestId } from '@/composables/requestId'
import { t } from '@/i18n'
import { errorMessage } from '@/i18n/errors'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupsStore } from '@/stores/cups'
import type { GameAnswerPayload, GameWordElement } from '@/api/gameRunTypes'
import type { CupBoardResponse, CupRound, PracticeVerdict } from '@/api/types'
import type { GameOutcome } from '@/stores/gameRun'

/**
 * loading (the table and my day) → confirm ("one attempt, 10 tasks, 10 minutes", `start()`) → starting → playing ⇄ submitting → reveal
 * (the verdict, "Next") → … → summary. An attempt that is on (another tab, a reload) skips the confirmation; one that is over opens on its
 * summary. `unavailable` - no round for the learner now (`notice` says why); `closed` - 410; `not_found` - 404 `cup_round_not_found`
 * (the screen leads to the cup); `error` - "Retry".
 */
export type CupRoundStatus =
  | 'idle'
  | 'loading'
  | 'confirm'
  | 'starting'
  | 'playing'
  | 'submitting'
  | 'reveal'
  | 'summary'
  | 'unavailable'
  | 'closed'
  | 'not_found'
  | 'error'

interface CupRoundState {
  cupId: number | null
  board: CupBoardResponse | null
  round: CupRound | null
  status: CupRoundStatus
  /** Why there is no round (`unavailable`) or what went wrong (`error`, `closed`); localized. */
  notice: string
  /** A refusal that leaves the learner where he is (localized): shown as a toast. */
  actionError: string
  outcome: GameOutcome | null
  /**
   * The `request_id` of the answer on its way or lost without a reply (network, 5xx, 429): a repeat takes the same id AND the same
   * answer, so the server counts it once (docs/04 A.23). One per task.
   */
  pendingRequestId: string | null
  pendingKey: string | null
  pendingAnswer: GameAnswerPayload | null
  /** The re-read after the deadline was asked for this attempt already. */
  expiryChecked: number | null
  /** Counts the cups the store was reset for: a reply for an earlier one is dropped. */
  generation: number
  /** Grows with every new task: the screen keys the task component by it. */
  elementSeq: number
}

/** A failure after which the server may or may not have counted the answer: the same request is repeated as it was. */
function isUncertain(err: unknown): boolean {
  return err instanceof ApiClientError && (err.kind === 'network' || err.kind === 'server' || err.kind === 'rate_limited')
}

const isVerdict = (value: unknown): value is PracticeVerdict =>
  value === 'correct' || value === 'typo' || value === 'wrong' || value === 'skipped'

/**
 * The round of the day of a cup (docs/33 §5.2, §7.1, docs/04 A.23). The server decides everything - the tasks, the verdict, the points
 * and the deadline; this store keeps what the screen needs and defends the answer as the run of the game does:
 * - one `request_id` per task, kept across a lost reply (`pendingRequestId` / `pendingAnswer`);
 * - `status` ≠ `playing` ignores a second tap (a double tap is one request); `starting` - a second "Start" too;
 * - 409 `cup_round_expired` / `cup_round_done` / `element_not_current` / `answer_already_accepted` read the attempt again;
 * - 404 `cup_round_not_found` - `not_found`; 410 `cup_closed` - `closed`.
 */
export const useCupRoundStore = defineStore('cupRound', {
  state: (): CupRoundState => ({
    cupId: null,
    board: null,
    round: null,
    status: 'idle',
    notice: '',
    actionError: '',
    outcome: null,
    pendingRequestId: null,
    pendingKey: null,
    pendingAnswer: null,
    expiryChecked: null,
    generation: 0,
    elementSeq: 0,
  }),

  getters: {
    /** The task to answer, as the components of the game take it. */
    element: (s): GameWordElement | null => (s.round?.current ? cupTaskElement(s.round.current) : null),
    /** The place shown in the header: the answered task while its verdict is read, else the current one. */
    position: (s): number => (s.status === 'reveal' && s.outcome !== null ? s.outcome.position : (s.round?.position ?? 0)),
  },

  actions: {
    reset(cupId: number | null): void {
      this.generation += 1
      this.cupId = cupId
      this.board = null
      this.round = null
      this.status = 'idle'
      this.notice = ''
      this.actionError = ''
      this.outcome = null
      this.expiryChecked = null
      this.clearPending()
    },

    /**
     * Enters the round of the day of `cupId`: my day from the table (the one the cup screen has just read, or a new read), then the
     * attempt that is on or over, or the confirmation before a new one.
     */
    async enter(cupId: number): Promise<void> {
      this.reset(cupId)
      const generation = this.generation
      this.status = 'loading'
      const boards = useCupBoardStore()
      const board = boards.isFreshFor(cupId) ? boards.board : await boards.load(cupId)
      if (generation !== this.generation) return
      if (board === null) {
        const failure = boards.failure
        this.notice = failure?.message ?? t('error.generic')
        this.status = failure !== null && !failure.retry ? 'unavailable' : 'error'
        return
      }
      this.board = board
      const today = board.me?.today ?? null
      if (board.me === null || today === null) {
        this.notice = t('cup.round.not_participant')
        this.status = 'unavailable'
        return
      }
      if ((today.state === 'active' || today.state === 'done') && today.attempt_id !== null) {
        await this.readAttempt(today.attempt_id, generation)
        return
      }
      if (today.state === 'not_started') {
        this.status = 'confirm'
        return
      }
      this.notice = t('cup.round.not_available')
      this.status = 'unavailable'
    },

    /** Reads the attempt `attemptId` (resume, or the summary of one that is over). */
    async readAttempt(attemptId: number, generation: number): Promise<void> {
      try {
        const round = await api.cups.round(attemptId)
        if (generation !== this.generation) return
        this.applyRound(round)
      } catch (err) {
        if (generation !== this.generation) return
        this.handleError(err)
      }
    },

    /** "Start the round": a new attempt (201) or the one that is on already (200, another tab). A second tap does nothing. */
    async start(): Promise<void> {
      const cupId = this.cupId
      if (this.status !== 'confirm' || cupId === null) return
      const generation = this.generation
      this.status = 'starting'
      this.actionError = ''
      try {
        const round = await api.cups.startRound(cupId)
        if (generation !== this.generation) return
        useCupsStore().invalidate()
        useCupBoardStore().invalidate()
        this.applyRound(round)
      } catch (err) {
        if (generation !== this.generation) return
        if (err instanceof ApiClientError && err.code === 'cup_round_done') {
          // Played in another tab meanwhile: its summary.
          const board = await useCupBoardStore().load(cupId)
          if (generation !== this.generation) return
          const attemptId = board?.me?.today.attempt_id ?? null
          if (attemptId !== null) {
            this.board = board
            await this.readAttempt(attemptId, generation)
            return
          }
        }
        if (err instanceof ApiClientError && err.kind === 'gone') {
          this.handleError(err)
          return
        }
        const failure = cupFailure(err)
        if (failure.retry) {
          // Telegram busy or down, the connection lost: the learner may start again (the server returns the same attempt).
          this.status = 'confirm'
          this.actionError = failure.message
          return
        }
        this.notice = failure.message
        this.status = 'unavailable'
      }
    },

    /** Takes the attempt as the server has it: the next task, or the summary. */
    applyRound(round: CupRound): void {
      const previous = this.round?.current?.id ?? null
      this.round = round
      this.outcome = null
      if (round.status === 'finished' || round.current === null) {
        this.finish(round)
        return
      }
      if (round.current.id !== previous) this.elementSeq += 1
      this.status = 'playing'
    },

    /** The attempt is over: the summary; the table and "my cups" are read again for the place. */
    finish(round: CupRound): void {
      this.status = 'summary'
      this.clearPending()
      useCupsStore().markPlayed(round.cup_id, round.day)
      useCupBoardStore().invalidate()
    },

    clearActionError(): void {
      this.actionError = ''
    },

    /**
     * One answer: `{option_id}`, `{text}` or `{skip: true}`. ONE per task: its request id belongs to the task, and after a lost reply the
     * repeat carries the same id AND the answer that went first.
     */
    async answer(payload: GameAnswerPayload): Promise<void> {
      const round = this.round
      const element = this.element
      if (this.status !== 'playing' || round === null || element === null) return

      const generation = this.generation
      const sent = this.pendingKey === element.id && this.pendingAnswer !== null ? this.pendingAnswer : payload
      const requestId = this.requestIdFor(element.id)
      this.pendingAnswer = sent
      const position = round.position
      this.status = 'submitting'
      this.actionError = ''
      try {
        const res = await api.cups.answer(round.attempt_id, { request_id: requestId, element_id: element.id, answer: sent as { option_id?: string; text?: string; skip?: boolean } })
        if (generation !== this.generation) return
        this.clearPending()
        this.round = res.round
        this.outcome = {
          element,
          answer: sent,
          verdict: isVerdict(res.verdict) ? res.verdict : 'wrong',
          points: res.points,
          reveal: (res.reveal ?? null) as GameOutcome['reveal'],
          flaskLost: false,
          combo: 0,
          eventApplied: null,
          pair: null,
          roundDone: false,
          position,
        }
        this.status = 'reveal'
      } catch (err) {
        if (generation !== this.generation) return
        await this.refuseAnswer(err, element, sent, position)
      }
    },

    /** "I don't know". */
    async skip(): Promise<void> {
      await this.answer({ skip: true })
    },

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

    /** The answer was not counted as given: a lost reply waits for a repeat; a conflict reads the attempt again. */
    async refuseAnswer(err: unknown, element: GameWordElement, payload: GameAnswerPayload, position: number): Promise<void> {
      if (isUncertain(err)) {
        this.status = 'playing'
        this.actionError = errorMessage(err, 'game')
        return
      }
      this.clearPending()
      const generation = this.generation
      if (err instanceof ApiClientError) {
        switch (err.code) {
          case 'cup_round_expired':
          case 'cup_round_done':
          case 'element_not_current':
          case 'unknown_element_id': {
            if (err.code === 'element_not_current' || err.code === 'unknown_element_id') this.actionError = t('error.code.element_not_current')
            const fresh = await this.reread(generation)
            if (fresh !== null) this.applyRound(fresh)
            return
          }
          case 'answer_already_accepted': {
            const fresh = await this.reread(generation)
            if (fresh === null) return
            const verdict = err.details?.verdict
            this.applyRound(fresh)
            if (isVerdict(verdict)) {
              this.outcome = {
                element,
                answer: payload,
                verdict,
                points: 0,
                reveal: null,
                flaskLost: false,
                combo: 0,
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
        }
        if (err.kind === 'validation') {
          // The server refused this answer, not the attempt: nothing was counted, the task starts afresh.
          this.status = 'playing'
          this.actionError = errorMessage(err, 'game')
          this.elementSeq += 1
          return
        }
      }
      this.handleError(err)
    },

    /** The attempt as the server has it now; null (and the error shown) when it cannot be read. */
    async reread(generation: number): Promise<CupRound | null> {
      const attemptId = this.round?.attempt_id
      if (attemptId === undefined) return null
      try {
        const round = await api.cups.round(attemptId)
        return generation === this.generation ? round : null
      } catch (err) {
        if (generation === this.generation) this.handleError(err)
        return null
      }
    },

    /** The learner has read the verdict: the next task, or the summary. */
    continueAfterReveal(): void {
      if (this.status !== 'reveal') return
      this.outcome = null
      const round = this.round
      if (round === null || round.status === 'finished' || round.current === null) {
        if (round !== null) this.finish(round)
        else this.status = 'summary'
        return
      }
      this.elementSeq += 1
      this.status = 'playing'
    },

    /**
     * The deadline of the server has passed on the clock of the screen: the attempt is read once more (the server closes an attempt that
     * is over when it is read) - the screen never ends it itself. Once per attempt.
     */
    async checkExpiry(): Promise<void> {
      const round = this.round
      if (this.status !== 'playing' || round === null || this.expiryChecked === round.attempt_id) return
      this.expiryChecked = round.attempt_id
      const generation = this.generation
      const fresh = await this.reread(generation)
      if (fresh !== null && this.status === 'playing') this.applyRound(fresh)
    },

    handleError(err: unknown): void {
      if (err instanceof ApiClientError && err.code === 'cup_round_not_found') {
        this.status = 'not_found'
        return
      }
      if (err instanceof ApiClientError && err.kind === 'gone') {
        this.notice = t('cup.failure.closed')
        this.status = 'closed'
        return
      }
      const failure = cupFailure(err)
      this.notice = failure.message
      this.status = failure.retry ? 'error' : 'unavailable'
    },
  },
})
