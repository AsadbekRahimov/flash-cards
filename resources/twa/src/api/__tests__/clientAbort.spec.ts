import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ofetch } from 'ofetch'
import { ApiClientError, createApiClient, onNetworkSignal, REQUEST_ABORTED, type NetworkSignal } from '../client'

vi.mock('ofetch', () => ({ ofetch: vi.fn() }))

/*
 * A request the caller drops (`RequestOptions.signal`: a newer search of "My dictionary" replaced it, docs/40 §2.3): it ends as a `network`
 * error with the code `aborted`, which says nothing about the connection - the screen "Нет соединения" is not told, no token is refreshed -
 * and a request dropped before it started is not sent at all. Without a signal nothing changes.
 */

type Sent = { signal?: AbortSignal }

const sent = (call = 0): Sent => vi.mocked(ofetch).mock.calls[call]![1] as unknown as Sent

function hang(): void {
  vi.mocked(ofetch).mockImplementation(((_path: string, options: Sent) =>
    new Promise((_resolve, reject) => {
      options.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
    })) as never)
}

let signals: NetworkSignal[] = []
let stop: () => void = () => {}

beforeEach(() => {
  vi.mocked(ofetch).mockReset()
  signals = []
  stop = onNetworkSignal((s) => signals.push(s))
})
afterEach(() => stop())

describe('api client — a request the caller drops', () => {
  it('ends as `aborted`, without a word to the screen "Нет соединения" and without a refresh of the token', async () => {
    hang()
    const onUnauthorized = vi.fn(async () => 'fresh')
    const controller = new AbortController()
    const result = createApiClient({ getToken: () => 'jwt', onUnauthorized }).get('/vocabulary', { q: 'te' }, { signal: controller.signal }).catch((err: unknown) => err)

    controller.abort()
    const err = await result

    expect(err).toBeInstanceOf(ApiClientError)
    expect(err).toMatchObject({ kind: 'network', code: REQUEST_ABORTED })
    expect(sent().signal?.aborted).toBe(true)
    expect(signals).toEqual([])
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('does not send a request dropped before it started', async () => {
    const controller = new AbortController()
    controller.abort()

    const err = await createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' }).get('/vocabulary', {}, { signal: controller.signal }).catch((e: unknown) => e)

    expect(err).toMatchObject({ kind: 'network', code: REQUEST_ABORTED })
    expect(ofetch).not.toHaveBeenCalled()
    expect(signals).toEqual([])
  })

  it('answers as usual when the signal never fires, and a real lost connection is still reported', async () => {
    const client = createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' })
    vi.mocked(ofetch).mockResolvedValueOnce({ items: [] } as never)
    await expect(client.get('/vocabulary', {}, { signal: new AbortController().signal })).resolves.toEqual({ items: [] })
    expect(signals).toEqual([{ outcome: 'reached', method: 'GET' }])

    vi.mocked(ofetch).mockRejectedValueOnce(Object.assign(new Error('fetch failed'), { response: undefined }) as never)
    const err = await client.get('/vocabulary', {}, { signal: new AbortController().signal }).catch((e: unknown) => e)
    expect(err).toMatchObject({ kind: 'network' })
    expect((err as ApiClientError).code).not.toBe(REQUEST_ABORTED)
    expect(signals.at(-1)).toEqual({ outcome: 'failed', method: 'GET' })
  })

  it('a request without a signal is sent without one (nothing changes for the rest of the app)', async () => {
    vi.mocked(ofetch).mockResolvedValueOnce({} as never)
    await createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' }).get('/today')
    expect(sent().signal).toBeUndefined()
  })
})
