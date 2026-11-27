import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ofetch } from 'ofetch'
import { createApiClient, onNetworkSignal, type NetworkSignal } from '../client'

vi.mock('ofetch', () => ({ ofetch: vi.fn() }))

const client = () => createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' })
const fetchFailed = () => Object.assign(new TypeError('Failed to fetch'), { response: undefined })
const status = (code: number) => Object.assign(new Error(String(code)), { response: { status: code }, data: {} })

describe('api client — what a request says about the connection (F5, docs/40 §2.6)', () => {
  let signals: NetworkSignal[]
  let stop: () => void

  beforeEach(() => {
    vi.mocked(ofetch).mockReset()
    signals = []
    stop = onNetworkSignal((s) => signals.push(s))
  })
  afterEach(() => stop())

  it('a request the server answered has reached it, an error status included', async () => {
    vi.mocked(ofetch).mockResolvedValueOnce({} as never)
    await client().get('/today')
    vi.mocked(ofetch).mockRejectedValueOnce(status(422))
    await expect(client().post('/me/course', { slug: 'x' })).rejects.toMatchObject({ kind: 'validation' })

    expect(signals).toEqual([
      { outcome: 'reached', method: 'GET' },
      { outcome: 'reached', method: 'POST' },
    ])
  })

  it('a fetch that threw and a request that ran out of time are failures, with their method', async () => {
    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    await expect(client().get('/today')).rejects.toMatchObject({ kind: 'network' })
    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    await expect(client().put('/words/1/note', { note: null })).rejects.toMatchObject({ kind: 'network' })

    expect(signals).toEqual([
      { outcome: 'failed', method: 'GET' },
      { outcome: 'failed', method: 'PUT' },
    ])
  })

  it('a background read lost to the network says nothing of a failure; one that reached the server says so', async () => {
    vi.mocked(ofetch).mockRejectedValueOnce(fetchFailed())
    await expect(client().get('/cups', undefined, { background: true })).rejects.toMatchObject({ kind: 'network' })
    vi.mocked(ofetch).mockRejectedValueOnce(status(503))
    await expect(client().get('/achievements', undefined, { background: true })).rejects.toMatchObject({ kind: 'server' })
    vi.mocked(ofetch).mockResolvedValueOnce({} as never)
    await client().get('/referrals', undefined, { background: true })

    expect(signals).toEqual([
      { outcome: 'reached', method: 'GET' },
      { outcome: 'reached', method: 'GET' },
    ])
  })

  it('a background read that ran out of its time is no failure of the screen either; the main load is', async () => {
    vi.useFakeTimers()
    try {
      vi.mocked(ofetch).mockImplementation((_path, opts) => new Promise((_resolve, reject) => {
        ;(opts as { signal?: AbortSignal }).signal?.addEventListener('abort', () => reject(new Error('aborted')))
      }) as never)
      const background = expect(client().get('/practice/battles/1/poll', undefined, { timeoutMs: 100, background: true })).rejects.toMatchObject({ code: 'timeout' })
      const main = expect(client().get('/today', undefined, { timeoutMs: 100 })).rejects.toMatchObject({ code: 'timeout' })
      await vi.advanceTimersByTimeAsync(100)
      await background
      await main
    } finally {
      vi.useRealTimers()
    }

    expect(signals).toEqual([{ outcome: 'failed', method: 'GET' }])
  })

  it('a listener that stopped hears nothing more', async () => {
    stop()
    vi.mocked(ofetch).mockResolvedValueOnce({} as never)
    await client().get('/today')

    expect(signals).toEqual([])
  })
})
