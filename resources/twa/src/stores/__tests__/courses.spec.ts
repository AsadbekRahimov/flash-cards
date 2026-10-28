import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCoursesStore } from '../courses'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { Course, CourseDetail, CourseLesson, CourseStage } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    courses: {
      list: vi.fn(),
      get: vi.fn(),
      select: vi.fn(),
    },
  },
}))

const course = (slug: string): Course => ({
  slug,
  title: slug,
  description: null,
  level: null,
  exam_tag: null,
  words_count: 5,
  is_featured: false,
  is_active: false,
  progress: { done: 0, total: 5 },
})

const detail = (slug: string): CourseDetail => ({ ...course(slug), stages: [] })

/** A promise that the test settles by hand, to order responses. */
function deferred<T>() {
  let resolve!: (v: T) => void
  let reject!: (e: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('courses store — overlapping requests', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('keeps the list loading state when a late detail response arrives (back before the detail loaded)', async () => {
    const detailReq = deferred<CourseDetail>()
    const listReq = deferred<{ courses: Course[] }>()
    vi.mocked(api.courses.get).mockReturnValue(detailReq.promise)
    vi.mocked(api.courses.list).mockReturnValue(listReq.promise)

    const store = useCoursesStore()
    const detailLoad = store.loadDetail('a1')
    expect(store.detailStatus).toBe('loading')

    // The learner goes back to the catalog before the detail answered.
    const listLoad = store.load()
    detailReq.resolve(detail('a1'))
    await detailLoad

    expect(store.detailStatus).toBe('ready')
    expect(store.listStatus).toBe('loading')
    expect(store.courses).toEqual([])

    listReq.resolve({ courses: [course('a1')] })
    await listLoad
    expect(store.listStatus).toBe('ready')
    expect(store.courses).toHaveLength(1)
  })

  it('does not let a failing detail request turn the list into an error', async () => {
    const detailReq = deferred<CourseDetail>()
    vi.mocked(api.courses.get).mockReturnValue(detailReq.promise)
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [course('a1')] })

    const store = useCoursesStore()
    const detailLoad = store.loadDetail('a1')
    await store.load()
    detailReq.reject(new ApiClientError('not_found', 404, 'course_not_found', 'Course not found.'))
    await detailLoad

    expect(store.listStatus).toBe('ready')
    expect(store.detailStatus).toBe('error')
  })

  it('ignores an older detail response that arrives after a newer one', async () => {
    const first = deferred<CourseDetail>()
    const second = deferred<CourseDetail>()
    vi.mocked(api.courses.get).mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

    const store = useCoursesStore()
    const loadA = store.loadDetail('a1')
    const loadB = store.loadDetail('b1')

    second.resolve(detail('b1'))
    await loadB
    first.resolve(detail('a1'))
    await loadA

    expect(store.detail?.slug).toBe('b1')
    expect(store.detailStatus).toBe('ready')
  })

  it('ignores a stale detail error once a newer request is in flight', async () => {
    const first = deferred<CourseDetail>()
    const second = deferred<CourseDetail>()
    vi.mocked(api.courses.get).mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

    const store = useCoursesStore()
    const loadA = store.loadDetail('a1')
    const loadB = store.loadDetail('b1')

    first.reject(new ApiClientError('network', 0, 'network', 'offline'))
    await loadA
    expect(store.detailStatus).toBe('loading')
    expect(store.errorMessage).toBe('')

    second.resolve(detail('b1'))
    await loadB
    expect(store.detailStatus).toBe('ready')
  })
})

const lesson = (id: number, number: number, done: number, total: number): CourseLesson => ({
  id,
  number,
  title: null,
  progress: { done, total },
})

const stage = (number: number, lessons: CourseLesson[]): CourseStage => ({
  number,
  title: `Module ${number}`,
  progress: { done: 0, total: 0 },
  lessons,
})

/** The store with an open course made of the given modules. */
function storeWith(stages: CourseStage[]) {
  const store = useCoursesStore()
  store.detail = { ...detail('a1'), stages }
  return store
}

describe('courses store — next lesson ("Continue: lesson N")', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('is the first lesson that still has words to learn', () => {
    const store = storeWith([stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 1, 3), lesson(13, 3, 0, 3)])])

    expect(store.nextLesson?.id).toBe(12)
    expect(store.allLessonsDone).toBe(false)
  })

  it('carries the number of its module, because the lesson numbers restart in every module', () => {
    const store = storeWith([
      stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 3, 3)]),
      stage(2, [lesson(21, 1, 3, 3), lesson(22, 2, 0, 3)]),
    ])

    expect(store.nextLesson).toMatchObject({ id: 22, stage: 2, number: 2 })
    // The lesson of the course itself is not touched.
    expect(store.detail?.stages[1]?.lessons[1]).not.toHaveProperty('stage')
  })

  it('goes through the modules in order', () => {
    const store = storeWith([
      stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 3, 3)]),
      stage(2, [lesson(21, 1, 3, 3), lesson(22, 2, 0, 3)]),
      stage(3, [lesson(31, 1, 0, 3)]),
    ])

    expect(store.nextLesson?.id).toBe(22)
  })

  it('takes the earlier module even when a later lesson is started', () => {
    const store = storeWith([stage(1, [lesson(11, 1, 0, 3)]), stage(2, [lesson(21, 1, 2, 3)])])

    expect(store.nextLesson?.id).toBe(11)
  })

  it('skips empty lessons: they have nothing to learn', () => {
    const store = storeWith([stage(1, [lesson(11, 1, 0, 0), lesson(12, 2, 0, 4)]), stage(2, [])])

    expect(store.nextLesson?.id).toBe(12)
  })

  it('is null when every lesson is learned, and then the course is done', () => {
    const store = storeWith([stage(1, [lesson(11, 1, 3, 3), lesson(12, 2, 0, 0)]), stage(2, [lesson(21, 1, 5, 5)])])

    expect(store.nextLesson).toBeNull()
    expect(store.allLessonsDone).toBe(true)
  })

  it('does not count a course without lessons, or with empty ones only, as done', () => {
    expect(storeWith([]).nextLesson).toBeNull()
    expect(storeWith([]).allLessonsDone).toBe(false)
    expect(storeWith([stage(1, []), stage(2, [lesson(21, 1, 0, 0)])]).allLessonsDone).toBe(false)
  })

  it('is null before a course is loaded', () => {
    const store = useCoursesStore()

    expect(store.detail).toBeNull()
    expect(store.nextLesson).toBeNull()
    expect(store.allLessonsDone).toBe(false)
  })

  it('follows the open course: a new detail gives a new target', async () => {
    vi.mocked(api.courses.get).mockResolvedValueOnce({ ...detail('a1'), stages: [stage(1, [lesson(11, 1, 0, 3)])] })
    const store = useCoursesStore()

    await store.loadDetail('a1')
    expect(store.nextLesson?.id).toBe(11)

    vi.mocked(api.courses.get).mockResolvedValueOnce({ ...detail('b1'), stages: [stage(1, [lesson(41, 1, 3, 3), lesson(42, 2, 0, 3)])] })
    await store.loadDetail('b1')
    expect(store.nextLesson?.id).toBe(42)
  })
})
