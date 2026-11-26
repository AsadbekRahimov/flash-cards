import { defineStore } from 'pinia'
import { api } from '@/api'
import { cupFailure, type CupFailure } from '@/composables/cupInfo'
import type { CupBoardResponse } from '@/api/types'

export type CupBoardStatus = 'idle' | 'loading' | 'ready' | 'failed'

interface CupBoardState {
  cupId: number | null
  board: CupBoardResponse | null
  status: CupBoardStatus
  failure: CupFailure | null
  /** When `board` was read (`Date.now()`): the round screen opened from the cup screen does not read it again. */
  loadedAt: number | null
  /** Counts the reads: a reply for an earlier one is dropped. */
  seq: number
}

/** How long the table read by the cup screen serves the round screen opened from it (the header, my day). */
export const CUP_BOARD_FRESH_MS = 30_000

/**
 * The table of a cup and my day (docs/04 A.23 `GET /cups/{id}`): the cup screen reads it on every opening; the round screen takes it
 * when it is young and of the same cup (no second request with a call to Telegram behind it).
 */
export const useCupBoardStore = defineStore('cupBoard', {
  state: (): CupBoardState => ({
    cupId: null,
    board: null,
    status: 'idle',
    failure: null,
    loadedAt: null,
    seq: 0,
  }),

  getters: {
    isFreshFor: (s) => (id: number): boolean => s.cupId === id && s.board !== null && s.loadedAt !== null && Date.now() - s.loadedAt < CUP_BOARD_FRESH_MS,
  },

  actions: {
    /**
     * Reads the table of `id`; the one of another cup is dropped first, the same cup stays on screen while it is read again. A refused
     * read drops it too: one who left the chat (403) must not keep the names and the points, and a failed check is a state with "Retry".
     */
    async load(id: number): Promise<CupBoardResponse | null> {
      const seq = ++this.seq
      if (this.cupId !== id) {
        this.board = null
        this.loadedAt = null
      }
      this.cupId = id
      this.status = this.board === null ? 'loading' : this.status
      this.failure = null
      try {
        const board = await api.cups.show(id)
        if (seq !== this.seq) return null
        this.board = board
        this.loadedAt = Date.now()
        this.status = 'ready'
        return board
      } catch (err) {
        if (seq !== this.seq) return null
        this.board = null
        this.loadedAt = null
        this.failure = cupFailure(err)
        this.status = 'failed'
        return null
      }
    },

    /** The table is out of date (a round was played, a name changed): the next reader asks again. */
    invalidate(): void {
      this.loadedAt = null
    },
  },
})
