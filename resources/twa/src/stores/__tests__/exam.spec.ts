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
      openLevelQuiz: vi.fn(),
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

describe('exam store — test of the week', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const session = {
    session_id: 91, mode: 'level_quiz' as const, course_id: 3, week_start: '2026-11-16', scored: true,
    started_at: null, ends_at: null, total_questions: 12, seconds: 600, resumed: false,
  }

  it('openLevelQuiz returns the session id', async () => {
    vi.mocked(api.exam.openLevelQuiz).mockResolvedValue(session)
    const store = useExamStore()

    await expect(store.openLevelQuiz('english-a1-a2')).resolves.toBe(91)
    expect(api.exam.openLevelQuiz).toHaveBeenCalledWith('english-a1-a2')
    expect(store.quizError).toBe('')
    expect(store.openingQuiz).toBe(false)
  })

  it('openLevelQuiz explains too little material in the words of the test and other errors by code', async () => {
    const store = useExamStore()
    vi.mocked(api.exam.openLevelQuiz).mockRejectedValueOnce(new ApiClientError('validation', 422, 'not_enough_words', 'x'))
    await expect(store.openLevelQuiz('c')).resolves.toBeNull()
    expect(store.quizError).toBe('В курсе пока мало слов для теста недели.')

    vi.mocked(api.exam.openLevelQuiz).mockRejectedValueOnce(new ApiClientError('validation', 422, 'not_enough_distractors', 'x'))
    await store.openLevelQuiz('c')
    expect(store.quizError).toBe('Для теста недели в курсе не хватает слов для вариантов ответа.')

    vi.mocked(api.exam.openLevelQuiz).mockRejectedValueOnce(new ApiClientError('not_found', 404, 'level_quiz_disabled', 'x'))
    await store.openLevelQuiz('c')
    expect(store.quizError).toBe('Тест недели сейчас недоступен.')

    store.clearQuizError()
    expect(store.quizError).toBe('')
  })

  it('plays on one timer, like a mock', async () => {
    vi.mocked(api.exam.join).mockResolvedValue({
      session_id: 91, ends_at: null, total_questions: 12, seconds_per_question: 600,
      question: { question_index: 0, word_id: 1, word: 'cat', options: ['a', 'b', 'c', 'd'], seconds_left: 600 },
    })
    const store = useExamStore()
    await store.join(91, 'level_quiz')

    expect(store.singleTimer).toBe(true)
    expect(store.isQuiz).toBe(true)
    expect(store.isMock).toBe(false)

    await store.join(92, 'personal_mock')
    expect(store.singleTimer).toBe(true)
    await store.join(93, 'group_exam')
    expect(store.singleTimer).toBe(false)
  })

  it('keeps the result of the test of the week apart from a mock and a leaderboard', async () => {
    vi.mocked(api.exam.result).mockResolvedValue({
      mode: 'level_quiz', correct: 8, total: 12, answered: 12, percent: 66, scored: true,
      week_start: '2026-11-16', course_id: 3, course_slug: 'english-a1-a2',
    })
    const store = useExamStore()
    store.resetForSession(91)
    await store.loadResults()

    expect(store.mode).toBe('level_quiz')
    expect(store.quizResult?.correct).toBe(8)
    expect(store.mockResult).toBeNull()
    expect(store.leaderboard).toEqual([])
    expect(store.finalScore).toBe(8)
  })
})

describe('exam store — resuming a join', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const joinBase = { ends_at: null, total_questions: 12, seconds_per_question: 600 }

  it('resumes at the first unanswered question and answers with its index', async () => {
    vi.mocked(api.exam.join).mockResolvedValue({
      ...joinBase, session_id: 91,
      question: { question_index: 3, word_id: 7, word: 'cat', options: ['a', 'b'], seconds_left: 600 },
    })
    vi.mocked(api.exam.answer).mockResolvedValue({
      is_correct: true, total_score: 1, has_next: true, next_question: null,
    } as never)
    const store = useExamStore()
    await store.join(91, 'level_quiz')

    expect(store.currentIndex).toBe(3)
    expect(store.status).toBe('answering')
    expect(api.exam.question).not.toHaveBeenCalled()

    await store.submitAnswer(1)
    expect(api.exam.answer).toHaveBeenCalledWith(91, expect.objectContaining({ question_index: 3, word_id: 7 }))
  })

  it('finishes straight away when a learner session has nothing left to answer', async () => {
    vi.mocked(api.exam.join).mockResolvedValue({ ...joinBase, session_id: 91, question: null })
    vi.mocked(api.exam.result).mockResolvedValue({
      mode: 'level_quiz', correct: 8, total: 12, answered: 12, percent: 66, scored: true,
      week_start: '2026-11-16', course_id: 3, course_slug: 'english-a1-a2',
    })
    const store = useExamStore()
    await store.join(91, 'level_quiz')

    expect(api.exam.question).not.toHaveBeenCalled()
    expect(store.status).toBe('finished')
    expect(store.quizResult?.correct).toBe(8)
  })

  it('keeps loading question 0 for a group exam without a question in the join', async () => {
    vi.mocked(api.exam.join).mockResolvedValue({ ...joinBase, session_id: 5, question: null })
    vi.mocked(api.exam.question).mockResolvedValue(
      { question_index: 0, word_id: 1, word: 'w', options: ['a', 'b'], seconds_left: 10 },
    )
    const store = useExamStore()
    await store.join(5, 'group_exam')

    expect(api.exam.question).toHaveBeenCalledWith(5, 0)
    expect(store.status).toBe('answering')
    expect(api.exam.result).not.toHaveBeenCalled()
  })
})
