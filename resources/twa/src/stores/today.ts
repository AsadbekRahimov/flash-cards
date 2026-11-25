import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import { markTiming } from '@/composables/useTimingMarks'
import type { TodayResponse } from '@/api/types'

export type TodayStatus = 'idle' | 'loading' | 'ready' | 'error'

interface TodayState {
  data: TodayResponse | null
  status: TodayStatus
  errorMessage: string
  /**
   * When `data` was last taken from the server (`Date.now()`); null = never, the last request failed, or something it shows changed
   * since (`invalidate()`: the active course was switched). Only a screen that reads a slow-moving part of it (which course is active)
   * trusts a young answer (`isFresh`); "Today" itself always asks again.
   */
  loadedAt: number | null
}

/** How long `/today` counts as fresh for the slow-moving parts it carries (the active course): as long as `/me` (`ME_FRESH_MS`). */
export const TODAY_FRESH_MS = 30_000

export const useTodayStore = defineStore('today', {
  state: (): TodayState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
    loadedAt: null,
  }),

  getters: {
    /** The stored answer is younger than `TODAY_FRESH_MS` and nothing it shows was changed since. */
    isFresh: (s): boolean => s.data !== null && s.loadedAt !== null && Date.now() - s.loadedAt < TODAY_FRESH_MS,
  },

  actions: {
    /** Always refetches: the numbers change with every grade. A stale screen stays visible while reloading. */
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.today.get()
        this.status = 'ready'
        this.loadedAt = Date.now()
        markTiming('today_received')
      } catch (err) {
        this.status = 'error'
        this.loadedAt = null
        this.errorMessage = errorMessage(err, 'today')
      }
    },

    /** Something the stored answer shows has changed (the active course was switched): the next reader asks the server again. */
    invalidate(): void {
      this.loadedAt = null
    },
  },
})
