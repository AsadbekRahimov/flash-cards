import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { usePracticeStore } from '../practice'
import { answerResponse, finishedSession, pairsElement, summary, timedSession } from '@/api/__tests__/practiceFixtures'
import type { PracticeSession } from '@/api/types'

vi.mock('@/api', () => ({
  api: { practice: { hub: vi.fn(), open: vi.fn(), get: vi.fn(), start: vi.fn(), answer: vi.fn(), replace: vi.fn() } },
}))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const NOW = Date.parse('2026-10-30T10:00:05.321Z')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  vi.useFakeTimers()
  // The device clock is an hour off: only `deadline_at - server_now` of the reply counts.
  vi.setSystemTime(NOW + 3_600_000)
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

async function loaded(s: PracticeSession) {
  vi.mocked(api.practice.get).mockResolvedValue(s)
  const store = usePracticeStore()
  await store.load(s.id)
  return store
}

const match = { left_id: 'lM1', right_id: 'rU2' }
const closed = () => finishedSession({ mode: 'pairs_timed', timed: { seconds: 60, deadline_at: null, started: true, score: 9, penalty_ms: 2000 }, summary: summary({ score: 9, best_score: 9, is_record: true, finish_reason: 'time_over' }) })

describe('practice store — timed pairs', () => {
  it('counts the time from deadline_at - server_now of the reply, not from the device clock', async () => {
    const store = await loaded(timedSession(42_000))

    expect(store.timedDeadline).toBe(Date.now() + 42_000)
    expect(store.timeUp).toBe(false)
    expect(store.status).toBe('ready')
  })

  it('has no deadline before the start, and takes no answer', async () => {
    const store = await loaded(timedSession(null))

    expect(store.timedDeadline).toBeNull()
    await store.answer(match)
    expect(api.practice.answer).not.toHaveBeenCalled()
  })

  it('start: POST /start once at a time, then the clock runs from the new deadline', async () => {
    const store = await loaded(timedSession(null))
    vi.mocked(api.practice.start).mockResolvedValue(timedSession(60_000))

    await Promise.all([store.startTimed(), store.startTimed()])

    expect(api.practice.start).toHaveBeenCalledTimes(1)
    expect(api.practice.start).toHaveBeenCalledWith(77)
    expect(store.timedDeadline).toBe(Date.now() + 60_000)
    expect(store.session?.timed?.started).toBe(true)
  })

  it('a start that got no reply is a message and can be repeated', async () => {
    const store = await loaded(timedSession(null))
    vi.mocked(api.practice.start).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValueOnce(timedSession(60_000))

    await store.startTimed()
    expect(store.actionError).not.toBe('')
    expect(store.timedStarting).toBe(false)
    expect(store.status).toBe('ready')

    await store.startTimed()
    expect(store.session?.timed?.started).toBe(true)
  })

  it('a right pair: no verdict screen, the score goes up', async () => {
    const store = await loaded(timedSession(40_000))
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({ verdict: 'correct', pair: match, round_done: false, penalty_ms: 0, session: timedSession(40_000, 1, { current: pairsElement({ matched: [['lM1', 'rU2']] }) }) }),
    )

    await store.answer(match)

    expect(store.status).toBe('ready')
    expect(store.outcome).toBeNull()
    expect(store.session?.timed?.score).toBe(1)
    expect(store.penalty.seq).toBe(0)
  })

  it('a wrong pair moves the deadline closer and flashes the penalty', async () => {
    const store = await loaded(timedSession(40_000))
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({ verdict: 'wrong', pair: match, round_done: false, penalty_ms: 2000, session: timedSession(38_000, 0, { current: pairsElement({ mismatches: 1 }) }) }),
    )

    await store.answer(match)

    expect(store.timedDeadline).toBe(Date.now() + 38_000)
    expect(store.penalty).toEqual({ seq: 1, ms: 2000 })
    expect(store.status).toBe('ready')
  })

  it('a finished round shows the next one at once, with no "Next" in between', async () => {
    const store = await loaded(timedSession(40_000))
    const next = pairsElement({ id: 'ePair0002', round: 2 })
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({ verdict: 'correct', pair: match, round_done: true, penalty_ms: 0, session: timedSession(40_000, 4, { current: next, progress: { done: 1, total: 10 } }) }),
    )

    await store.answer(match)

    expect(store.status).toBe('ready')
    expect(store.current?.id).toBe('ePair0002')
  })

  it('zero: locks the board, reads the session (no answer is sent) and shows the summary the server has closed', async () => {
    const store = await loaded(timedSession(1_000))
    vi.mocked(api.practice.get).mockResolvedValue(closed())

    const expiring = store.expire()
    expect(store.timeUp).toBe(true)
    await store.answer(match)
    expect(api.practice.answer).not.toHaveBeenCalled()
    await expiring

    expect(store.status).toBe('finished')
    expect(store.session?.summary?.score).toBe(9)
  })

  it('zero: while the server is still in its grace, the session is read again until it is closed', async () => {
    const store = await loaded(timedSession(1_000))
    vi.mocked(api.practice.get).mockClear()
    vi.mocked(api.practice.get).mockResolvedValueOnce(timedSession(-200)).mockResolvedValueOnce(closed())

    const expiring = store.expire()
    await vi.advanceTimersByTimeAsync(2_000)
    await expiring

    expect(api.practice.get).toHaveBeenCalledTimes(2)
    expect(store.status).toBe('finished')
  })

  it('409 practice_time_over: the board is locked and the session is read -> the summary', async () => {
    const store = await loaded(timedSession(20_000))
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'practice_time_over', { score: 9 }))
    vi.mocked(api.practice.get).mockResolvedValue(closed())

    await store.answer(match)

    expect(api.practice.get).toHaveBeenCalledTimes(2)
    expect(store.status).toBe('finished')
    expect(store.timeUp).toBe(false)
  })

  it('409 practice_not_started: the session is read, nothing is told as a failure', async () => {
    const store = await loaded(timedSession(20_000))
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'practice_not_started'))
    vi.mocked(api.practice.get).mockResolvedValue(timedSession(null))

    await store.answer(match)

    expect(store.session?.timed?.started).toBe(false)
    expect(store.actionError).toBe('')
  })

  it('an answer that comes back after the summary is on screen does not bring the game back', async () => {
    const store = await loaded(timedSession(1_000))
    let reply!: (value: ReturnType<typeof answerResponse>) => void
    vi.mocked(api.practice.answer).mockReturnValue(new Promise((resolve) => (reply = resolve)))
    const answering = store.answer(match)
    vi.mocked(api.practice.get).mockResolvedValue(closed())
    await store.expire()
    expect(store.status).toBe('finished')

    reply(answerResponse({ pair: match, round_done: false, penalty_ms: 0, session: timedSession(500, 5) }))
    await answering

    expect(store.status).toBe('finished')
    expect(store.session?.status).toBe('finished')
  })

  it('resync reads the session when the app comes back; a lost network changes nothing on screen', async () => {
    const store = await loaded(timedSession(30_000))
    vi.mocked(api.practice.get).mockRejectedValueOnce(apiError('network', 0, 'network'))
    await store.resync()
    expect(store.status).toBe('ready')

    vi.mocked(api.practice.get).mockResolvedValueOnce(closed())
    await store.resync()
    expect(store.status).toBe('finished')
  })

  it('"Again" opens a new session of the same mode', async () => {
    const store = await loaded(timedSession(1_000))
    vi.mocked(api.practice.get).mockResolvedValue(closed())
    await store.expire()
    vi.mocked(api.practice.open).mockResolvedValue(timedSession(null, 0, { id: 78 }))

    expect(await store.again()).toBe(78)
    expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ mode: 'pairs_timed', source: 'hub' })
  })
})
