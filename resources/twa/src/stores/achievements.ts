import { defineStore } from 'pinia'
import { api } from '@/api'
import { BACKGROUND } from '@/api/background'
import type { RequestOptions } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { AchievementsResponse } from '@/api/types'

export type AchievementsStatus = 'idle' | 'loading' | 'ready' | 'error'

interface AchievementsState {
  data: AchievementsResponse | null
  status: AchievementsStatus
  errorMessage: string
  /** A quiet read of `ensureLoaded()` is in flight (it never touches `status`). */
  ensuring: boolean
}

/** Achievements (docs/04 A.15): the catalog with the learner's state, and the "shared" mark. */
export const useAchievementsStore = defineStore('achievements', {
  state: (): AchievementsState => ({
    data: null,
    status: 'idle',
    errorMessage: '',
    ensuring: false,
  }),

  actions: {
    /** `options` - `BACKGROUND` when another screen reads it by the way (the finish of a lesson): its loss does not raise "Нет соединения". */
    async load(options?: RequestOptions): Promise<void> {
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        this.data = await api.achievements.list(options)
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },

    /**
     * The profile's read (best effort): asks only while nothing is known and nothing is in flight. A failure stays quiet — `status` and
     * `errorMessage` are left as they were, so the "Achievements" screen never inherits an error of a background read; it loads fresh
     * data itself (`load()`).
     */
    async ensureLoaded(): Promise<void> {
      if (this.data !== null || this.status === 'loading' || this.ensuring) return
      this.ensuring = true
      try {
        const data = await api.achievements.list(BACKGROUND)
        this.data = data
        this.status = 'ready'
        this.errorMessage = ''
      } catch {
        /* the profile simply shows no row of badges */
      } finally {
        this.ensuring = false
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
