import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useExamStore } from '../exam'
import { ApiClientError } from '@/api/client'
import { api } from '@/api'

vi.mock('@/api', () => ({
  api: {
    exam: {
      join: vi.fn(),
      question: vi.fn(),
      answer: vi.fn(),
      result: vi.fn(),
    },
  },
}))

describe('exam store — loadResults', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('stores results on success', async () => {
    vi.mocked(api.exam.result).mockResolvedValue({
      student_score: 42,
      rank: 2,
      total_participants: 7,
      leaderboard: [{ rank: 1, name: 'Anna', score: 50 }],
    })

    const store = useExamStore()
    store.resetForSession(1)
    await store.loadResults()

    expect(store.finalScore).toBe(42)
    expect(store.finalRank).toBe(2)
    expect(store.totalParticipants).toBe(7)
    expect(store.leaderboard).toHaveLength(1)
  })

  it('treats 409 exam_still_open as "results pending", not an error', async () => {
    vi.mocked(api.exam.result).mockRejectedValue(
      new ApiClientError('conflict', 409, 'exam_still_open', 'Results are available only after the exam is closed.'),
    )

    const store = useExamStore()
    store.resetForSession(1)
    store.status = 'finished'
    await store.loadResults()

    expect(store.status).toBe('finished')
    expect(store.errorMessage).toBe('')
    expect(store.leaderboard).toHaveLength(0)
  })

  it('still surfaces real errors', async () => {
    vi.mocked(api.exam.result).mockRejectedValue(
      new ApiClientError('server', 500, 'internal', 'Boom'),
    )

    const store = useExamStore()
    store.resetForSession(1)
    await store.loadResults()

    expect(store.status).toBe('error')
    expect(store.errorMessage).not.toBe('')
  })
})
