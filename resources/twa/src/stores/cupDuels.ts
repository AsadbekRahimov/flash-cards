import { defineStore } from 'pinia'
import { api } from '@/api'
import { duelFailure, duelRated, type DuelFailure } from '@/composables/cupDuelInfo'
import { newRequestId } from '@/composables/requestId'
import { useBillingStore } from '@/stores/billing'
import { useCupBoardStore } from '@/stores/cupBoard'
import type { CupDuel, CupDuelChallengeRequest, CupDuelListResponse, PracticeLimitHit } from '@/api/types'

export type CupDuelsStatus = 'idle' | 'loading' | 'ready' | 'failed'

interface CupDuelsState {
  /**
   * The duels are off (409 `cup_duel_disabled`, docs/04 A.23d): remembered for the session - no screen shows the section and no route of
   * the duels is asked again until the Mini App is opened anew.
   */
  off: boolean
  cupId: number | null
  list: CupDuelListResponse | null
  status: CupDuelsStatus
  failure: DuelFailure | null
  /** Counts the reads: a reply for an earlier one is dropped. */
  seq: number
  /** The duel of the list an action is on its way for: its buttons wait, a second tap does nothing. */
  acting: number | null
  /** A refused action of the list (localized). */
  actionError: string
  /** `cup_duel_rating_changed`: the conditions of a challenge changed, the list was read again (localized). */
  notice: string
  /** My match in the way of an acceptance (409 `battle_busy`): a link leads to it. */
  busyBattleId: number | null
  /** The paywall of the free battles of the day (403 `practice_limit_reached`). */
  limitHit: PracticeLimitHit | null
  /**
   * The `request_id` of the challenge being made: one per attempt. A repeat after a lost reply (network, 5xx, 429) carries the same one, so
   * the server answers it with the duel it made (200), never a second duel; a new choice of the opponent is a new attempt.
   */
  requestId: string | null
  /** The choice the `request_id` belongs to: the participant id, or `public`. */
  requestKey: string | null
  creating: boolean
  createFailure: DuelFailure | null
}

/**
 * The duels of a cup (docs/33 §5.7, docs/04 A.23d): the session switch, the list (`GET /cups/{id}/duels` - challenges to me, open ones
 * to anybody, mine, my duels of today, and whether I may challenge), its actions, and the challenge. The server decides everything: the
 * kind (rated or friendly), the limits, who may accept; the store keeps what the screens need and defends the challenge against a double
 * tap and a lost reply.
 */
export const useCupDuelsStore = defineStore('cupDuels', {
  state: (): CupDuelsState => ({
    off: false,
    cupId: null,
    list: null,
    status: 'idle',
    failure: null,
    seq: 0,
    acting: null,
    actionError: '',
    notice: '',
    busyBattleId: null,
    limitHit: null,
    requestId: null,
    requestKey: null,
    creating: false,
    createFailure: null,
  }),

  getters: {
    /** The challenges addressed to me: the counter of the cup screen. */
    incomingCount: (s): number => s.list?.incoming.length ?? 0,
  },

  actions: {
    /** The duels are off: the section goes for the rest of the session. */
    markOff(): void {
      this.off = true
      this.list = null
      this.status = 'idle'
    },

    /** Notes a refusal of any duel route: `cup_duel_disabled` switches the section off. */
    noteFailure(failure: DuelFailure): void {
      if (failure.kind === 'disabled') this.markOff()
    },

    /** Reads the list of `cupId` (one request). Nothing is asked while the duels are off. */
    async load(cupId: number): Promise<CupDuelListResponse | null> {
      if (this.off) return null
      const seq = ++this.seq
      if (this.cupId !== cupId) this.list = null
      this.cupId = cupId
      this.status = this.list === null ? 'loading' : this.status
      this.failure = null
      try {
        const list = await api.cups.duels.list(cupId)
        if (seq !== this.seq) return null
        this.list = list
        this.status = 'ready'
        return list
      } catch (err) {
        if (seq !== this.seq) return null
        const failure = duelFailure(err)
        this.noteFailure(failure)
        if (this.off) return null
        this.list = null
        this.failure = failure
        this.status = 'failed'
        return null
      }
    },

    clearMessages(): void {
      this.actionError = ''
      this.notice = ''
      this.busyBattleId = null
    },

    dismissPaywall(): void {
      this.limitHit = null
    },

    /**
     * "Accept" of a row: with the kind the row shows (`expect_rated`). Returns the id of the match to go to at once (the room waits only a
     * short while), or null with the reason: the conditions changed (`notice`, the list read again - the learner looks and accepts again),
     * the challenge closed meanwhile (the list read again), my own match in the way, the paywall.
     */
    async accept(duel: CupDuel): Promise<number | null> {
      const cupId = this.cupId
      if (this.acting !== null || cupId === null) return null
      this.acting = duel.id
      this.clearMessages()
      try {
        const accepted = await api.cups.duels.accept(cupId, duel.id, duelRated(duel))
        useCupBoardStore().invalidate()
        return accepted.battle_id
      } catch (err) {
        await this.refuseAction(err, cupId)
        return null
      } finally {
        this.acting = null
      }
    },

    /** "Decline" of a challenge to me; the list is read again. */
    async decline(duel: CupDuel): Promise<void> {
      await this.act(duel, (cupId) => api.cups.duels.decline(cupId, duel.id))
    },

    /** "Cancel" of my open challenge; the list is read again (and "Challenge" comes back). */
    async cancel(duel: CupDuel): Promise<void> {
      await this.act(duel, (cupId) => api.cups.duels.cancel(cupId, duel.id))
    },

    async act(duel: CupDuel, call: (cupId: number) => Promise<CupDuel>): Promise<void> {
      const cupId = this.cupId
      if (this.acting !== null || cupId === null) return
      this.acting = duel.id
      this.clearMessages()
      try {
        await call(cupId)
        useCupBoardStore().invalidate()
        await this.load(cupId)
      } catch (err) {
        await this.refuseAction(err, cupId)
      } finally {
        this.acting = null
      }
    },

    async refuseAction(err: unknown, cupId: number): Promise<void> {
      const failure = duelFailure(err)
      this.noteFailure(failure)
      if (this.off) return
      if (failure.kind === 'limit') {
        this.limitHit = failure.limitHit
        if (failure.limitHit !== null) useBillingStore().noteLimitHit(failure.limitHit)
        return
      }
      if (failure.rated !== null) this.notice = failure.message
      else this.actionError = failure.message
      this.busyBattleId = failure.battleId
      // What the row showed is out of date: the conditions, the state of the challenge, the challenge itself.
      if (failure.rated !== null || failure.kind === 'not_found' || failure.kind === 'refused') await this.load(cupId)
    },

    /** A new attempt of a challenge: the screen was opened again, or the learner changed his mind. */
    resetCreate(): void {
      this.requestId = null
      this.requestKey = null
      this.createFailure = null
      this.limitHit = null
    },

    /**
     * Challenges `opponentParticipantId`, or anybody in the group (null: an open challenge, published in the chat). Returns the duel made
     * (201) or the one made by the same `request_id` before (200); null with the reason in `createFailure` / `limitHit`. A second tap
     * while the request is on its way does nothing.
     */
    async challenge(cupId: number, opponentParticipantId: number | null): Promise<CupDuel | null> {
      if (this.creating || this.off) return null
      const key = opponentParticipantId === null ? 'public' : String(opponentParticipantId)
      if (this.requestId === null || this.requestKey !== key) {
        this.requestId = newRequestId()
        this.requestKey = key
      }
      const body: CupDuelChallengeRequest =
        opponentParticipantId === null
          ? { public: true, request_id: this.requestId }
          : { opponent_participant_id: opponentParticipantId, public: false, request_id: this.requestId }
      this.creating = true
      this.createFailure = null
      this.limitHit = null
      try {
        const duel = await api.cups.duels.challenge(cupId, body)
        this.requestId = null
        this.requestKey = null
        useCupBoardStore().invalidate()
        return duel
      } catch (err) {
        const failure = duelFailure(err)
        // Refused for sure: nothing was made, the next tap is a new attempt. A lost reply keeps the id for the repeat.
        if (!failure.uncertain) {
          this.requestId = null
          this.requestKey = null
        }
        this.noteFailure(failure)
        if (failure.kind === 'limit' && failure.limitHit !== null) {
          this.limitHit = failure.limitHit
          useBillingStore().noteLimitHit(failure.limitHit)
        }
        this.createFailure = failure
        return null
      } finally {
        this.creating = false
      }
    },
  },
})
