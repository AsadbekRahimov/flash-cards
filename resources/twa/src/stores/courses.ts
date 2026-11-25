import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import { useTodayStore } from '@/stores/today'
import type { Course, CourseDetail, CourseLesson } from '@/api/types'

export type CatalogStatus = 'idle' | 'loading' | 'ready' | 'error'

/** A lesson of the open course with the number of its module: lesson numbers restart in every module. */
export type LessonInModule = CourseLesson & { stage: number }

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
     * the lessons in them: the target of "Continue: module M, lesson N" (`stage` is the module's number, `number`
     * the lesson's own). An empty lesson (no words) is never the target.
     */
    nextLesson: (s): LessonInModule | null => {
      for (const stage of s.detail?.stages ?? []) {
        const lesson = stage.lessons.find((l) => l.progress.done < l.progress.total)
        if (lesson) return { ...lesson, stage: stage.number }
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

    /**
     * The list for a screen that only needs to know which course is active (the course screen opened by a link): takes it
     * when the store has none yet, and never touches the status or the message of the screens - a failure changes nothing.
     */
    async loadListQuietly(): Promise<void> {
      if (this.courses.length > 0) return
      try {
        const res = await api.courses.list()
        if (this.courses.length === 0) this.courses = res.courses
      } catch {
        // the button then says "Start course"; the server decides anyway
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

    /**
     * Makes `slug` the active course; the server answers with the refreshed course row.
     *
     * @returns whether the course was chosen (a screen then leads to Home)
     */
    async select(slug: string): Promise<boolean> {
      if (this.selecting !== null) return false
      this.selecting = slug
      this.errorMessage = ''
      try {
        const chosen = await api.courses.select(slug)
        // `/today` names the active course: a young copy of it no longer tells where the "Course" tab leads.
        useTodayStore().invalidate()
        // `is_active` of the previous active course flips off, the chosen one on.
        this.courses = this.courses.map((c) =>
          c.slug === chosen.slug ? chosen : { ...c, is_active: false },
        )
        if (this.detail?.slug === chosen.slug) this.detail = { ...this.detail, is_active: true }
        else if (this.detail) this.detail = { ...this.detail, is_active: false }
        return true
      } catch (err) {
        this.errorMessage = errorMessage(err, 'catalog')
        return false
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
