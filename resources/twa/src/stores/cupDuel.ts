import { defineStore } from 'pinia'
import { api } from '@/api'
import { duelFailure, duelRated, localDuelFailure, type DuelFailure } from '@/composables/cupDuelInfo'
import { t } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupDuelsStore } from '@/stores/cupDuels'
import type { CupDuel, PracticeLimitHit } from '@/api/types'

export type CupDuelScreenStatus = 'idle' | 'loading' | 'ready' | 'failed'

export type CupDuelAction = 'accept' | 'decline' | 'cancel'

interface CupDuelState {
  cupId: number | null
  duelId: number | null
  duel: CupDuel | null
  status: CupDuelScreenStatus
  /** Why the screen has no duel: `disabled`, `not_found` (the screen goes to the cup), `unavailable`, `off`, the rest with "Retry". */
  failure: DuelFailure | null
  /** The action on its way: the buttons wait, a second tap does nothing. */
  acting: CupDuelAction | null
  /** A refused action (localized). */
  actionError: string
  /** `cup_duel_rating_changed`: "the conditions of the challenge changed" - the duel was read again (localized). */
  notice: string
  /** My match in the way of an acceptance (409 `battle_busy`). */
  busyBattleId: number | null
  /** The paywall of the free battles of the day. */
  limitHit: PracticeLimitHit | null
  /** Counts the screens the store was reset for: a reply for an earlier one (or one that came after the screen was left) is dropped. */
  generation: number
}

/** A failure the poll waits out: the beat slows down (`usePolling` doubles the pause), the screen keeps what it shows. */
const isTransient = (failure: DuelFailure): boolean => failure.uncertain

/**
 * One duel of a cup (docs/33 §5.7, docs/04 A.23d `GET …/duels/{duel}`): the challenge as the challenger waits on it, as the one challenged
 * (or anybody, for an open one) accepts or declines it, and how it ended. The screen polls it every 3 s while it is open (`poll`, one
 * request at a time); `accepted` with `battle_id` sends both players to the match at once. Entered by the id of the cup, or by the code of
 * the link in the chat (`GET /cups/by-code/{code}` first, docs/04 A.23d).
 */
export const useCupDuelStore = defineStore('cupDuel', {
  state: (): CupDuelState => ({
    cupId: null,
    duelId: null,
    duel: null,
    status: 'idle',
    failure: null,
    acting: null,
    actionError: '',
    notice: '',
    busyBattleId: null,
    limitHit: null,
    generation: 0,
  }),

  actions: {
    reset(): void {
      this.generation += 1
      this.cupId = null
      this.duelId = null
      this.duel = null
      this.status = 'idle'
      this.failure = null
      this.acting = null
      this.clearMessages()
      this.limitHit = null
    },

    clearMessages(): void {
      this.actionError = ''
      this.notice = ''
      this.busyBattleId = null
    },

    /** Opens the duel `duelId` of the cup `cupId`. Nothing is asked while the duels are off for the session. */
    async open(cupId: number, duelId: number): Promise<void> {
      this.reset()
      const generation = this.generation
      this.cupId = cupId
      this.duelId = duelId
      this.status = 'loading'
      await this.read(generation)
    },

    /**
     * The link of the chat (`TwaScreen::CupDuel`): the cup by its code first (the server answers a participant with its id), then the
     * duel. A member of the chat who has not joined, one who left, a stranger: the cup is not available. A duel id that is not a number is
     * a missing duel.
     */
    async openByCode(code: string, duelId: number | null): Promise<void> {
      this.reset()
      const generation = this.generation
      this.status = 'loading'
      if (useCupDuelsStore().off) {
        this.fail(localDuelFailure('disabled', t('cup.duel.failure.disabled')), generation)
        return
      }
      try {
        const invite = await api.cups.invite(code)
        if (generation !== this.generation) return
        if (!invite.me.joined || invite.me.left) {
          this.fail(localDuelFailure('unavailable', t('cup.duel.failure.not_participant')), generation)
          return
        }
        this.cupId = invite.id
      } catch (err) {
        this.fail(duelFailure(err), generation)
        return
      }
      if (duelId === null) {
        this.fail(localDuelFailure('not_found', t('cup.duel.failure.not_found')), generation)
        return
      }
      this.duelId = duelId
      await this.read(generation)
    },

    /** The screen has no duel to show: the state of the refusal (`cup_duel_disabled` switches the duels off for the session). */
    fail(failure: DuelFailure, generation: number): void {
      if (generation !== this.generation) return
      useCupDuelsStore().noteFailure(failure)
      this.duel = null
      this.failure = failure
      this.status = 'failed'
    },

    /** Reads the duel (the first time, and after a refused action that made what is shown out of date). */
    async read(generation: number = this.generation): Promise<void> {
      const cupId = this.cupId
      const duelId = this.duelId
      if (cupId === null || duelId === null) return
      if (useCupDuelsStore().off) {
        this.fail(localDuelFailure('disabled', t('cup.duel.failure.disabled')), generation)
        return
      }
      try {
        const duel = await api.cups.duels.show(cupId, duelId)
        if (generation !== this.generation) return
        this.duel = duel
        this.failure = null
        this.status = 'ready'
      } catch (err) {
        if (generation !== this.generation) return
        const failure = duelFailure(err)
        // A lost reply while the duel is on screen keeps it there (the poll tries again); without a duel it is a state with "Retry".
        if (this.duel !== null && isTransient(failure)) return
        this.fail(failure, generation)
      }
    },

    /**
     * One beat of the poll of an open duel: the state as the server has it now. A lost reply or a 429 throws, so that the beat slows down
     * (`usePolling`); the duel on screen stays. A refusal for good (404, 403, the duels off) is the state of the screen.
     */
    async poll(): Promise<void> {
      const cupId = this.cupId
      const duelId = this.duelId
      if (this.status !== 'ready' || cupId === null || duelId === null || this.acting !== null) return
      const generation = this.generation
      try {
        const duel = await api.cups.duels.show(cupId, duelId)
        if (generation !== this.generation || this.acting !== null) return
        this.duel = duel
      } catch (err) {
        if (generation !== this.generation) return
        const failure = duelFailure(err)
        if (isTransient(failure)) throw err
        this.fail(failure, generation)
      }
    },

    /**
     * "Accept" with the kind the screen shows (`expect_rated`). The answer carries `battle_id`: the screen goes to the match at once. The
     * kind changed meanwhile (409 `cup_duel_rating_changed {rated}`): the new kind is shown, the duel read again, and the learner accepts
     * again knowing it. The challenge closed meanwhile: read again, the screen shows how it ended.
     */
    async accept(): Promise<void> {
      const duel = this.duel
      if (duel === null || this.acting !== null || this.cupId === null) return
      await this.act('accept', () => api.cups.duels.accept(this.cupId!, duel.id, duelRated(duel)))
    },

    /** "Decline" (the one challenged). */
    async decline(): Promise<void> {
      const duel = this.duel
      if (duel === null || this.acting !== null || this.cupId === null) return
      await this.act('decline', () => api.cups.duels.decline(this.cupId!, duel.id))
    },

    /** "Cancel" (the challenger). */
    async cancel(): Promise<void> {
      const duel = this.duel
      if (duel === null || this.acting !== null || this.cupId === null) return
      await this.act('cancel', () => api.cups.duels.cancel(this.cupId!, duel.id))
    },

    async act(action: CupDuelAction, call: () => Promise<CupDuel>): Promise<void> {
      const generation = this.generation
      this.acting = action
      this.clearMessages()
      this.limitHit = null
      try {
        const duel = await call()
        if (generation !== this.generation) return
        this.duel = duel
        useCupBoardStore().invalidate()
      } catch (err) {
        if (generation !== this.generation) return
        await this.refuse(err, generation)
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },

    async refuse(err: unknown, generation: number): Promise<void> {
      const failure = duelFailure(err)
      if (failure.kind === 'disabled' || failure.kind === 'not_found' || failure.kind === 'unavailable' || failure.kind === 'off') {
        this.fail(failure, generation)
        return
      }
      if (failure.kind === 'limit' && failure.limitHit !== null) {
        this.limitHit = failure.limitHit
        useBillingStore().noteLimitHit(failure.limitHit)
        return
      }
      this.busyBattleId = failure.battleId
      if (failure.rated !== null) {
        // The screen shows the new kind at once, whether or not the read below comes back.
        if (this.duel !== null) this.duel = { ...this.duel, rated_offered: failure.rated }
        this.notice = failure.message
        await this.read(generation)
        return
      }
      this.actionError = failure.message
      if (failure.kind === 'refused') await this.read(generation)
    },

    dismissPaywall(): void {
      this.limitHit = null
    },
  },
})
