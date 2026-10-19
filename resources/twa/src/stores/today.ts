import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { TodayResponse } from '@/api/types'

export type TodayStatus = 'idle' | 'loading' | 'ready' | 'error'

interface TodayState {
  data: TodayResponse | null
  status: TodayStatus
  errorMessage: string
}

export const useTodayStore = defineStore('today', {
  state: (): TodayState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
  }),

  actions: {
    /** Always refetches: the numbers change with every grade. A stale screen stays visible while reloading. */
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.today.get()
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'today')
      }
    },
  },
})
