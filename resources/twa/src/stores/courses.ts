import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { Course, CourseDetail, CourseLesson } from '@/api/types'

export type CatalogStatus = 'idle' | 'loading' | 'ready' | 'error'

interface CoursesState {
  courses: Course[]
  detail: CourseDetail | null
  /** state of the list request; independent of the detail request (the screens overlap on "back") */
  listStatus: CatalogStatus
  detailStatus: CatalogStatus
  /** newest request number per kind: an older response that arrives late is ignored */
  listSeq: number
  detailSeq: number
  /** slug of the course whose selection is in flight, if any */
  selecting: string | null
  errorMessage: string
}

export const useCoursesStore = defineStore('courses', {
  state: (): CoursesState => ({
    courses: [],
    detail: null,
    listStatus: 'idle',
    detailStatus: 'idle',
    listSeq: 0,
    detailSeq: 0,
    selecting: null,
    errorMessage: '',
  }),

  getters: {
    /**
     * The first lesson of the open course that still has words to learn, in the order of the modules and of
     * the lessons in them: the target of "Continue: lesson N". An empty lesson (no words) is never the target.
     */
    nextLesson: (s): CourseLesson | null => {
      for (const stage of s.detail?.stages ?? []) {
        const lesson = stage.lessons.find((l) => l.progress.done < l.progress.total)
        if (lesson) return lesson
      }
      return null
    },

    /** The open course has lessons with words and every one of them is learned (a course without lessons is not "done"). */
    allLessonsDone: (s): boolean => {
      const lessons = (s.detail?.stages ?? []).flatMap((stage) => stage.lessons).filter((l) => l.progress.total > 0)
      return lessons.length > 0 && lessons.every((l) => l.progress.done >= l.progress.total)
    },
  },

  actions: {
    async load(): Promise<void> {
      const seq = ++this.listSeq
      this.listStatus = 'loading'
      this.errorMessage = ''
      try {
        const res = await api.courses.list()
        if (seq !== this.listSeq) return
        this.courses = res.courses
        this.listStatus = 'ready'
      } catch (err) {
        if (seq === this.listSeq) this.fail(err, 'list')
      }
    },

    async loadDetail(slug: string): Promise<void> {
      const seq = ++this.detailSeq
      this.detailStatus = 'loading'
      this.errorMessage = ''
      this.detail = null
      try {
        const res = await api.courses.get(slug)
        if (seq !== this.detailSeq) return
        this.detail = res
        this.detailStatus = 'ready'
      } catch (err) {
        if (seq === this.detailSeq) this.fail(err, 'detail')
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

    fail(err: unknown, kind: 'list' | 'detail'): void {
      if (kind === 'list') this.listStatus = 'error'
      else this.detailStatus = 'error'
      this.errorMessage = errorMessage(err, 'catalog')
    },
  },
})
