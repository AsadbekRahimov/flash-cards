import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { useTrainingStore } from '../training'
import type { SessionSummary, TrainingStartResponse } from '@/api/types'

vi.mock('@/api', () => ({ api: { training: { start: vi.fn(), next: vi.fn(), finish: vi.fn() } } }))

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
const started = (id: number): TrainingStartResponse => ({
  session_id: id, mode: 'lesson', lesson: null, total_words: 2,
  card: { word_id: id, word: `word-${id}`, translation: 'слово', translation_fallback: false,
    example: null, transcription: null, card_kind: 'new', serve_token: null },
  progress: { done: 0, total: 2 },
})

beforeEach(() => { setActivePinia(createPinia()); vi.resetAllMocks() })

describe('training: responses belong to the visit that requested them', () => {
  it('ignores a late start of an earlier session', async () => {
    const old = pending<TrainingStartResponse>()
    vi.mocked(api.training.start).mockReturnValueOnce(old.promise).mockResolvedValueOnce(started(2))
    const store = useTrainingStore()
    const first = store.start(1)
    await store.start(2)
    old.resolve(started(1))
    await first
    expect(store.sessionId).toBe(2)
    expect(store.card?.word_id).toBe(2)
  })

  it('ignores a late start failure from an earlier visit to the same session', async () => {
    const old = pending<TrainingStartResponse>()
    vi.mocked(api.training.start).mockReturnValueOnce(old.promise).mockResolvedValueOnce(started(1))
    const store = useTrainingStore()
    const first = store.start(1)
    await store.start(1)
    old.reject(new Error('offline'))
    await first
    expect(store.status).toBe('ready')
  })

  it('does not replace a new session with the old next-card response', async () => {
    vi.mocked(api.training.start).mockResolvedValueOnce(started(1)).mockResolvedValueOnce(started(2))
    const store = useTrainingStore()
    await store.start(1)
    const old = pending<Awaited<ReturnType<typeof api.training.next>>>()
    vi.mocked(api.training.next).mockReturnValueOnce(old.promise)
    const next = store.loadNext()
    await store.start(2)
    old.resolve({ card: null, progress: { done: 2, total: 2 } })
    await next
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(2)
  })

  it('does not attach the previous summary to a new session', async () => {
    vi.mocked(api.training.start).mockResolvedValueOnce(started(1)).mockResolvedValueOnce(started(2))
    const store = useTrainingStore()
    await store.start(1)
    const old = pending<SessionSummary>()
    vi.mocked(api.training.finish).mockReturnValueOnce(old.promise)
    const finish = store.finalize()
    await store.start(2)
    old.resolve({ reviewed: 9 } as SessionSummary)
    await finish
    expect(store.summary).toBeNull()
  })
})
