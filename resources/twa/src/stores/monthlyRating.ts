import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { MonthlyRatingMeResponse, MonthlyRatingResponse } from '@/api/types'

export type MonthlyRatingStatus = 'idle' | 'loading' | 'ready' | 'error'

interface MonthlyRatingState {
  data: MonthlyRatingResponse | null
  status: MonthlyRatingStatus
  errorMessage: string
  /** The light answer for the finish screen; null until fetched or when the request failed. */
  mine: MonthlyRatingMeResponse | null
}

/** The monthly rating (docs/04 A.19, docs/13 §7.6): the whole screen and the learner's own line. */
export const useMonthlyRatingStore = defineStore('monthlyRating', {
  state: (): MonthlyRatingState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
    mine: null,
  }),

  actions: {
    /** Always refetches (the top moves with every session); a stale screen stays visible while reloading. */
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.league.month()
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },

    /** The learner's line only, for the finish screen: best effort, a failure leaves nothing to show and says nothing. */
    async loadMine(): Promise<void> {
      this.mine = null
      try {
        this.mine = (await api.league.monthMe()) ?? null
      } catch {
        this.mine = null
      }
    },
  },
})
