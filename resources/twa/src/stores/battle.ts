import { defineStore } from 'pinia'
import { api } from '@/api'
import type { Battle, BattleIncoming, BattleListResponse, BattlePoll, BattleRound, BattleStatus } from '@/api/types'

export type BattleListStatus = 'idle' | 'loading' | 'ready' | 'error'

interface BattleState {
  /** `GET /practice/battles`: the unfinished battle, the invitations to me, the last results. */
  list: BattleListResponse | null
  listStatus: BattleListStatus
  /** Number of the newest list request: an older answer that comes late is dropped. */
  listSeq: number

  // The state of the battle screen (docs/16 §11.4). The hub does not touch it; 18.6-T fills the actions that move it.
  battle: Battle | null
  /** The last poll. */
  poll: BattlePoll | null
  /** The round on screen. */
  round: BattleRound | null
  /** The status of the battle as the server last told it. */
  status: BattleStatus | 'idle'
  /** Counts the battles the store has been reset for: a reply that comes back for an earlier one is dropped. */
  generation: number
  /** The answer of the round that is on its way (`request_id`), kept for a repeat after a network error. */
  pendingAnswer: { request_id: string; round: number } | null
  /** `server time − device time`, for the timer of a round. */
  serverOffsetMs: number
  /** The poll runs. */
  polling: boolean
}

/**
 * Battle 1 on 1 (docs/16 §11.4). In this task the store carries the state of the screen and the list that the hub
 * reads — the invitations waiting for the learner and the battle that is on; the actions of the room and of the match
 * (accept, ready, answer, poll, …) are added with the battle screens.
 */
export const useBattleStore = defineStore('battle', {
  state: (): BattleState => ({
    list: null,
    listStatus: 'idle',
    listSeq: 0,
    battle: null,
    poll: null,
    round: null,
    status: 'idle',
    generation: 0,
    pendingAnswer: null,
    serverOffsetMs: 0,
    polling: false,
  }),

  getters: {
    /** Invitations to me that have not run out. */
    incoming: (s): BattleIncoming[] => s.list?.incoming ?? [],

    /** The battle I am in (a sent invitation, the room, the match). */
    active: (s): BattleListResponse['active'] => s.list?.active ?? null,
  },

  actions: {
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
  },
})
