import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { ReferralsResponse } from '@/api/types'

export type ReferralsStatus = 'idle' | 'loading' | 'ready' | 'error'

interface ReferralsState {
  data: ReferralsResponse | null
  status: ReferralsStatus
  errorMessage: string
}

/** "Invite a friend" (docs/04 A.12): the learner's code / link, the counts and the invited learners. */
export const useReferralsStore = defineStore('referrals', {
  state: (): ReferralsState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
  }),

  actions: {
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.referrals.get()
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },
  },
})
