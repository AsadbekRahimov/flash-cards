import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { COMBO_MIN, COMBO_STEP, useTrainingStore } from '../training'
import { api } from '@/api'
import type { Card, Quality, ReviewResponse } from '@/api/types'

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

const meaning: Card = {
  ...flash,
  prompt: { type: 'meaning', front: 'resilient', masked_example: null, options: ['a', 'b', 'c', 'd'], answer_length: null },
}

const spelling: Card = {
  ...flash,
  prompt: { type: 'spelling', front: 'Устойчивый', masked_example: null, options: null, answer_length: 9 },
}

const pretest: Card = {
  ...flash,
  prompt: { type: 'pretest', front: 'resilient', masked_example: null, options: ['a', 'b', 'c', 'd'], answer_length: null },
}

const reply = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 1,
  easiness_factor: 2.5,
  graded: null,
  card: flash,
  progress: { done: 1, total: 30 },
  ...overrides,
})

const graded = (correct: boolean) => ({ quality: correct ? 5 : 1, correct, expected: 'resilient' })

async function start(first: Card) {
  const store = useTrainingStore()
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1, mode: 'lesson', lesson: { stage: 1, lesson: 1, title: null }, total_words: 30, card: first, progress: { done: 0, total: 30 },
  })
  await store.start(1)
  return store
}

/** A flash card graded `quality`, then the next flash card is served. */
async function grade(store: ReturnType<typeof useTrainingStore>, quality: Quality) {
  vi.mocked(api.training.review).mockResolvedValue(reply())
  await store.submitReview(quality)
}

describe('training store — the run of right answers', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('starts empty and the thresholds are 3 and every 5th', async () => {
    const store = await start(flash)

    expect([store.combo, store.bestCombo, store.outcome.seq]).toEqual([0, 0, 0])
    expect([COMBO_MIN, COMBO_STEP]).toEqual([3, 5])
  })

  it('grows with "Got it" and "Knew it" of a flash card', async () => {
    const store = await start(flash)

    await grade(store, 4)
    await grade(store, 5)
    await grade(store, 4)

    expect(store.combo).toBe(3)
    expect(store.bestCombo).toBe(3)
    expect(store.outcome).toEqual({ seq: 3, kind: 'right' })
  })

  it('is broken by "Not yet", which is an honest grade (kind "again"), and the best run is kept', async () => {
    const store = await start(flash)
    await grade(store, 4)
    await grade(store, 4)
    await grade(store, 4)

    await grade(store, 2)

    expect(store.combo).toBe(0)
    expect(store.bestCombo).toBe(3)
    expect(store.outcome.kind).toBe('again')

    await grade(store, 5)
    expect(store.combo).toBe(1)
    expect(store.bestCombo).toBe(3)
  })

  it('counts a right pick of a four-choice card and breaks on a wrong one (kind "wrong")', async () => {
    const store = await start(meaning)
    vi.mocked(api.training.answer).mockResolvedValue(reply({ graded: graded(true), card: meaning }))
    await store.submitChoice('a')
    expect(store.combo).toBe(1)
    expect(store.outcome.kind).toBe('right')

    store.continueAfterChoice()
    vi.mocked(api.training.answer).mockResolvedValue(reply({ graded: graded(false), card: meaning }))
    await store.submitChoice('b')

    expect(store.combo).toBe(0)
    expect(store.bestCombo).toBe(1)
    expect(store.outcome).toEqual({ seq: 2, kind: 'wrong' })
  })

  it('counts a typed answer by the verdict of the server', async () => {
    const store = await start(spelling)
    vi.mocked(api.training.answer).mockResolvedValue(reply({ graded: graded(true), card: spelling }))
    await store.submitAnswer('resilient')
    store.continueAfterVerdict()
    vi.mocked(api.training.answer).mockResolvedValue(reply({ graded: graded(false), card: spelling }))
    await store.submitAnswer('resilent')

    expect([store.combo, store.bestCombo, store.outcome.kind]).toEqual([0, 1, 'wrong'])
  })

  it('leaves the pretest out: a guess before learning neither goes on nor breaks the run', async () => {
    const store = await start(flash)
    await grade(store, 4)
    await grade(store, 4)
    store.applyCard(pretest)
    vi.mocked(api.training.answer).mockResolvedValue(reply({ graded: graded(false), card: flash }))

    await store.submitAnswer("I don't know")

    expect(store.combo).toBe(2)
    expect(store.outcome.seq).toBe(2)
  })

  it('leaves the introduction card of a new word out: "Got it" there answers no question, and "Not yet" breaks nothing', async () => {
    const intro: Card = { ...flash, card_kind: 'new' }
    const store = await start(flash)
    await grade(store, 4)
    await grade(store, 4)
    store.applyCard(intro)

    vi.mocked(api.training.review).mockResolvedValue(reply({ card: intro }))
    await store.submitReview(4)
    await store.submitReview(2)
    await store.submitReview(5)

    expect([store.combo, store.bestCombo, store.outcome.seq]).toEqual([2, 2, 2])
    // The grades still went to the server and were counted as reviewed.
    expect(api.training.review).toHaveBeenCalledTimes(5)
    expect(store.reviewedCount).toBe(5)
  })

  it('counts a new word that is checked: a four-choice card of a new word (card_kind new) is an answer', async () => {
    const store = await start({ ...meaning, card_kind: 'new' })
    vi.mocked(api.training.answer).mockResolvedValue(reply({ graded: graded(true), card: meaning }))

    await store.submitChoice('a')

    expect([store.combo, store.outcome.kind]).toEqual([1, 'right'])
  })

  it('does not count an answer the server refused', async () => {
    const store = await start(flash)
    vi.mocked(api.training.review).mockRejectedValue(new Error('offline'))

    await store.submitReview(4)

    expect([store.combo, store.outcome.seq]).toEqual([0, 0])
  })

  it('is forgotten with the session, and the start of the session is noted', async () => {
    const store = await start(flash)
    await grade(store, 4)
    expect(store.startedAt).toBeGreaterThan(0)

    await start(flash)

    expect([store.combo, store.bestCombo]).toEqual([0, 0])
  })
})
