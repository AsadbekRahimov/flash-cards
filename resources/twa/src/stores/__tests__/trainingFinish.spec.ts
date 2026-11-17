import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore } from '../training'
import { api } from '@/api'
import type { Card, ReviewResponse, SessionSummary } from '@/api/types'

vi.mock('@/api', () => ({
  api: { training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() } },
}))

const card = (prompt?: Card['prompt']): Card => ({
  word_id: 7,
  word: 'walk',
  translation: 'идти',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: null,
  ...(prompt ? { prompt } : {}),
})

const summary = (startedAt: string | undefined = '2026-11-17T10:00:00+00:00'): SessionSummary => ({
  ...(startedAt ? { started_at: startedAt } : {}),
  reviewed: 3,
  correct: 3,
  new_words: 0,
})

const last = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 1,
  easiness_factor: 2.5,
  graded: null,
  card: null,
  done_reason: 'lesson_complete',
  progress: { done: 3, total: 3 },
  ...overrides,
})

async function startWith(first: Card, extra: Record<string, unknown> = {}) {
  const store = useTrainingStore()
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1, mode: 'daily_review', lesson: null, total_words: 3, card: first, progress: { done: 2, total: 3 }, ...extra,
  })
  await store.start(1)
  return store
}

describe('training store — the round is closed at the last card', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.training.finish).mockResolvedValue(summary())
  })

  it('closes at once when the answer of a self-graded card brings no card', async () => {
    const store = await startWith(card())
    vi.mocked(api.training.review).mockResolvedValue(last())

    await store.submitReview(4)
    await flushPromises()

    expect(store.status).toBe('finished')
    expect(api.training.finish).toHaveBeenCalledTimes(1)
    expect(store.summary?.reviewed).toBe(3)
  })

  it('closes on the verdict screen of a typed answer, not when "Continue" is pressed', async () => {
    const store = await startWith(card({ type: 'spelling', front: 'идти', masked_example: null, options: null, answer_length: 4 }))
    vi.mocked(api.training.answer).mockResolvedValue(last({ graded: { quality: 5, correct: true, expected: 'walk' } }))

    await store.submitAnswer('walk')
    await flushPromises()

    expect(store.status).toBe('verdict')
    expect(api.training.finish).toHaveBeenCalledTimes(1)
    expect(store.summary).not.toBeNull()

    store.continueAfterVerdict()
    await flushPromises()
    expect(store.status).toBe('finished')
    // already closed: nothing is asked again
    expect(api.training.finish).toHaveBeenCalledTimes(1)
  })

  it('closes while the right answer of a four-choice card is still on screen', async () => {
    const store = await startWith(card({ type: 'meaning', front: 'walk', masked_example: null, options: ['идти', 'бежать'], answer_length: null }))
    vi.mocked(api.training.answer).mockResolvedValue(last({ graded: { quality: 5, correct: true, expected: 'идти' } }))

    await store.submitChoice('идти')
    await flushPromises()

    expect(store.status).toBe('held')
    expect(api.training.finish).toHaveBeenCalledTimes(1)
  })

  it('does not close while the answer brings another card', async () => {
    const store = await startWith(card())
    vi.mocked(api.training.review).mockResolvedValue(last({ card: card(), done_reason: null }))

    await store.submitReview(4)
    await flushPromises()

    expect(store.status).toBe('ready')
    expect(api.training.finish).not.toHaveBeenCalled()
  })

  it('closes a /next with no card, once even when the finish screen asks as well', async () => {
    const store = await startWith(card(), { card: undefined })
    vi.mocked(api.training.next).mockResolvedValue({ card: null, progress: { done: 3, total: 3 }, done_reason: 'nothing_due' })

    await store.loadNext()
    const again = store.finalize()
    await flushPromises()
    await again

    expect(api.training.finish).toHaveBeenCalledTimes(1)
  })

  it('keeps a failed close best effort: the finish screen asks again', async () => {
    vi.mocked(api.training.finish).mockRejectedValueOnce(new Error('offline'))
    const store = await startWith(card())
    vi.mocked(api.training.review).mockResolvedValue(last())

    await store.submitReview(4)
    await flushPromises()
    expect(store.summary).toBeNull()

    await store.finalize()
    expect(api.training.finish).toHaveBeenCalledTimes(2)
    expect(store.summary).not.toBeNull()
  })
})

describe('training store — the start of the session is the server\'s', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('takes `started_at` of the start answer, not the clock of the device', async () => {
    const store = await startWith(card(), { started_at: '2026-11-17T10:00:00+00:00' })

    expect(store.startedAt).toBe(Date.parse('2026-11-17T10:00:00Z'))
  })

  it('takes it from an opened session too (the answer of POST /sessions)', async () => {
    const store = useTrainingStore()
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 1, mode: 'daily_review', started_at: '2026-11-17T10:00:00+00:00', lesson: null, total_words: 3, card: card(), progress: { done: 0, total: 3 },
    })
    await store.open({ mode: 'daily_review' })
    await store.start(1)

    expect(store.startedAt).toBe(Date.parse('2026-11-17T10:00:00Z'))
  })

  it('falls back to the clock of the device when an older server sends none', async () => {
    const before = Date.now()
    const store = await startWith(card())

    expect(store.startedAt).toBeGreaterThanOrEqual(before)
  })

  it('takes the start of the finish summary as the newest word on it', async () => {
    vi.mocked(api.training.finish).mockResolvedValue(summary('2026-11-17T09:59:30+00:00'))
    const store = await startWith(card(), { started_at: '2026-11-17T10:00:00+00:00' })

    await store.finalize()

    expect(store.startedAt).toBe(Date.parse('2026-11-17T09:59:30Z'))
  })

  it('ignores a start that is not a date', async () => {
    const before = Date.now()
    const store = await startWith(card(), { started_at: 'yesterday' })

    expect(store.startedAt).toBeGreaterThanOrEqual(before)
  })
})
