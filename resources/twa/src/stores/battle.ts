import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { newRequestId } from '@/composables/requestId'
import { useServerClock, type ServerClock } from '@/composables/useServerClock'
import { errorMessage } from '@/i18n/errors'
import type {
  Battle,
  BattleIncoming,
  BattleListResponse,
  BattlePoll,
  BattleRound,
  BattleRoundState,
  BattleStatus,
  PracticeAnswerPayload,
} from '@/api/types'

export type BattleListStatus = 'idle' | 'loading' | 'ready' | 'error'

/** The first reading of the battle that is on screen. */
export type BattleLoadStatus = 'idle' | 'loading' | 'ready' | 'error'

/** The action of the screen that is on its way: its button waits. */
export type BattleAction = 'accept' | 'decline' | 'cancel' | 'ready' | 'surrender' | 'rematch'

/** The answer of the round: nothing on its way / being sent (repeats included) / not delivered, waiting for "Retry". */
export type AnswerState = 'idle' | 'sending' | 'failed'

/** The answer that is on its way or was not delivered: a repeat takes the same `request_id`, so the server counts it once. */
export interface PendingAnswer {
  request_id: string
  round: number
  payload: PracticeAnswerPayload
}

/** The statuses in which the battle is alive: the poll runs only in them (docs/16 §11.4). */
const LIVE: readonly BattleStatus[] = ['invited', 'room', 'countdown', 'playing']

/** How many times a lost answer is sent again by itself before the screen asks the learner to retry. */
export const ANSWER_REPEATS = 3
/** The pause before each repeat, in milliseconds. */
const ANSWER_REPEAT_DELAYS_MS = [500, 1000, 2000]

/** How many times the result screen asks the server about a rematch (one reading per five seconds, two minutes). */
export const RESULT_CHECKS_MAX = 24

const NOT_READY = { me: false, opponent: false }
const NO_SCORE = { me: 0, opponent: 0 }

interface BattleState {
  /** `GET /practice/battles`: the unfinished battle, the invitations to me, the last results. */
  list: BattleListResponse | null
  listStatus: BattleListStatus
  /** Number of the newest list request: an older answer that comes late is dropped. */
  listSeq: number
  /** Invitations (ids) that are being accepted or declined from the list. */
  invitationBusy: number[]

  // The state of the battle screen (docs/16 §11.4).
  /** The battle that is open. */
  battleId: number | null
  /** The battle as `GET /battles/{id}` last told it: the rules, the people, the result. */
  battle: Battle | null
  /** The last poll (or the poll of the last full reading): the freshest ready flags, round, scores and presence. */
  poll: BattlePoll | null
  /** The round on screen. */
  round: BattleRound | null
  /** The status of the battle as the server last told it. */
  status: BattleStatus | 'idle'
  loadStatus: BattleLoadStatus
  /** Why the battle could not be read (localized). */
  errorMessage: string
  /** The refusal is final (not found, no access, closed): retrying changes nothing, the screen only leads away. */
  gone: boolean
  /** Counts the battles the store has been reset for: a reply that comes back for an earlier one is dropped. */
  generation: number
  /** The answer of the round that is on its way (`request_id`), kept for a repeat after a network error. */
  pendingAnswer: PendingAnswer | null
  answerState: AnswerState
  /** `server time − device time`, for the timer of a round. */
  serverOffsetMs: number
  /** The poll runs. */
  polling: boolean
  /** The screen wants the poll (it is open); `polling` is that while the battle is alive. */
  pollingWanted: boolean
  /** A poll is on its way: a second one (the wake-up at a deadline) does not start beside it. */
  ticking: boolean
  /** The poll failed (no network, 5xx, 429): "No connection, trying…". */
  offline: boolean
  /** Server time (epoch ms) at which the poll first showed the opponent gone; null while they are there. */
  absentSince: number | null
  /** The action that is on its way. */
  acting: BattleAction | null
  /** The refusal of an action that leaves the learner on the screen (localized): shown as a toast. */
  actionError: string
  /** The learner's other battle that blocked an accept or a rematch (409 `battle_busy`). */
  busyBattleId: number | null
  /** Number of the newest round request: an older round that comes late is dropped. */
  roundReq: number
  /** Grows with every new round element: the screen keys the mode component by it, so a new round has no pick left over. */
  elementSeq: number
  /** Elements whose clip the learner reported as broken (once each). */
  audioReported: string[]
  /** The rematch of the finished battle, read in full: it tells who offered it. */
  rematchBattle: Battle | null
  /** How many times the result screen has asked the server since the battle ended. */
  resultChecks: number
}

/** One clock of the server per store: the median of the latest `server_now` readings (docs/16 §11.5). */
const clocks = new WeakMap<object, ServerClock>()

function clockOf(store: object): ServerClock {
  let clock = clocks.get(store)
  if (!clock) {
    clock = useServerClock()
    clocks.set(store, clock)
  }
  return clock
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

/** A failure after which the server may or may not have counted the request: the same request is repeated as it was. */
function isUncertain(err: unknown): boolean {
  return err instanceof ApiClientError && (err.kind === 'network' || err.kind === 'server' || err.kind === 'rate_limited')
}

/** A refusal that no repeat can change: the battle is not there (or not the learner's), or not open to them. */
function isFinal(err: unknown): boolean {
  return err instanceof ApiClientError && (err.kind === 'not_found' || err.kind === 'forbidden' || err.kind === 'gone')
}

/** The dynamic part of a full reading, in the shape of a poll: the screen reads the freshest of the two. */
function pollOf(battle: Battle): BattlePoll {
  return {
    id: battle.id,
    status: battle.status,
    version: battle.version,
    server_now: battle.server_now,
    ready: battle.ready,
    opponent_present: battle.opponent_present,
    round: battle.round,
    scores: battle.scores,
    end_reason: battle.result?.end_reason ?? null,
  }
}

/** `version` only grows (docs/16 §2.7); at the same version the later `server_now` wins (presence does not move it). */
function isNewer(next: { version: number; server_now: string }, than: { version: number; server_now: string } | null): boolean {
  if (than === null || next.version !== than.version) return than === null || next.version > than.version
  return Date.parse(next.server_now) >= Date.parse(than.server_now)
}

/**
 * Battle 1 on 1 (docs/16 §11.4): the list that the hub and the Friends screen read, and the battle on screen — the
 * invitation, the room, the countdown, the rounds, the result.
 *
 * The server owns everything: the score, the time, the verdicts. This store keeps what the screen shows and defends it:
 * - `tick` is the poll (the heartbeat of the player): it reads `GET /battles/{id}` again when the `status` changed and
 *   `GET /round` when the round or its phase changed, and nothing else;
 * - `generation`: every reply is dropped if the learner has moved to another battle or left the screen meanwhile;
 * - one `request_id` per answer of a round, kept across a network error (`pendingAnswer`): the repeat is counted once;
 *   a repeat that arrives after the deadline (`round_closed`) shows no verdict, only the state the server has now;
 * - `serverOffsetMs`: the timer of a round counts to the server's deadline by the server's clock.
 */
export const useBattleStore = defineStore('battle', {
  state: (): BattleState => ({
    list: null,
    listStatus: 'idle',
    listSeq: 0,
    invitationBusy: [],
    battleId: null,
    battle: null,
    poll: null,
    round: null,
    status: 'idle',
    loadStatus: 'idle',
    errorMessage: '',
    gone: false,
    generation: 0,
    pendingAnswer: null,
    answerState: 'idle',
    serverOffsetMs: 0,
    polling: false,
    pollingWanted: false,
    ticking: false,
    offline: false,
    absentSince: null,
    acting: null,
    actionError: '',
    busyBattleId: null,
    roundReq: 0,
    elementSeq: 0,
    audioReported: [],
    rematchBattle: null,
    resultChecks: 0,
  }),

  getters: {
    /** Invitations to me that have not run out. */
    incoming: (s): BattleIncoming[] => s.list?.incoming ?? [],

    /** The battle I am in (a sent invitation, the room, the match). */
    active: (s): BattleListResponse['active'] => s.list?.active ?? null,

    /** The battle is not over: an invitation, the room, the countdown or the match. */
    isLive: (s): boolean => (LIVE as readonly string[]).includes(s.status),

    /** The battle is over: it has a result (`finished`) or ended without one (`closed`). */
    isOver: (s): boolean => s.status === 'finished' || s.status === 'closed',

    /** Who is ready (the room): the poll is fresher than the last full reading. */
    readiness: (s): Battle['ready'] => s.poll?.ready ?? s.battle?.ready ?? NOT_READY,

    opponentPresent: (s): boolean => s.poll?.opponent_present ?? s.battle?.opponent_present ?? true,

    /** The round the server is in (its number, phase, deadlines, who has answered). */
    roundState: (s): BattleRoundState | null => (s.poll !== null ? s.poll.round : (s.battle?.round ?? null)),

    scores: (s): Battle['scores'] => s.poll?.scores ?? s.battle?.scores ?? NO_SCORE,

    /** The round resource of the round the server is in; null while it is being read, or if the screen has an older one. */
    currentRound: (s): BattleRound | null => {
      const state = s.poll !== null ? s.poll.round : (s.battle?.round ?? null)
      return s.round !== null && state !== null && s.round.no === state.no ? s.round : null
    },

    /** The result screen still asks about a rematch (the other side may offer one a few seconds after the end). */
    watchingResult: (s): boolean => (s.status === 'finished' || s.status === 'closed') && s.resultChecks < RESULT_CHECKS_MAX,
  },

  actions: {
    // ----------------------------------------------------------- the list

    /**
     * Reads the list. Best effort: the screens that show it work without, so a failure keeps what there was and is not
     * reported. A late answer of an earlier request is dropped.
     */
    async loadList(): Promise<void> {
      const seq = ++this.listSeq
      this.listStatus = this.list === null ? 'loading' : 'ready'
      try {
        const list = await api.battles.list()
        if (seq !== this.listSeq) return
        this.list = list
        this.listStatus = 'ready'
      } catch {
        if (seq !== this.listSeq) return
        this.listStatus = this.list === null ? 'error' : 'ready'
      }
    },

    /**
     * Accepts an invitation of the list (the Friends screen). True when it was accepted: the battle is the learner's
     * now, and the screen leads into its room. A refusal is in `actionError`; the list is read again.
     */
    async acceptInvitation(id: number): Promise<boolean> {
      return this.answerInvitation(id, (battleId) => api.battles.accept(battleId))
    },

    async declineInvitation(id: number): Promise<boolean> {
      return this.answerInvitation(id, (battleId) => api.battles.decline(battleId))
    },

    async answerInvitation(id: number, call: (id: number) => Promise<Battle>): Promise<boolean> {
      if (this.invitationBusy.includes(id)) return false
      this.invitationBusy.push(id)
      this.actionError = ''
      try {
        await call(id)
        if (this.list !== null) this.list = { ...this.list, incoming: this.list.incoming.filter((item) => item.id !== id) }
        return true
      } catch (err) {
        this.actionError = errorMessage(err, 'battle')
        // Another side acted first (the invitation ran out, was withdrawn): show what is true now.
        void this.loadList()
        return false
      } finally {
        this.invitationBusy = this.invitationBusy.filter((busy) => busy !== id)
      }
    },

    clearActionError(): void {
      this.actionError = ''
    },

    clearBusyBattle(): void {
      this.busyBattleId = null
    },

    // -------------------------------------------------------- the screen

    /** Forgets the battle on screen. Whatever comes back for it later is dropped (`generation`). */
    resetFor(id: number | null): void {
      this.generation += 1
      this.battleId = id
      this.battle = null
      this.poll = null
      this.round = null
      this.status = 'idle'
      this.loadStatus = id === null ? 'idle' : 'loading'
      this.errorMessage = ''
      this.gone = false
      this.pendingAnswer = null
      this.answerState = 'idle'
      this.serverOffsetMs = 0
      clockOf(this).reset()
      this.polling = false
      this.pollingWanted = id !== null
      this.ticking = false
      this.offline = false
      this.absentSince = null
      this.acting = null
      this.actionError = ''
      this.busyBattleId = null
      this.roundReq += 1
      this.elementSeq = 0
      this.audioReported = []
      this.rematchBattle = null
      this.resultChecks = 0
    },

    /** Opens a battle: reads it and, while it is alive, keeps polling. */
    async open(id: number): Promise<void> {
      this.resetFor(id)
      const generation = this.generation
      try {
        const battle = await api.battles.get(id)
        if (generation !== this.generation) return
        this.applyBattle(battle)
        this.loadStatus = 'ready'
        // The round is not what the screen waits for; a failure here is repaired by the next poll.
        await this.syncRound().catch(() => undefined)
        await this.loadRematch()
      } catch (err) {
        if (generation !== this.generation) return
        this.failLoad(err)
      }
    },

    /** The screen is left: nothing that is still on its way may change the state, and the poll stops. */
    leave(): void {
      this.resetFor(null)
    },

    failLoad(err: unknown): void {
      this.loadStatus = 'error'
      this.errorMessage = errorMessage(err, 'battle')
      this.gone = isFinal(err)
      this.polling = false
    },

    startPolling(): void {
      this.pollingWanted = true
      this.updatePolling()
    },

    stopPolling(): void {
      this.pollingWanted = false
      this.polling = false
    },

    /** The poll runs only for a battle that is alive (docs/16 §11.4): it stops at `finished` and `closed`. */
    updatePolling(): void {
      this.polling = this.pollingWanted && (LIVE as readonly string[]).includes(this.status)
    },

    syncClock(serverNow: string): void {
      const clock = clockOf(this)
      clock.sync(serverNow)
      this.serverOffsetMs = clock.offsetMs.value
    },

    /** Takes a full reading of the battle: the answer of `GET /battles/{id}` and of every action. */
    applyBattle(battle: Battle): void {
      if (this.battle !== null && battle.version < this.battle.version) return
      this.battle = battle
      this.status = battle.status
      this.syncClock(battle.server_now)
      const poll = pollOf(battle)
      if (isNewer(poll, this.poll)) {
        this.poll = poll
        this.trackPresence(poll)
      }
      this.updatePolling()
    },

    trackPresence(poll: BattlePoll): void {
      if (poll.opponent_present) this.absentSince = null
      else if (this.absentSince === null) this.absentSince = Date.parse(poll.server_now) || Date.now()
    },

    async reloadBattle(): Promise<void> {
      const id = this.battleId
      if (id === null) return
      const generation = this.generation
      const battle = await api.battles.get(id)
      if (generation !== this.generation) return
      this.applyBattle(battle)
    },

    // --------------------------------------------------------- the poll

    /**
     * One beat of the poll, which is also the player's heartbeat (docs/16 §8.4). It throws when the beat did not come
     * (no network, 5xx, 429) so that `usePolling` slows down; a refusal that no repeat cures ends the polling.
     */
    async tick(): Promise<void> {
      const id = this.battleId
      if (id === null || !this.polling || this.ticking) return

      const generation = this.generation
      this.ticking = true
      try {
        const poll = await api.battles.poll(id)
        if (generation !== this.generation) return
        this.offline = false
        await this.applyPoll(poll)
      } catch (err) {
        if (generation !== this.generation) return
        if (isFinal(err)) {
          this.failLoad(err)
          return
        }
        this.offline = true
        throw err
      } finally {
        if (generation === this.generation) this.ticking = false
      }
    },

    /**
     * Takes a poll: a new `status` is read in full (`GET /battles/{id}`), a new round or phase is read as a round
     * (`GET /round`); a poll that only moves the scores, the flags or the presence needs no other request.
     */
    async applyPoll(poll: BattlePoll): Promise<void> {
      if (this.poll !== null && !isNewer(poll, this.poll)) return
      const generation = this.generation
      this.syncClock(poll.server_now)
      const changed = poll.status !== this.status
      this.poll = poll
      this.trackPresence(poll)

      if (changed) {
        await this.reloadBattle()
        if (generation !== this.generation) return
        if (this.isOver) await this.loadRematch()
      }
      await this.syncRound()
      this.updatePolling()
    },

    /** Does the round on screen differ from the round the server is in: another number, another phase, or no reveal yet. */
    roundIsStale(): boolean {
      const state = this.roundState
      if (this.status !== 'playing' || state === null || state.phase === null) return false
      // Having answered, the learner sees "waiting", not the task: there is nothing to read until the reveal.
      if (state.phase === 'answering' && state.my_answered) return false
      const round = this.round
      if (round === null || round.no !== state.no || round.phase !== state.phase) return true
      return state.phase === 'reveal' && round.reveal === null
    },

    /** Reads the round the server is in, when the one on screen is not it. */
    async syncRound(): Promise<void> {
      const id = this.battleId
      const state = this.roundState
      if (id === null || state === null || !this.roundIsStale()) return

      const generation = this.generation
      const req = ++this.roundReq
      try {
        const round = await api.battles.round(id, state.no)
        if (generation !== this.generation || req !== this.roundReq) return
        this.syncClock(round.server_now)
        if (this.round?.element.id !== round.element.id) this.elementSeq += 1
        this.round = round
      } catch (err) {
        // The round is not open yet (or no more): the next poll shows where the server is.
        if (err instanceof ApiClientError && err.kind === 'conflict') return
        throw err
      }
    },

    // ------------------------------------------------------- the actions

    async accept(): Promise<boolean> {
      return this.act('accept', (id) => api.battles.accept(id))
    },

    async decline(): Promise<boolean> {
      return this.act('decline', (id) => api.battles.decline(id))
    },

    /** The challenger withdraws the invitation, or either player leaves the room or the countdown. */
    async cancel(): Promise<boolean> {
      return this.act('cancel', (id) => api.battles.cancel(id))
    },

    async ready(): Promise<boolean> {
      return this.act('ready', (id) => api.battles.ready(id))
    },

    /** "Surrender and leave": the opponent wins, the screen shows the result. */
    async surrender(): Promise<boolean> {
      return this.act('surrender', (id) => api.battles.surrender(id))
    },

    /** Runs one action of the battle on screen: its button waits, the answer is the battle as it is now. */
    async act(action: BattleAction, call: (id: number) => Promise<Battle>): Promise<boolean> {
      const id = this.battleId
      if (id === null || this.acting !== null) return false

      const generation = this.generation
      this.acting = action
      this.actionError = ''
      try {
        const battle = await call(id)
        if (generation !== this.generation) return false
        this.applyBattle(battle)
        await this.syncRound().catch(() => undefined)
        return true
      } catch (err) {
        if (generation !== this.generation) return false
        await this.refuse(err)
        return false
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },

    /** The action was not done. A conflict means the state has moved: it is read again, and the screen follows it. */
    async refuse(err: unknown): Promise<void> {
      this.actionError = errorMessage(err, 'battle')
      if (!(err instanceof ApiClientError)) return
      if (err.code === 'battle_busy') this.busyBattleId = typeof err.details?.battle_id === 'number' ? err.details.battle_id : null
      if (err.code === 'battle_closed' || err.code === 'battle_wrong_state') {
        try {
          await this.reloadBattle()
        } catch {
          // the poll shows it
        }
      }
    },

    /**
     * The answer of the round (docs/16 §8.5): one final answer, sent with one `request_id`. A lost reply is repeated with
     * the same id up to `ANSWER_REPEATS` times; then the screen offers "Retry" (`retryAnswer`).
     */
    async answer(payload: PracticeAnswerPayload): Promise<void> {
      const state = this.roundState
      const round = this.currentRound
      if (this.battleId === null || this.status !== 'playing' || state === null || state.phase !== 'answering' || state.my_answered) return
      if (round === null || this.answerState !== 'idle' || this.pendingAnswer !== null) return

      this.pendingAnswer = { request_id: newRequestId(), round: round.no, payload }
      await this.send()
    },

    /** "Retry": the answer that was not delivered goes again, with its own `request_id`. */
    async retryAnswer(): Promise<void> {
      if (this.pendingAnswer === null || this.answerState === 'sending') return
      await this.send()
    },

    async send(): Promise<void> {
      const pending = this.pendingAnswer
      const id = this.battleId
      if (pending === null || id === null) return

      const generation = this.generation
      this.answerState = 'sending'
      this.actionError = ''
      for (let attempt = 0; ; attempt++) {
        try {
          const res = await api.battles.answer(id, { request_id: pending.request_id, round: pending.round, answer: pending.payload })
          if (generation !== this.generation) return
          this.pendingAnswer = null
          this.answerState = 'idle'
          // The verdict is not shown: the right answer and the other's come together with the reveal of the round.
          try {
            await this.applyPoll(res.poll)
          } catch {
            // the answer is counted; the next poll brings the rest
          }
          return
        } catch (err) {
          if (generation !== this.generation) return
          if (isUncertain(err)) {
            if (attempt < ANSWER_REPEATS) {
              await sleep(ANSWER_REPEAT_DELAYS_MS[attempt] ?? 2000)
              if (generation !== this.generation || this.pendingAnswer?.request_id !== pending.request_id) return
              continue
            }
            this.answerState = 'failed'
            return
          }
          await this.refuseAnswer(err)
          return
        }
      }
    },

    /** The server did not take the answer. Nothing of it is shown as a verdict: the screen goes to where the server is. */
    async refuseAnswer(err: unknown): Promise<void> {
      const generation = this.generation
      this.pendingAnswer = null
      this.answerState = 'idle'
      const code = err instanceof ApiClientError ? err.code : ''
      // The answer of this round was counted already (a repeat whose reply was lost): nothing to say, only to follow.
      if (code !== 'answer_already_accepted') this.actionError = errorMessage(err, 'battle')

      if (err instanceof ApiClientError && (code === 'invalid_answer_shape' || code === 'unknown_element_id')) this.round = null
      try {
        const id = this.battleId
        if (id === null) return
        const poll = await api.battles.poll(id)
        if (generation !== this.generation) return
        await this.applyPoll(poll)
      } catch {
        // the poll shows it
      }
    },

    /** The clip of the round does not play: tell the server once per element; it checks the file (docs/16 §10.3). */
    async audioFailed(elementId: string): Promise<void> {
      const id = this.battleId
      if (id === null || this.audioReported.includes(elementId)) return

      this.audioReported.push(elementId)
      const generation = this.generation
      try {
        const res = await api.battles.audioFailed(id, { request_id: newRequestId(), element_id: elementId })
        if (generation !== this.generation) return
        // `verified`: the server found the file missing and voided the match; otherwise it goes on and this is a local fault.
        this.applyBattle(res.battle)
      } catch {
        // best effort: the poll tells if the match was voided
      }
    },

    // ----------------------------------------------------------- rematch

    /** "Rematch": a new invitation to the same friend, in the same format and place. Returns its id, or null (`actionError`). */
    async rematch(): Promise<number | null> {
      const battle = this.battle
      if (battle === null || !this.isOver || this.acting !== null) return null

      const generation = this.generation
      this.acting = 'rematch'
      this.actionError = ''
      try {
        const created = await api.battles.invite({
          rematch_of: battle.id,
          mode: battle.mode,
          course: battle.course.slug,
          lesson_id: battle.lesson?.id ?? null,
          ...(typeof battle.friendship_id === 'number' ? { friendship_id: battle.friendship_id } : {}),
        })
        return created.id
      } catch (err) {
        if (generation === this.generation) await this.refuse(err)
        return null
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },

    /**
     * Reads the rematch that follows this battle, to know who offered it (`rematch` of the battle has only its id and
     * status): an offer to me is answered with "Accept" or "Decline", my own is only a way back into it.
     */
    async loadRematch(): Promise<void> {
      const offer = this.battle?.rematch ?? null
      if (offer === null) {
        this.rematchBattle = null
        return
      }
      if (this.rematchBattle?.id === offer.battle_id && this.rematchBattle.status === offer.status) return

      const generation = this.generation
      try {
        const rematch = await api.battles.get(offer.battle_id)
        if (generation === this.generation) this.rematchBattle = rematch
      } catch {
        // the offer is just not shown
      }
    },

    /** One reading of the result screen: the offer of a rematch may have come, or been taken back. Best effort. */
    async refreshResult(): Promise<void> {
      if (!this.watchingResult) return
      const generation = this.generation
      this.resultChecks += 1
      try {
        await this.reloadBattle()
      } catch {
        return
      }
      if (generation === this.generation) await this.loadRematch()
    },

    /** Accepts the rematch offered to me; returns its id for the route, or null (`actionError`). */
    async acceptRematch(): Promise<number | null> {
      const offer = this.rematchBattle
      if (offer === null || this.acting !== null) return null

      const generation = this.generation
      this.acting = 'accept'
      this.actionError = ''
      try {
        const accepted = await api.battles.accept(offer.id)
        return accepted.id
      } catch (err) {
        if (generation === this.generation) {
          await this.refuse(err)
          await this.loadRematch().catch(() => undefined)
        }
        return null
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },

    async declineRematch(): Promise<boolean> {
      const offer = this.rematchBattle
      if (offer === null || this.acting !== null) return false

      const generation = this.generation
      this.acting = 'decline'
      this.actionError = ''
      try {
        const declined = await api.battles.decline(offer.id)
        if (generation === this.generation) this.rematchBattle = declined
        return true
      } catch (err) {
        if (generation === this.generation) await this.refuse(err)
        return false
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },
  },
})
