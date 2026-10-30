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
  BattleRematch,
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

/** Why a request got no reply: the connection (or a request that hung up to its limit) or the server itself (5xx, 429). The screen says which. */
export type TroubleCause = 'network' | 'server'

/** The answer that is on its way or was not delivered: a repeat takes the same `request_id`, so the server counts it once. */
export interface PendingAnswer {
  request_id: string
  round: number
  payload: PracticeAnswerPayload
  /** A try of it ended without a reply (no network, 5xx, 429, a hung request): the server may have counted it. */
  uncertain: boolean
}

/** The statuses in which the battle is alive: the poll runs only in them (docs/16 §11.4). */
const LIVE: readonly BattleStatus[] = ['invited', 'room', 'countdown', 'playing']

/** The order in which a battle goes through its statuses (the two end ones are equal): an older reading does not take a newer one back. */
const STATUS_RANK: Record<BattleStatus, number> = { invited: 0, room: 1, countdown: 2, playing: 3, finished: 4, closed: 4 }

/** How many times a lost answer is sent again by itself before the screen asks the learner to retry. */
export const ANSWER_REPEATS = 3
/** The pause before each repeat, in milliseconds. */
const ANSWER_REPEAT_DELAYS_MS = [500, 1000, 2000]

/** The pause before the report of a broken clip, if it did not reach the server, is sent once more. */
const AUDIO_REPORT_REPEAT_MS = 1500

/** How many times the result screen asks the server about a rematch (one reading per five seconds, two minutes). */
export const RESULT_CHECKS_MAX = 24

/**
 * What the server says to a repeat of an answer that comes too late. After a try that got no reply the first one may have been
 * counted before it was late (the server answers a repeat with the first result, but an older server did not), so these are not
 * "your answer was not taken": the review of the round tells what became of it.
 */
const LATE_CODES = ['round_closed', 'round_not_open', 'battle_closed']

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
  /** The answer of the round that is on its way (`request_id`), kept for a repeat after a lost reply. */
  pendingAnswer: PendingAnswer | null
  answerState: AnswerState
  /** Why the last try of the answer got no reply (for the words under "Retry"). */
  answerCause: TroubleCause
  /** `server time − device time`, for the timer of a round. */
  serverOffsetMs: number
  /** The poll runs. */
  polling: boolean
  /** The screen wants the poll (it is open); `polling` is that while the battle is alive. */
  pollingWanted: boolean
  /** A poll is on its way: a second one (the wake-up at a deadline) does not start beside it. */
  ticking: boolean
  /** A wake-up came while a poll was on its way: another follows it as soon as it is done. */
  wakeQueued: boolean
  /** The poll failed (no network, 5xx, 429): "No connection, trying…". */
  offline: boolean
  /** What the failed poll was: the connection or the server (the strip says it in other words). */
  offlineCause: TroubleCause
  /** Server time (epoch ms) since which the opponent is gone (their last presence); null while they are there. */
  absentSince: number | null
  /** The action that is on its way. */
  acting: BattleAction | null
  /** The refusal of an action that leaves the learner on the screen (localized): shown as a toast. */
  actionError: string
  /** The learner's other battle that blocked an accept or a rematch (409 `battle_busy`). */
  busyBattleId: number | null
  /** The action was refused because practice is behind Pro (403 `practice_pro_required`): the paywall opens instead of a message. */
  paywall: boolean
  /** Number of the newest round request: an older round that comes late is dropped. */
  roundReq: number
  /** Grows with every new round element: the screen keys the mode component by it, so a new round has no pick left over. */
  elementSeq: number
  /** Elements whose clip the learner reported as broken (once each). */
  audioReported: string[]
  /** The rematch offered after the battle that is over, and who offered it (`by_me`); null if none. (`rematch()` is the action.) */
  rematchOffer: BattleRematch | null
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

/** Why a request got no reply: the connection (a request that hung up to its limit is that too) or the server (5xx, 429). */
function causeOf(err: unknown): TroubleCause {
  return err instanceof ApiClientError && (err.kind === 'server' || err.kind === 'rate_limited') ? 'server' : 'network'
}

/** A refusal that no repeat can change: the battle is not there (or not the learner's), or not open to them. */
function isFinal(err: unknown): boolean {
  return err instanceof ApiClientError && (err.kind === 'not_found' || err.kind === 'forbidden' || err.kind === 'gone')
}

/** Practice (a battle with it) is behind Pro for this learner (docs/16 §3.2): the answer is the paywall, not a message. */
export function isProRequired(err: unknown): boolean {
  return err instanceof ApiClientError && err.code === 'practice_pro_required'
}

/**
 * 409 `battle_busy` names the battle that is in the way (`details.battle_id`). When it is an invitation from the friend TO ME - the
 * two of us offered a battle (or a rematch) at the same moment, and theirs was first - it is the one to accept, and this gives its id
 * so that the screen leads into it. Null: the battle in the way is the learner's own (or it cannot be read), the message about it stands.
 */
export async function incomingInvitation(err: unknown): Promise<number | null> {
  if (!(err instanceof ApiClientError) || err.code !== 'battle_busy') return null
  const id = err.details?.battle_id
  if (typeof id !== 'number') return null
  try {
    const battle = await api.battles.get(id)
    return battle.status === 'invited' && battle.role === 'opponent' ? battle.id : null
  } catch {
    return null
  }
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
    opponent_absent_since: battle.opponent_absent_since,
    round: battle.round,
    scores: battle.scores,
    end_reason: battle.result?.end_reason ?? battle.end_reason,
    rematch: battle.rematch,
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
 * - one `request_id` per answer of a round, kept across a lost reply (`pendingAnswer`): the repeat is counted once. The answer
 *   belongs to ONE round: as soon as the server is somewhere else (`dropStaleAnswer`) it is let go without a word, and what is
 *   still in flight for it ends quietly; a repeat that arrives late after a try with no reply shows no refusal either, only the
 *   state the server has now;
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
    answerCause: 'network',
    serverOffsetMs: 0,
    polling: false,
    pollingWanted: false,
    ticking: false,
    wakeQueued: false,
    offline: false,
    offlineCause: 'network',
    absentSince: null,
    acting: null,
    actionError: '',
    busyBattleId: null,
    paywall: false,
    roundReq: 0,
    elementSeq: 0,
    audioReported: [],
    rematchOffer: null,
    resultChecks: 0,
  }),

  getters: {
    /** Invitations to me that have not run out. */
    incoming: (s): BattleIncoming[] => s.list?.incoming ?? [],

    /** The battle I am in (a sent invitation, the room, the match). */
    active: (s): BattleListResponse['active'] => s.list?.active ?? null,

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
     * now, and the screen leads into its room. A refusal is in `actionError` (or, behind Pro, in `paywall`); the list is read again.
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
        if (isProRequired(err)) this.paywall = true
        else this.actionError = errorMessage(err, 'battle')
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

    dismissPaywall(): void {
      this.paywall = false
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
      this.answerCause = 'network'
      this.serverOffsetMs = 0
      clockOf(this).reset()
      this.polling = false
      this.pollingWanted = id !== null
      this.ticking = false
      this.wakeQueued = false
      this.offline = false
      this.offlineCause = 'network'
      this.absentSince = null
      this.acting = null
      this.actionError = ''
      this.busyBattleId = null
      this.paywall = false
      this.roundReq += 1
      this.elementSeq = 0
      this.audioReported = []
      this.rematchOffer = null
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
      if (isProRequired(err)) this.paywall = true
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

    /**
     * Takes a full reading of the battle: the answer of `GET /battles/{id}` and of every action. `version` only grows, and the poll
     * is read more often than the battle: a reading older than what the screen has - the last full one OR the last poll - is a reply
     * that came late, and it takes nothing back (no status that flickers back to the one before).
     */
    applyBattle(battle: Battle): void {
      if (battle.version < Math.max(this.battle?.version ?? 0, this.poll?.version ?? 0)) return
      this.battle = battle
      this.status = battle.status
      this.syncClock(battle.server_now)
      const poll = pollOf(battle)
      if (isNewer(poll, this.poll)) {
        this.poll = poll
        this.trackPresence(poll)
      }
      this.applyRematch(battle.rematch)
      this.dropStaleAnswer()
      this.updatePolling()
    },

    /**
     * Since when the opponent is gone: the server names the moment (`opponent_absent_since`, their last presence) and the strip counts
     * `presence_grace_seconds` from it - the same number after a reload. A server that does not name it is taken at its word from the
     * first poll that saw the opponent gone.
     */
    trackPresence(poll: BattlePoll): void {
      if (poll.opponent_present) {
        this.absentSince = null
        return
      }
      const since = Date.parse(poll.opponent_absent_since ?? '')
      if (Number.isFinite(since)) this.absentSince = since
      else if (this.absentSince === null) this.absentSince = Date.parse(poll.server_now) || Date.now()
    },

    /**
     * Takes the rematch the server reports. The statuses of a battle only go forward, so a reading that was on its way while the
     * offer was answered (declined, accepted) cannot bring the old status back.
     */
    applyRematch(next: BattleRematch | null): void {
      const known = this.rematchOffer
      if (next !== null && known !== null && next.battle_id === known.battle_id && STATUS_RANK[next.status] < STATUS_RANK[known.status]) return
      this.rematchOffer = next
    },

    /**
     * The answer belongs to ONE round. When the server is somewhere else - the battle is not `playing`, the round is another one, its
     * phase is not `answering` any more, or this player's answer is counted already (it was the reply that got lost) - the answer that
     * was on its way or "not delivered" is let go without a word: it must not stand in the way of the next round, and the request that
     * is still in flight finds its `request_id` gone and ends quietly (`send`).
     */
    dropStaleAnswer(): void {
      const pending = this.pendingAnswer
      const poll = this.poll
      if (pending === null || poll === null) return
      const round = poll.round
      const stale = poll.status !== 'playing' || round === null || round.no !== pending.round || round.phase !== 'answering' || round.my_answered
      if (!stale) return
      this.pendingAnswer = null
      this.answerState = 'idle'
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
     * (no network, 5xx, 429, a request that hung up to its limit) so that `usePolling` slows down; a refusal that no repeat cures
     * ends the polling.
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
        this.offlineCause = causeOf(err)
        throw err
      } finally {
        if (generation === this.generation) {
          this.ticking = false
          // A wake-up came while this poll was on its way: it was asked before the moment, so another follows at once.
          if (this.wakeQueued) {
            this.wakeQueued = false
            void this.tick().catch(() => undefined)
          }
        }
      }
    },

    /**
     * The screen asks for a poll NOW: a moment at which the server moves the battle on has come (the deadline of a round, the end of
     * the pause). If a poll is on its way it may have been asked before the moment, so it is not enough to drop the call: another poll
     * follows it as soon as it is done, instead of the next beat, two seconds later.
     */
    async wake(): Promise<void> {
      if (this.ticking) {
        this.wakeQueued = true
        return
      }
      await this.tick()
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
      this.applyRematch(poll.rematch)
      this.dropStaleAnswer()

      if (changed) {
        await this.reloadBattle()
        if (generation !== this.generation) return
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

    /**
     * The action was not done. Behind Pro the answer is the paywall, not a message. A conflict means the state has moved: it is read
     * again, and the screen follows it.
     */
    async refuse(err: unknown): Promise<void> {
      if (isProRequired(err)) {
        this.paywall = true
        return
      }
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

      this.pendingAnswer = { request_id: newRequestId(), round: round.no, payload, uncertain: false }
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
      // Is this answer still the one the screen waits for? A poll may have shown that the round moved on (`dropStaleAnswer`), and the
      // answer of the next round may be on its way by now: whatever this request brings back is then nobody's, and must not touch it.
      const current = (): boolean => generation === this.generation && this.pendingAnswer?.request_id === pending.request_id

      this.answerState = 'sending'
      this.actionError = ''
      for (let attempt = 0; ; attempt++) {
        try {
          const res = await api.battles.answer(id, { request_id: pending.request_id, round: pending.round, answer: pending.payload })
          if (!current()) return
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
          if (!current()) return
          if (isUncertain(err)) {
            // The server may have counted this try: from now on "too late" is not "not taken" (see `LATE_CODES`).
            pending.uncertain = true
            this.answerCause = causeOf(err)
            if (attempt < ANSWER_REPEATS) {
              await sleep(ANSWER_REPEAT_DELAYS_MS[attempt] ?? 2000)
              if (!current()) return
              continue
            }
            this.answerState = 'failed'
            return
          }
          await this.refuseAnswer(err, pending.uncertain)
          return
        }
      }
    },

    /**
     * The server did not take the answer. Nothing of it is shown as a verdict: the screen goes to where the server is.
     * `uncertain`: an earlier try of this very answer got no reply, so it may have been counted.
     */
    async refuseAnswer(err: unknown, uncertain = false): Promise<void> {
      const generation = this.generation
      this.pendingAnswer = null
      this.answerState = 'idle'
      const code = err instanceof ApiClientError ? err.code : ''
      // Nothing to say when the answer of this round was counted already (a repeat whose reply was lost), nor when a try that got
      // no reply meets "late": the review of the round tells what became of the answer. Only a first try that is late is a "no".
      const silent = code === 'answer_already_accepted' || (uncertain && LATE_CODES.includes(code))
      if (!silent) this.actionError = errorMessage(err, 'battle')

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

    /**
     * The clip does not come: tell the server once per element; it checks the file (docs/16 §10.3). A report that got no reply is sent
     * once more (with the same `request_id`: the server counts it once). Returns what the server found - `true`: the file is
     * missing and the match is voided (the battle it answers with is taken at once), `false`: the file is there, the fault is local -
     * or `null`: nothing was found out (a second report of an element, no reply, a refusal).
     */
    async audioFailed(elementId: string): Promise<boolean | null> {
      const id = this.battleId
      if (id === null || this.audioReported.includes(elementId)) return null

      this.audioReported.push(elementId)
      const generation = this.generation
      const requestId = newRequestId()
      for (let attempt = 0; attempt < 2; attempt++) {
        try {
          const res = await api.battles.audioFailed(id, { request_id: requestId, element_id: elementId })
          if (generation !== this.generation) return null
          this.applyBattle(res.battle)
          return res.verified
        } catch (err) {
          if (generation !== this.generation) return null
          // best effort: the poll tells if the match was voided
          if (attempt === 0 && isUncertain(err)) {
            await sleep(AUDIO_REPORT_REPEAT_MS)
            if (generation !== this.generation) return null
            continue
          }
          return null
        }
      }
      return null
    },

    // ----------------------------------------------------------- rematch

    /**
     * "Rematch": a new invitation to the same friend, in the same format and place. Returns the id of the battle to go to, or null
     * (`actionError`). If the friend offered a rematch at the same moment, the invitation to go to is THEIRS - the learner accepts it.
     */
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
        if (generation !== this.generation) return null
        const theirs = await incomingInvitation(err)
        if (generation !== this.generation) return null
        if (theirs !== null) return theirs
        await this.refuse(err)
        return null
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },

    /**
     * Asks whether a rematch has been offered after this battle, and by whom - with the poll: it carries `rematch` (`by_me`) for a
     * battle that is over, costs the server a fraction of `GET /battles/{id}` and runs outside the budget of the other requests.
     * Best effort.
     */
    async readRematch(): Promise<void> {
      const id = this.battleId
      if (id === null) return
      const generation = this.generation
      try {
        const poll = await api.battles.poll(id)
        if (generation !== this.generation) return
        this.applyRematch(poll.rematch)
      } catch {
        // the next reading shows it
      }
    },

    /** One reading of the result screen: the offer of a rematch may have come, or been taken back. Best effort. */
    async refreshResult(): Promise<void> {
      if (!this.watchingResult) return
      this.resultChecks += 1
      await this.readRematch()
    },

    /** Accepts the rematch offered to me; returns the id of its battle for the route, or null (`actionError`). */
    async acceptRematch(): Promise<number | null> {
      const offer = this.rematchOffer
      if (offer === null || offer.by_me || this.acting !== null) return null

      const generation = this.generation
      this.acting = 'accept'
      this.actionError = ''
      try {
        const accepted = await api.battles.accept(offer.battle_id)
        return accepted.id
      } catch (err) {
        if (generation === this.generation) {
          await this.refuse(err)
          await this.readRematch()
        }
        return null
      } finally {
        if (generation === this.generation) this.acting = null
      }
    },

    async declineRematch(): Promise<boolean> {
      const offer = this.rematchOffer
      if (offer === null || offer.by_me || this.acting !== null) return false

      const generation = this.generation
      this.acting = 'decline'
      this.actionError = ''
      try {
        const declined = await api.battles.decline(offer.battle_id)
        if (generation === this.generation) this.applyRematch({ ...offer, status: declined.status })
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
