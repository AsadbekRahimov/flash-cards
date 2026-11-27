import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import { useTodayStore } from '@/stores/today'
import type { Course, CourseDetail, CourseLesson } from '@/api/types'

export type CatalogStatus = 'idle' | 'loading' | 'ready' | 'error'

/** A read of a course into the cache (`ensureCached`) that has not given a course yet: in flight, or failed with its message. */
export type CachedDetailLoad = { status: 'loading' } | { status: 'error'; message: string }

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
  /**
   * `GET /courses/{slug}` already read in this session, by slug: the modules under a tile of "All courses" and the first frame of the
   * course screen. Kept fresh by every read of the course (`loadDetail`), patched by a selection; a course whose row of a newer list
   * shows other progress or another active mark is dropped (a lesson was learned since), so the next reader asks again.
   */
  cached: Record<string, CourseDetail>
  /** The reads of `ensureCached` that have no course yet, by slug: apart from the catalog's `errorMessage`. */
  cachedLoads: Record<string, CachedDetailLoad>
}

/** An older server may leave `stages` out of a course without modules. */
function normalized(detail: CourseDetail): CourseDetail {
  return { ...detail, stages: detail.stages ?? [] }
}

function withoutKey<T>(record: Record<string, T>, key: string): Record<string, T> {
  const next = { ...record }
  delete next[key]
  return next
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
    cached: {},
    cachedLoads: {},
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
        this.dropOutdated(res.courses)
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

    /**
     * The open course: always asks the server (one request, as it always did). A course already in the cache is shown at once while the
     * fresh copy comes; a failure of that refresh keeps the copy on the screen without an error.
     */
    async loadDetail(slug: string): Promise<void> {
      const seq = ++this.detailSeq
      const known = this.cached[slug] ?? null
      this.errorMessage = ''
      this.detail = known
      this.detailStatus = known === null ? 'loading' : 'ready'
      try {
        const res = normalized(await api.courses.get(slug))
        this.remember(res)
        if (seq !== this.detailSeq) return
        this.detail = res
        this.detailStatus = 'ready'
      } catch (err) {
        if (seq === this.detailSeq && known === null) this.fail(err, 'detail')
      }
    },

    /** The first frame of the course screen: a course already in the cache becomes the open one at once (no request). */
    showCached(slug: string): void {
      const known = this.cached[slug]
      if (known === undefined) return
      this.detail = known
      this.detailStatus = 'ready'
    },

    /**
     * The course into the cache unless it is there or on its way (the modules of a tile of "All courses"): never touches the open
     * course, its status or the catalog's message - a failure is kept by slug in `cachedLoads`.
     */
    async ensureCached(slug: string): Promise<void> {
      if (this.cached[slug] !== undefined || this.cachedLoads[slug]?.status === 'loading') return
      this.cachedLoads = { ...this.cachedLoads, [slug]: { status: 'loading' } }
      try {
        this.remember(normalized(await api.courses.get(slug)))
        this.cachedLoads = withoutKey(this.cachedLoads, slug)
      } catch (err) {
        this.cachedLoads = { ...this.cachedLoads, [slug]: { status: 'error', message: errorMessage(err, 'catalog') } }
      }
    },

    remember(detail: CourseDetail): void {
      this.cached = { ...this.cached, [detail.slug]: detail }
      if (this.cachedLoads[detail.slug] !== undefined) this.cachedLoads = withoutKey(this.cachedLoads, detail.slug)
    },

    /** A course whose row of the fresh list differs in progress or in the active mark has changed since it was read: drop it. */
    dropOutdated(rows: Course[]): void {
      let next: Record<string, CourseDetail> | null = null
      for (const row of rows) {
        const known = this.cached[row.slug]
        if (known === undefined) continue
        const same = known.is_active === row.is_active && known.progress.done === row.progress.done && known.progress.total === row.progress.total
        if (!same) next = withoutKey(next ?? this.cached, row.slug)
      }
      if (next !== null) this.cached = next
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
        this.cached = Object.fromEntries(
          Object.entries(this.cached).map(([slug, detail]) => [slug, { ...detail, is_active: slug === chosen.slug }]),
        )
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
