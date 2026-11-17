import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import type { ReadinessResponse, ReadinessSource } from '@/api/types'

export type ReadinessStatus = 'idle' | 'loading' | 'ready' | 'error'

interface ReadinessState {
  slug: string | null
  data: ReadinessResponse | null
  status: ReadinessStatus
  errorMessage: string
  /** The server says the feature is off (404 `readiness_disabled`): the screen says so instead of offering a retry. */
  disabled: boolean
}

/** Readiness for the level of one course (docs/04 A.20): the screen's data; Home reads `/today` and not this store. */
export const useReadinessStore = defineStore('readiness', {
  state: (): ReadinessState => ({
    slug: null,
    data: null,
    status: 'idle',
    errorMessage: '',
    disabled: false,
  }),

  actions: {
    /** Always refetches (a session or a test changes the numbers); another course's data is dropped at once, the same course's stays while reloading. */
    async load(slug: string, source: ReadinessSource): Promise<void> {
      if (this.slug !== slug) {
        this.slug = slug
        this.data = null
      }
      this.status = this.data === null ? 'loading' : this.status
      this.errorMessage = ''
      this.disabled = false
      try {
        const data = await api.readiness.get(slug, source)
        if (this.slug !== slug) return
        this.data = data
        this.status = 'ready'
      } catch (err) {
        if (this.slug !== slug) return
        this.status = 'error'
        this.disabled = err instanceof ApiClientError && err.code === 'readiness_disabled'
        this.errorMessage = errorMessage(err, 'catalog')
      }
    },
  },
})
