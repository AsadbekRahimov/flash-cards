import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import { useGameStore, type GameStartResult } from '@/stores/game'
import type { GameClass } from '@/api/gameClassTypes'

/** idle → loading → ready; `not_found` (404 `class_caravan_not_found`), `unavailable` (403 `game_unavailable`) and `error` end it. */
export type GameClassLoadStatus = 'idle' | 'loading' | 'ready' | 'not_found' | 'unavailable' | 'error'

interface GameClassState {
  caravan: GameClass | null
  status: GameClassLoadStatus
  /** Localized text of the last failed reading. */
  error: string
  /** Number of the newest request: an older answer that comes late is dropped. */
  seq: number
}

/**
 * The caravan of a class (docs/05 §5b.7, docs/18 §12.9): one `GET /game/class/{id}` per opening and the start of the boss of the class.
 * The start itself is the game store's (one start at a time, typed refusals); this store never asks for the map or `/me`.
 */
export const useGameClassStore = defineStore('gameClass', {
  state: (): GameClassState => ({ caravan: null, status: 'idle', error: '', seq: 0 }),

  actions: {
    async load(id: number): Promise<void> {
      const seq = ++this.seq
      // Another caravan is not shown while this one is read; the same one stays (a quiet re-read).
      if (this.caravan?.id !== id) this.caravan = null
      this.status = this.caravan === null ? 'loading' : this.status
      this.error = ''
      try {
        const caravan = await api.gameClass.show(id)
        if (seq !== this.seq) return
        this.caravan = caravan
        this.status = 'ready'
      } catch (err) {
        if (seq !== this.seq) return
        this.caravan = null
        if (err instanceof ApiClientError && err.code === 'class_caravan_not_found') this.status = 'not_found'
        else if (err instanceof ApiClientError && err.code === 'game_unavailable') this.status = 'unavailable'
        else {
          this.status = 'error'
          this.error = errorMessage(err, 'game')
        }
      }
    },

    /** `POST /game/runs {kind: 'class_boss'}`: 201 → the id of the run; a refusal comes back typed (see the game store). */
    startBoss(id: number, replaceActive = false): Promise<GameStartResult> {
      return useGameStore().start({ kind: 'class_boss', class_caravan_id: id, ...(replaceActive ? { replace_active: true } : {}) })
    },

    /** The screen is left: an answer that comes after it is dropped. */
    reset(): void {
      this.seq++
      this.caravan = null
      this.status = 'idle'
      this.error = ''
    },
  },
})
