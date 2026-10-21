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
      openMock: vi.fn(),
    },
  },
}))

describe('exam store — personal mock (2D.4)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('openMock returns the session id and remembers nothing on success', async () => {
    vi.mocked(api.exam.openMock).mockResolvedValue({
      session_id: 12, mode: 'personal_mock', course_id: 3, started_at: null, ends_at: null,
      total_questions: 40, seconds: 1200, resumed: true,
    })

    const store = useExamStore()
    await expect(store.openMock('ielts-core')).resolves.toBe(12)
    expect(store.mockPaywall).toBe(false)
    expect(store.mockError).toBe('')
    expect(store.openingMock).toBe(false)
  })

  it('openMock turns the Pro refusal into the paywall and other errors into a message', async () => {
    vi.mocked(api.exam.openMock).mockRejectedValueOnce(
      new ApiClientError('forbidden', 403, 'exam_simulator_pro_required', 'Pro'),
    )
    const store = useExamStore()
    await expect(store.openMock('ielts-core')).resolves.toBeNull()
    expect(store.mockPaywall).toBe(true)
    expect(store.mockError).toBe('')

    store.dismissMockPaywall()
    expect(store.mockPaywall).toBe(false)

    vi.mocked(api.exam.openMock).mockRejectedValueOnce(
      new ApiClientError('validation', 422, 'course_not_exam', 'No mock.'),
    )
    await expect(store.openMock('general')).resolves.toBeNull()
    expect(store.mockPaywall).toBe(false)
    expect(store.mockError).toBe('У этого курса нет пробного теста.')
  })

  it('join records the mode and loadResults recognises the mock shape', async () => {
    vi.mocked(api.exam.join).mockResolvedValue({
      session_id: 12, ends_at: null, total_questions: 2, seconds_per_question: 1200,
      question: { question_index: 0, word_id: 1, word: 'w', options: ['a', 'b'], seconds_left: 1200 },
    })
    vi.mocked(api.exam.result).mockResolvedValue({
      mode: 'personal_mock', correct: 30, total: 40, answered: 38, percent: 75, percentile: 82, attempts: 9, course_id: 3,
    })

    const store = useExamStore()
    await store.join(12, 'personal_mock')
    expect(store.mode).toBe('personal_mock')
    expect(store.isMock).toBe(true)

    await store.loadResults()
    expect(store.mockResult?.percent).toBe(75)
    expect(store.mockResult?.percentile).toBe(82)
    expect(store.finalScore).toBe(30)
    expect(store.totalParticipants).toBe(9)
    expect(store.leaderboard).toEqual([])

    // A group result clears the mock one.
    store.resetForSession(13)
    expect(store.mode).toBe('group_exam')
    expect(store.mockResult).toBeNull()
  })
})

describe('exam store — loadResults', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('stores results on success', async () => {
    vi.mocked(api.exam.result).mockResolvedValue({
      learner_score: 42,
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
