import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore } from '../training'
import { ApiClientError } from '@/api/client'
import { api } from '@/api'
import type { Card, ReviewResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    training: {
      open: vi.fn(),
      start: vi.fn(),
      next: vi.fn(),
      review: vi.fn(),
      answer: vi.fn(),
      finish: vi.fn(),
    },
  },
}))

const card = (overrides: Partial<Card>): Card => ({
  word_id: 7,
  word: 'resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: null,
  ...overrides,
})

const spelling = card({
  prompt: { type: 'spelling', front: 'Устойчивый', masked_example: null, options: null, answer_length: 9 },
})

const reviewed = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 3,
  easiness_factor: 2.5,
  graded: null,
  card: card({ word_id: 8 }),
  progress: { done: 1, total: 3 },
  ...overrides,
})

async function startWith(first: Card): Promise<ReturnType<typeof useTrainingStore>> {
  const store = useTrainingStore()
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1,
    mode: 'lesson',
    lesson: { stage: 1, lesson: 1, title: null },
    total_words: 3,
    card: first,
    progress: { done: 0, total: 3 },
  })
  await store.start(1)
  return store
}

describe('training store — exercises', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reads the exercise from the prompt; a card without one is a flash card', async () => {
    const store = await startWith(card({}))
    expect(store.exercise).toBe('flash')
    store.applyCard(spelling)
    expect(store.exercise).toBe('spelling')
  })

  it('sends a typed answer, holds the next card until the verdict is seen', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockResolvedValueOnce(
      reviewed({ graded: { quality: 4, correct: true, expected: 'resilient' } }),
    )

    await store.submitAnswer('resiliant')

    expect(api.training.answer).toHaveBeenCalledWith(1, 7, 'resiliant', expect.any(Number), null)
    expect(api.training.review).not.toHaveBeenCalled()
    expect(store.status).toBe('verdict')
    expect(store.verdict).toEqual({ quality: 4, correct: true, expected: 'resilient' })
    expect(store.card?.word_id).toBe(7) // still the graded card: nothing new on screen yet
    expect(store.reviewedCount).toBe(1)
    expect(store.lastIntervalDays).toBe(3)
    expect(store.progress).toEqual({ done: 1, total: 3 })

    store.continueAfterVerdict()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.verdict).toBeNull()
    expect(store.deferred).toBeNull()
  })

  it('finishes after the verdict when the review answered with no card', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockResolvedValueOnce(
      reviewed({ graded: { quality: 1, correct: false, expected: 'resilient' }, card: null, done_reason: 'lesson_complete' }),
    )

    await store.submitAnswer('')
    expect(store.status).toBe('verdict')
    store.continueAfterVerdict()
    expect(store.status).toBe('finished')
    expect(store.doneReason).toBe('lesson_complete')
  })

  it('continueAfterVerdict is a no-op outside the verdict state', async () => {
    const store = await startWith(spelling)
    store.continueAfterVerdict()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(7)
  })

  it('echoes the learn-ahead serve_token with a typed answer', async () => {
    const store = await startWith(card({ ...spelling, card_kind: 'learning', serve_token: 'ticket-9' }))
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 5, correct: true, expected: 'resilient' } }))
    await store.submitAnswer('resilient')
    expect(vi.mocked(api.training.answer).mock.calls[0]?.[4]).toBe('ticket-9')
  })

  it.each(['answer_not_accepted', 'quality_not_accepted', 'exercise_pro_required'])(
    'reloads the card when the server expects the other kind of grade (%s)',
    async (code) => {
      const store = await startWith(spelling)
      vi.mocked(api.training.answer).mockRejectedValueOnce(new ApiClientError('validation', 422, code, 'x'))
      vi.mocked(api.training.next).mockResolvedValueOnce({ card: card({ word_id: 7 }), progress: { done: 0, total: 3 } })

      await store.submitAnswer('whatever')

      expect(api.training.next).toHaveBeenCalledTimes(1)
      expect(store.status).toBe('ready')
      expect(store.exercise).toBe('flash')
      expect(store.reviewedCount).toBe(0)
    },
  )

  it('a client-graded review with graded: null goes straight to the next card', async () => {
    const store = await startWith(card({ prompt: { type: 'reverse', front: 'Устойчивый', masked_example: null, options: ['a', 'resilient', 'c', 'd'], answer_length: null } }))
    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())

    await store.submitReview(4)

    expect(api.training.review).toHaveBeenCalledWith(1, 7, 4, expect.any(Number), null)
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.verdict).toBeNull()
  })

  it('counts every card the server serves, the same word again included', async () => {
    const store = await startWith(card({}))
    expect(store.serveSeq).toBe(1)

    // A failed learning card served ahead at once: the same word_id, a new serve.
    vi.mocked(api.training.review).mockResolvedValue(reviewed({ card: card({ serve_token: 'ticket-2', card_kind: 'learning' }) }))
    await store.submitReview(2)
    expect(store.card?.word_id).toBe(7)
    expect(store.serveSeq).toBe(2)

    // The card of a verdict counts when the learner moves on to it, not when the review answers.
    const typed = await startWith(spelling)
    const before = typed.serveSeq
    vi.mocked(api.training.answer).mockResolvedValue(
      reviewed({ graded: { quality: 5, correct: true, expected: 'resilient' }, card: card({ word_id: 9 }) }),
    )
    await typed.submitAnswer('resilient')
    expect(typed.serveSeq).toBe(before)
    typed.continueAfterVerdict()
    expect(typed.serveSeq).toBe(before + 1)
  })

  it('does not count the end of the session as a serve', async () => {
    const store = await startWith(card({}))
    vi.mocked(api.training.review).mockResolvedValue(reviewed({ card: null, done_reason: 'lesson_complete' }))

    await store.submitReview(4)

    expect(store.status).toBe('finished')
    expect(store.serveSeq).toBe(1)
  })

  it('resets the verdict with the session', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 5, correct: true, expected: 'resilient' } }))
    await store.submitAnswer('resilient')
    expect(store.status).toBe('verdict')

    await store.start(2)
    expect(store.verdict).toBeNull()
    expect(store.deferred).toBeNull()
    expect(store.status).toBe('ready')
  })
})
