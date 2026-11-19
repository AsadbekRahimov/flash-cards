import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { ASSIGNMENTS_POSSIBLE_KEY, useAssignmentsStore } from '../assignments'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { resetSeenSession } from '@/composables/seenFlag'
import type { AssignmentItem } from '@/api/types'

vi.mock('@/api', () => ({ api: { assignments: { list: vi.fn() } } }))

const item = (id: number): AssignmentItem => ({
  id,
  title: `Lesson ${id}`,
  group: 'Group A',
  lesson_id: 40 + id,
  stage: 1,
  lesson: id,
  due_at: '2026-11-25T13:00:00+00:00',
  overdue: false,
  progress_pct: 0,
  completed: false,
  completed_at: null,
  late: false,
})

const failure = () => new ApiClientError('server', 500, 'server_error', 'boom')

describe('assignments store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
  })

  it('loads the list', async () => {
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [item(1)] })
    const store = useAssignmentsStore()

    await store.load()

    expect(store.items).toEqual([item(1)])
    expect(store.loaded).toBe(true)
    expect(store.failed).toBe(false)
    expect(store.loading).toBe(false)
  })

  it('marks a failure as a failure, not as an empty class, keeping what was there', async () => {
    vi.mocked(api.assignments.list).mockResolvedValueOnce({ assignments: [item(1)] }).mockRejectedValueOnce(failure())
    const store = useAssignmentsStore()
    await store.load()

    await store.load()

    expect(store.failed).toBe(true)
    expect(store.loaded).toBe(true)
    expect(store.items).toEqual([item(1)])
  })

  it('clears the failure once a retry succeeds', async () => {
    vi.mocked(api.assignments.list).mockRejectedValueOnce(failure()).mockResolvedValueOnce({ assignments: [item(2)] })
    const store = useAssignmentsStore()
    await store.load()
    expect(store.failed).toBe(true)
    expect(store.items).toEqual([])

    await store.load()

    expect(store.failed).toBe(false)
    expect(store.items).toEqual([item(2)])
  })

  it('does not start early on a device that knows nothing or remembers "surely none"', () => {
    const store = useAssignmentsStore()
    expect(store.loadEarly()).toBe(false)

    window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '0')
    expect(store.loadEarly()).toBe(false)
    expect(api.assignments.list).not.toHaveBeenCalled()
  })

  it('starts early on a device whose last Today said "maybe"', () => {
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '1')

    expect(useAssignmentsStore().loadEarly()).toBe(true)
    expect(api.assignments.list).toHaveBeenCalledTimes(1)
  })

  it('settles "surely none" without a request and remembers it', () => {
    const store = useAssignmentsStore()

    store.followToday(false, false)

    expect(api.assignments.list).not.toHaveBeenCalled()
    expect(store.loaded).toBe(true)
    expect(store.items).toEqual([])
    expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('0')
  })

  it('asks the list for a "maybe" and for an older server (no field), once, and remembers it', async () => {
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [item(1)] })
    const store = useAssignmentsStore()

    store.followToday(true, false)
    await flushPromises()
    expect(api.assignments.list).toHaveBeenCalledTimes(1)
    expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('1')

    store.followToday(undefined, false)
    await flushPromises()
    expect(api.assignments.list).toHaveBeenCalledTimes(2)
    expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('1')

    // Already asked alongside Today: not again.
    store.followToday(true, true)
    expect(api.assignments.list).toHaveBeenCalledTimes(2)
  })

  it('drops an answer that "surely none" made needless, even a failure', async () => {
    let fail!: (e: unknown) => void
    vi.mocked(api.assignments.list).mockReturnValue(new Promise((_, reject) => (fail = reject)))
    window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '1')
    const store = useAssignmentsStore()
    store.loadEarly()

    store.followToday(false, true)
    fail(failure())
    await flushPromises()

    expect(store.failed).toBe(false)
    expect(store.loaded).toBe(true)
    expect(store.loading).toBe(false)
    expect(store.items).toEqual([])
  })

  it('waits again for the list after "surely none" turned into "maybe" (the learner joined a group)', async () => {
    let release!: (v: { assignments: AssignmentItem[] }) => void
    vi.mocked(api.assignments.list).mockReturnValue(new Promise((resolve) => (release = resolve)))
    const store = useAssignmentsStore()
    store.followToday(false, false)
    expect(store.loaded).toBe(true)

    store.followToday(true, false)
    expect(store.loaded).toBe(false)
    expect(store.loading).toBe(true)

    release({ assignments: [item(3)] })
    await flushPromises()
    expect(store.loaded).toBe(true)
    expect(store.items).toEqual([item(3)])
  })
})
