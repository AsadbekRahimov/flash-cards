import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { usePracticeStore } from '../practice'
import { answerResponse, dictationElement, hub, session, sentenceElement } from '@/api/__tests__/practiceFixtures'
import type { PracticeAnswerResponse, PracticeHubResponse, PracticeSession } from '@/api/types'

vi.mock('@/api', () => ({
  api: { practice: { hub: vi.fn(), open: vi.fn(), get: vi.fn(), answer: vi.fn(), replace: vi.fn() } },
}))

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')
const gone = () => new ApiClientError('gone', 410, 'practice_session_closed', 'closed')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

describe('practice: the answer belongs to the visit that asked for it', () => {
  it('ignores a late start of an earlier session', async () => {
    const old = pending<PracticeSession>()
    vi.mocked(api.practice.get).mockReturnValueOnce(old.promise).mockResolvedValueOnce(session({ id: 2 }))
    const store = usePracticeStore()

    const first = store.load(1)
    await store.load(2)
    old.resolve(session({ id: 1 }))
    await first

    expect(store.sessionId).toBe(2)
    expect(store.session?.id).toBe(2)
    expect(store.status).toBe('ready')
  })

  it('ignores a late failure of an earlier visit to the same session', async () => {
    const old = pending<PracticeSession>()
    vi.mocked(api.practice.get).mockReturnValueOnce(old.promise).mockResolvedValueOnce(session())
    const store = usePracticeStore()

    const first = store.load(77)
    await store.load(77)
    old.reject(offline())
    await first

    expect(store.status).toBe('ready')
    expect(store.errorMessage).toBe('')
  })

  it('ignores a late 410 of an earlier session', async () => {
    const old = pending<PracticeSession>()
    vi.mocked(api.practice.get).mockReturnValueOnce(old.promise).mockResolvedValueOnce(session({ id: 2 }))
    const store = usePracticeStore()

    const first = store.load(1)
    await store.load(2)
    old.reject(gone())
    await first

    expect(store.status).toBe('ready')
  })

  it('does not put the verdict of an earlier session over the new one', async () => {
    vi.mocked(api.practice.get).mockResolvedValueOnce(session({ id: 1 })).mockResolvedValueOnce(session({ id: 2 }))
    const store = usePracticeStore()
    await store.load(1)
    const old = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValueOnce(old.promise)

    const answering = store.answer({ order: ['fW2'] })
    await store.load(2)
    old.resolve(answerResponse({ session: session({ id: 1, progress: { done: 1, total: 5 } }) }))
    await answering

    expect(store.status).toBe('ready')
    expect(store.session?.id).toBe(2)
    expect(store.outcome).toBeNull()
    expect(store.progress).toEqual({ done: 0, total: 5 })
  })

  it('does not show the failure of an earlier session’s answer in the new one', async () => {
    vi.mocked(api.practice.get).mockResolvedValueOnce(session({ id: 1 })).mockResolvedValueOnce(session({ id: 2 }))
    const store = usePracticeStore()
    await store.load(1)
    const old = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValueOnce(old.promise)

    const answering = store.answer({ order: ['fW2'] })
    await store.load(2)
    old.reject(offline())
    await answering

    expect(store.status).toBe('ready')
    expect(store.actionError).toBe('')
    expect(store.pendingRequestId).toBeNull()
  })

  it('does not re-read an earlier session after its conflict', async () => {
    vi.mocked(api.practice.get).mockResolvedValueOnce(session({ id: 1 })).mockResolvedValueOnce(session({ id: 2 }))
    const store = usePracticeStore()
    await store.load(1)
    const old = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValueOnce(old.promise)

    const answering = store.answer({ order: ['fW2'] })
    await store.load(2)
    old.reject(new ApiClientError('conflict', 409, 'element_not_current', 'x'))
    await answering

    expect(api.practice.get).toHaveBeenCalledTimes(2) // the two visits; no third read for the dead one
    expect(store.session?.id).toBe(2)
  })

  it('does not apply the swap of an earlier dictation to the new session', async () => {
    vi.mocked(api.practice.get).mockResolvedValueOnce(session({ id: 1, mode: 'dictation' })).mockResolvedValueOnce(session({ id: 2 }))
    const store = usePracticeStore()
    await store.load(1)
    const old = pending<PracticeSession>()
    vi.mocked(api.practice.replace).mockReturnValueOnce(old.promise)

    const replacing = store.replace()
    await store.load(2)
    old.resolve(session({ id: 1, mode: 'dictation', current: dictationElement({ id: 'eDict00002' }) }))

    expect(await replacing).toBe(false)
    expect(store.session?.id).toBe(2)
  })
})

describe('practice: a tap is one request', () => {
  it('two taps on "Check" are one answer', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(session())
    const store = usePracticeStore()
    await store.load(77)
    const reply = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValue(reply.promise)

    const first = store.answer({ order: ['fW2'] })
    const second = store.answer({ order: ['fW2'] })
    reply.resolve(answerResponse())
    await Promise.all([first, second])

    expect(api.practice.answer).toHaveBeenCalledTimes(1)
    expect(store.status).toBe('verdict')
  })

  it('a tap on another option while the answer is on its way is ignored', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(session({ mode: 'collocation' }))
    const store = usePracticeStore()
    await store.load(77)
    const reply = pending<PracticeAnswerResponse>()
    vi.mocked(api.practice.answer).mockReturnValue(reply.promise)

    const first = store.answer({ option_id: 'oA1' })
    await store.answer({ option_id: 'oB2' })
    reply.resolve(answerResponse())
    await first

    expect(api.practice.answer).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.practice.answer).mock.calls[0]![1].answer).toEqual({ option_id: 'oA1' })
  })

  it('two taps on "Next" show one element', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(session())
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse())
    const store = usePracticeStore()
    await store.load(77)
    await store.answer({ order: ['fW2'] })
    const seq = store.elementSeq

    store.continueAfterVerdict()
    store.continueAfterVerdict()

    expect(store.elementSeq).toBe(seq + 1)
    expect(store.status).toBe('ready')
  })

  it('a swap and an answer do not run together', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(session({ mode: 'dictation' }))
    const store = usePracticeStore()
    await store.load(77)
    const swap = pending<PracticeSession>()
    vi.mocked(api.practice.replace).mockReturnValue(swap.promise)

    const replacing = store.replace()
    await store.answer({ text: 'see you' })
    swap.resolve(session({ mode: 'dictation', current: dictationElement({ id: 'eDict00002' }) }))
    await replacing

    expect(api.practice.answer).not.toHaveBeenCalled()
  })

  it('two taps on a card open one session', async () => {
    const reply = pending<PracticeSession>()
    vi.mocked(api.practice.open).mockReturnValue(reply.promise)
    const store = usePracticeStore()
    const request = { mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'hub' } as const

    const first = store.open(request)
    const second = store.open({ ...request, mode: 'dictation' })
    reply.resolve(session({ id: 5 }))

    expect(await first).toBe(5)
    expect(await second).toBeNull()
    expect(api.practice.open).toHaveBeenCalledTimes(1)
  })
})

describe('practice: the hub belongs to the request that asked for it', () => {
  const hubOf = (slug: string): PracticeHubResponse => hub({ course: { slug, title: slug } })

  it('ignores a late hub of an earlier course', async () => {
    const old = pending<PracticeHubResponse>()
    vi.mocked(api.practice.hub).mockReturnValueOnce(old.promise).mockResolvedValueOnce(hubOf('b'))
    const store = usePracticeStore()

    const first = store.loadHub('a')
    await store.loadHub('b')
    old.resolve(hubOf('a'))
    await first

    expect(store.hub?.course.slug).toBe('b')
    expect(store.hubScope).toEqual({ course: 'b', lessonId: null })
  })

  it('ignores a late failure of an earlier request', async () => {
    const old = pending<PracticeHubResponse>()
    vi.mocked(api.practice.hub).mockReturnValueOnce(old.promise).mockResolvedValueOnce(hubOf('a'))
    const store = usePracticeStore()

    const first = store.loadHub('a')
    await store.loadHub('a')
    old.reject(offline())
    await first

    expect(store.hubStatus).toBe('ready')
    expect(store.hubError).toBe('')
  })

  it('ignores a late "unavailable" of an earlier request', async () => {
    const old = pending<PracticeHubResponse>()
    vi.mocked(api.practice.hub).mockReturnValueOnce(old.promise).mockResolvedValueOnce(hubOf('a'))
    const store = usePracticeStore()

    const first = store.loadHub('a')
    await store.loadHub('a')
    old.reject(new ApiClientError('forbidden', 403, 'practice_unavailable', 'x'))
    await first

    expect(store.hubStatus).toBe('ready')
  })
})

describe('practice: what the screen is keyed by', () => {
  it('a new element is a new component, the same element read again is not', async () => {
    vi.mocked(api.practice.get).mockResolvedValue(session())
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: session({ current: sentenceElement({ id: 'eSent00002' }) }) }))
    const store = usePracticeStore()
    await store.load(77)
    expect(store.elementSeq).toBe(1)

    await store.answer({ order: ['fW2'] })
    store.continueAfterVerdict()
    expect(store.elementSeq).toBe(2)

    store.applySession(session({ current: sentenceElement({ id: 'eSent00002' }) }))
    expect(store.elementSeq).toBe(2)
  })
})
