import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { LeagueResponse } from '@/api/types'

export type LeagueStatus = 'idle' | 'loading' | 'ready' | 'error'

interface LeagueState {
  data: LeagueResponse | null
  status: LeagueStatus
  errorMessage: string
}

/** Leagues (docs/04 A.13): the learner's group of the week, standings and last week's result. */
export const useLeagueStore = defineStore('league', {
  state: (): LeagueState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
  }),

  actions: {
    /** Always refetches (the standings move with every grade); a stale screen stays visible while reloading. */
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.league.get()
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },
  },
})
