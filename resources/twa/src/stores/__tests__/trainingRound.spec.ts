import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore } from '../training'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { Card, ReviewResponse, SessionSummary } from '@/api/types'

// An answer that waited for the network is kept in `sessionStorage` (docs/40 §2.6): every test starts without one.
beforeEach(() => window.sessionStorage.clear())

vi.mock('@/api', () => ({
  api: { training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() } },
}))

const flash: Card = {
  word_id: 7,
  word: 'resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: null,
}

const summary: SessionSummary = { reviewed: 12, correct: 10, new_words: 6, stages: { graduated: 6, promoted: 2, mastered: 1 } }

const reply = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 1,
  easiness_factor: 2.5,
  graded: null,
  card: null,
  progress: { done: 12, total: 12 },
  ...overrides,
})

async function start(first: Card | null, extra: Record<string, unknown> = {}) {
  const store = useTrainingStore()
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1, mode: 'daily_review', lesson: null, total_words: 12, card: first, progress: { done: 0, total: 12 }, ...extra,
  })
  await store.start(1)
  return store
}

describe('training store — the round (docs/06 §13.2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('keeps `can_continue` of the start, like the reason', async () => {
    const store = await start(null, { done_reason: 'sprint_complete', can_continue: true })

    expect([store.doneReason, store.canContinue, store.status]).toEqual(['sprint_complete', true, 'finished'])
  })

  it('is false when the server says nothing (an older server) or with a card', async () => {
    expect((await start(flash)).canContinue).toBe(false)
    expect((await start(null, { done_reason: 'sprint_complete' })).canContinue).toBe(false)
  })

  it('takes it from /next and from the answer of the last grade', async () => {
    const store = await start(flash)
    vi.mocked(api.training.review).mockResolvedValue(reply({ done_reason: 'sprint_complete', can_continue: true }))

    await store.submitReview(4)

    expect([store.doneReason, store.canContinue, store.status]).toEqual(['sprint_complete', true, 'finished'])

    const other = await start(flash)
    // An older reply with no `card` at all: the next card is asked for with /next.
    const noCard = reply()
    delete noCard.card
    vi.mocked(api.training.review).mockResolvedValue(noCard)
    vi.mocked(api.training.next).mockResolvedValue({ card: null, progress: { done: 12, total: 12 }, done_reason: 'sprint_complete', can_continue: true })
    await other.submitReview(4)

    expect([other.doneReason, other.canContinue]).toEqual(['sprint_complete', true])
  })

  it('holds it back with the card of a held answer (a four-choice card) until the learner moves on', async () => {
    const meaning: Card = { ...flash, prompt: { type: 'meaning', front: 'resilient', masked_example: null, options: ['a', 'b', 'c', 'd'], answer_length: null } }
    const store = await start(meaning)
    vi.mocked(api.training.answer).mockResolvedValue(
      reply({ graded: { quality: 5, correct: true, expected: 'a' }, done_reason: 'sprint_complete', can_continue: true }),
    )

    await store.submitChoice('a')

    expect(store.canContinue).toBe(false)
    expect(store.deferred?.canContinue).toBe(true)

    store.continueAfterChoice()

    expect([store.canContinue, store.doneReason, store.status]).toEqual([true, 'sprint_complete', 'finished'])
  })

  it('is forgotten with a new session', async () => {
    await start(null, { done_reason: 'sprint_complete', can_continue: true })

    expect((await start(flash)).canContinue).toBe(false)
  })

  describe('nextRound()', () => {
    it('opens a new daily review; the session that already has its summary is not finished again', async () => {
      const store = await start(null, { done_reason: 'sprint_complete', can_continue: true })
      store.summary = summary
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 2, mode: 'daily_review', lesson: null, total_words: 12 })

      expect(await store.nextRound()).toBe(2)

      // Only the close at the last card: the round is not finished a second time.
      expect(api.training.finish).toHaveBeenCalledTimes(1)
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      expect(store.preloaded?.sessionId).toBe(2)
    })

    it('finishes a session whose finish failed first, and only then opens the new one', async () => {
      // The close at the last card failed (best effort): the next round repeats it.
      vi.mocked(api.training.finish).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
      const store = await start(null, { done_reason: 'sprint_complete', can_continue: true })
      vi.mocked(api.training.finish).mockResolvedValue(summary)
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 3, mode: 'daily_review', lesson: null, total_words: 12 })

      expect(await store.nextRound()).toBe(3)

      expect(api.training.finish).toHaveBeenCalledWith(1)
      expect(store.summary).toEqual(summary)
      expect(api.training.finish).toHaveBeenCalledTimes(2)
      expect(vi.mocked(api.training.finish).mock.invocationCallOrder[1]).toBeLessThan(vi.mocked(api.training.open).mock.invocationCallOrder[0]!)
    })

    it('stops with a message when the finish fails again: opening would only bring the same finished session back', async () => {
      vi.mocked(api.training.finish).mockRejectedValue(new ApiClientError('server', 500, 'unknown', 'Boom'))
      const store = await start(null, { done_reason: 'sprint_complete', can_continue: true })

      expect(await store.nextRound()).toBeNull()

      expect(api.training.open).not.toHaveBeenCalled()
      expect(store.openError).not.toBe('')
      expect(store.opening).toBe(false)
    })

    it('passes the refusal of the open on and is free to be called again', async () => {
      const store = await start(null, { done_reason: 'sprint_complete', can_continue: true })
      store.summary = summary
      vi.mocked(api.training.open).mockRejectedValueOnce(new ApiClientError('server', 500, 'unknown', 'Boom'))

      expect(await store.nextRound()).toBeNull()
      expect(store.openError).not.toBe('')

      vi.mocked(api.training.open).mockResolvedValue({ session_id: 4, mode: 'daily_review', lesson: null, total_words: 12 })
      expect(await store.nextRound()).toBe(4)
      expect(store.openError).toBe('')
    })
  })
})
