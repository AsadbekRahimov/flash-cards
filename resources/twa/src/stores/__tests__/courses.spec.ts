import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCoursesStore } from '../courses'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { Course, CourseDetail } from '@/api/types'

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
