import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore, type PendingAnswer } from '../training'
import { ApiClientError } from '@/api/client'
import { api } from '@/api'
import type { Card, ReviewResponse, TrainingStartResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    today: { get: vi.fn() },
    training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() },
  },
}))

const KEY = 'training:pending-review:1'

const offline = () => new ApiClientError('network', 0, 'unknown', 'fetch failed')
const notDue = () => new ApiClientError('conflict', 409, 'card_not_due', 'Card is not due.')
const gone = () => new ApiClientError('gone', 410, 'session_closed', 'This training session is closed.')

const card = (overrides: Partial<Card> = {}): Card => ({
  word_id: 7,
  word: 'walk',
  translation: 'идти',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: 'ticket-7',
  ...overrides,
})

const started = (overrides: Partial<TrainingStartResponse> = {}): TrainingStartResponse => ({
  session_id: 1,
  mode: 'lesson',
  lesson: { stage: 1, lesson: 3, title: null },
  total_words: 8,
  card: card(),
  progress: { done: 4, total: 8 },
  done_reason: null,
  ...overrides,
})

const reviewed = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 2,
  easiness_factor: 2.5,
  graded: null,
  card: card({ word_id: 8, serve_token: 'ticket-8' }),
  progress: { done: 5, total: 8 },
  ...overrides,
})

const stored = (): PendingAnswer | null => {
  const raw = window.sessionStorage.getItem(KEY)
  return raw === null ? null : (JSON.parse(raw) as PendingAnswer)
}

/** A started lesson whose grade of the first card was lost to the network. */
async function lostGrade() {
  vi.mocked(api.training.start).mockResolvedValueOnce(started())
  const store = useTrainingStore()
  await store.start(1)
  vi.mocked(api.training.review).mockRejectedValueOnce(offline())
  await store.submitReview(4)
  return store
}

describe('training store — an answer lost to the network (F5, docs/40 §2.6)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.sessionStorage.clear()
  })

  it('keeps the answer in the store and in sessionStorage, and shows the error state', async () => {
    const store = await lostGrade()

    expect(store.status).toBe('error')
    expect(store.networkError).toBe(true)
    expect(store.reviewedCount).toBe(0)
    expect(store.pending).toMatchObject({ sessionId: 1, wordId: 7, serveToken: 'ticket-7', exercise: 'flash', quality: 4, answer: null })
    expect(stored()).toEqual(store.pending)
    // The progress is still the server's: the lost answer is not counted.
    expect(store.progress).toEqual({ done: 4, total: 8 })
  })

  it('"Retry" sends the very same request once; 200 forgets it and shows the next card', async () => {
    const store = await lostGrade()
    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())

    await store.resendPending()

    expect(api.training.review).toHaveBeenCalledTimes(2)
    expect(vi.mocked(api.training.review).mock.calls[1]).toEqual(vi.mocked(api.training.review).mock.calls[0])
    expect(vi.mocked(api.training.review).mock.calls[1]?.slice(0, 3)).toEqual([1, 7, 4])
    expect(vi.mocked(api.training.review).mock.calls[1]?.[4]).toBe('ticket-7')
    expect(store.pending).toBeNull()
    expect(stored()).toBeNull()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.progress).toEqual({ done: 5, total: 8 })
    expect(store.reviewedCount).toBe(1)
  })

  it('two presses while the answer is on its way send it once', async () => {
    const store = await lostGrade()
    let release: (value: ReviewResponse) => void = () => {}
    vi.mocked(api.training.review).mockReturnValueOnce(new Promise((resolve) => (release = resolve)))

    const first = store.resendPending()
    const second = store.resendPending()
    release(reviewed())
    await Promise.all([first, second])

    expect(api.training.review).toHaveBeenCalledTimes(2)
    expect(store.reviewedCount).toBe(1)
  })

  it('the answer had arrived after all: 409 card_not_due forgets it and asks for the card, the session goes on', async () => {
    const store = await lostGrade()
    vi.mocked(api.training.review).mockRejectedValueOnce(notDue())
    vi.mocked(api.training.next).mockResolvedValueOnce({ card: card({ word_id: 8 }), progress: { done: 5, total: 8 } })

    await store.resendPending()

    expect(store.pending).toBeNull()
    expect(stored()).toBeNull()
    expect(api.training.next).toHaveBeenCalledTimes(1)
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.progress).toEqual({ done: 5, total: 8 })
  })

  it('410: the answer is forgotten and the session is closed', async () => {
    const store = await lostGrade()
    vi.mocked(api.training.review).mockRejectedValueOnce(gone())

    await store.resendPending()

    expect(store.pending).toBeNull()
    expect(stored()).toBeNull()
    expect(store.status).toBe('closed')
  })

  it('still no network: the answer keeps waiting', async () => {
    const store = await lostGrade()
    vi.mocked(api.training.review).mockRejectedValueOnce(offline())

    await store.resendPending()

    expect(store.status).toBe('error')
    expect(store.pending?.serveToken).toBe('ticket-7')
    expect(stored()?.serveToken).toBe('ticket-7')
  })

  it('a pick of a four-choice card goes again as `answer` and moves on at once (its card is no longer on screen)', async () => {
    vi.mocked(api.training.start).mockResolvedValueOnce(
      started({
        card: card({ prompt: { type: 'reverse', front: 'идти', masked_example: null, options: ['run', 'walk', 'sit', 'eat'], answer_length: null } }),
      }),
    )
    const store = useTrainingStore()
    await store.start(1)
    vi.mocked(api.training.answer).mockRejectedValueOnce(offline())
    await store.submitChoice('walk')
    expect(store.pending).toMatchObject({ answer: 'walk', quality: null, hold: true, exercise: 'reverse' })

    vi.mocked(api.training.answer).mockResolvedValueOnce(
      reviewed({ graded: { quality: 4, correct: true, expected: 'walk' } }),
    )
    await store.resendPending()

    expect(vi.mocked(api.training.answer).mock.calls[1]).toEqual(vi.mocked(api.training.answer).mock.calls[0])
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
  })

  it('a new card served by "next" makes the waiting answer void', async () => {
    const store = await lostGrade()
    vi.mocked(api.training.next).mockResolvedValueOnce({ card: card({ word_id: 9 }), progress: { done: 4, total: 8 } })

    await store.loadNext()

    expect(store.pending).toBeNull()
    expect(stored()).toBeNull()
  })

  it('a new session forgets the answer of the old one in the store, not in sessionStorage', async () => {
    const store = await lostGrade()
    vi.mocked(api.training.start).mockResolvedValueOnce(started({ session_id: 2 }))

    await store.start(2)

    expect(store.pending).toBeNull()
    expect(stored()?.sessionId).toBe(1)
  })

  it('does nothing without a waiting answer', async () => {
    vi.mocked(api.training.start).mockResolvedValueOnce(started())
    const store = useTrainingStore()
    await store.start(1)

    await store.resendPending()

    expect(api.training.review).not.toHaveBeenCalled()
  })
})

describe('training store — a reload of the tab with a waiting answer', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.sessionStorage.clear()
  })

  /** The answer lost before the reload; a fresh Pinia is the new load of the tab. */
  async function reloadAfterLostGrade() {
    setActivePinia(createPinia())
    await lostGrade()
    vi.clearAllMocks()
    setActivePinia(createPinia())
    return useTrainingStore()
  }

  it('sends the answer first, then starts the session as usual', async () => {
    const store = await reloadAfterLostGrade()
    const order: string[] = []
    vi.mocked(api.training.review).mockImplementationOnce(async () => {
      order.push('review')
      return reviewed()
    })
    vi.mocked(api.training.start).mockImplementationOnce(async () => {
      order.push('start')
      return started({ card: card({ word_id: 8, serve_token: 'ticket-8b' }), progress: { done: 5, total: 8 } })
    })

    await store.start(1)

    expect(order).toEqual(['review', 'start'])
    expect(vi.mocked(api.training.review).mock.calls[0]?.slice(0, 3)).toEqual([1, 7, 4])
    expect(vi.mocked(api.training.review).mock.calls[0]?.[4]).toBe('ticket-7')
    expect(stored()).toBeNull()
    expect(store.pending).toBeNull()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.progress).toEqual({ done: 5, total: 8 })
  })

  it('409 for the answer (it had arrived): forgotten, the session starts', async () => {
    const store = await reloadAfterLostGrade()
    vi.mocked(api.training.review).mockRejectedValueOnce(notDue())
    vi.mocked(api.training.start).mockResolvedValueOnce(started({ card: card({ word_id: 8 }) }))

    await store.start(1)

    expect(stored()).toBeNull()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
  })

  it('still no network: nothing is started, the answer waits, the error state shows', async () => {
    const store = await reloadAfterLostGrade()
    vi.mocked(api.training.review).mockRejectedValueOnce(offline())

    await store.start(1)

    expect(api.training.start).not.toHaveBeenCalled()
    expect(store.status).toBe('error')
    expect(store.networkError).toBe(true)
    expect(store.pending?.wordId).toBe(7)
    expect(stored()?.wordId).toBe(7)
  })

  it('410 for the answer: the session is closed, nothing is started', async () => {
    const store = await reloadAfterLostGrade()
    vi.mocked(api.training.review).mockRejectedValueOnce(gone())

    await store.start(1)

    expect(api.training.start).not.toHaveBeenCalled()
    expect(store.status).toBe('closed')
    expect(stored()).toBeNull()
  })

  it('the answer of the open is not used when a waiting answer went first', async () => {
    const store = await reloadAfterLostGrade()
    vi.mocked(api.training.open).mockResolvedValueOnce(started({ card: card({ word_id: 7, serve_token: 'ticket-7b' }) }))
    await store.open({ mode: 'lesson', lesson_id: 3, source: 'self' })
    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())
    vi.mocked(api.training.start).mockResolvedValueOnce(started({ card: card({ word_id: 8 }) }))

    await store.start(1)

    expect(api.training.start).toHaveBeenCalledTimes(1)
    expect(store.card?.word_id).toBe(8)
  })

  it('a stored value that is not an answer of this build is dropped', async () => {
    window.sessionStorage.setItem(KEY, '{"sessionId":1,"wordId":"x"}')
    setActivePinia(createPinia())
    vi.mocked(api.training.start).mockResolvedValueOnce(started())

    await useTrainingStore().start(1)

    expect(api.training.review).not.toHaveBeenCalled()
    expect(window.sessionStorage.getItem(KEY)).toBeNull()
  })
})
