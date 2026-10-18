import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { Course, CourseDetail } from '@/api/types'

export type CatalogStatus = 'idle' | 'loading' | 'ready' | 'error'

interface CoursesState {
  courses: Course[]
  detail: CourseDetail | null
  status: CatalogStatus
  /** slug of the course whose selection is in flight, if any */
  selecting: string | null
  errorMessage: string
}

export const useCoursesStore = defineStore('courses', {
  state: (): CoursesState => ({
    courses: [],
    detail: null,
    status: 'idle',
    selecting: null,
    errorMessage: '',
  }),

  actions: {
    async load(): Promise<void> {
      this.status = 'loading'
      this.errorMessage = ''
      try {
        this.courses = (await api.courses.list()).courses
        this.status = 'ready'
      } catch (err) {
        this.fail(err)
      }
    },

    async loadDetail(slug: string): Promise<void> {
      this.status = 'loading'
      this.errorMessage = ''
      this.detail = null
      try {
        this.detail = await api.courses.get(slug)
        this.status = 'ready'
      } catch (err) {
        this.fail(err)
      }
    },

    /** Makes `slug` the active course; the server answers with the refreshed course row. */
    async select(slug: string): Promise<void> {
      if (this.selecting !== null) return
      this.selecting = slug
      this.errorMessage = ''
      try {
        const chosen = await api.courses.select(slug)
        // `is_active` of the previous active course flips off, the chosen one on.
        this.courses = this.courses.map((c) =>
          c.slug === chosen.slug ? chosen : { ...c, is_active: false },
        )
        if (this.detail?.slug === chosen.slug) this.detail = { ...this.detail, is_active: true }
        else if (this.detail) this.detail = { ...this.detail, is_active: false }
      } catch (err) {
        this.errorMessage = errorMessage(err, 'catalog')
      } finally {
        this.selecting = null
      }
    },

    fail(err: unknown): void {
      this.status = 'error'
      this.errorMessage = errorMessage(err, 'catalog')
    },
  },
})
