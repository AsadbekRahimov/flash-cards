import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { AchievementsResponse } from '@/api/types'

export type AchievementsStatus = 'idle' | 'loading' | 'ready' | 'error'

interface AchievementsState {
  data: AchievementsResponse | null
  status: AchievementsStatus
  errorMessage: string
}

/** Achievements (docs/04 A.15): the catalog with the learner's state, and the "shared" mark. */
export const useAchievementsStore = defineStore('achievements', {
  state: (): AchievementsState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
  }),

  actions: {
    async load(): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.achievements.list()
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },

    /** Best effort: a failed mark must not break the share the learner just made. */
    async markShared(key: string): Promise<void> {
      try {
        this.data = await api.achievements.shared(key)
      } catch {
        /* the next load shows the real state */
      }
    },
  },
})
