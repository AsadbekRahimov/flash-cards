import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ofetch } from 'ofetch'
import { ApiClientError, createApiClient } from '../client'

vi.mock('ofetch', () => ({ ofetch: vi.fn() }))

/*
 * The limit of a JSON request (docs/16 §11.5, the review of the battle, Ж2): a request on a dead connection hangs, the poll of a
 * battle does not start another beside one that is on its way, and the server counts the silence against the player. A battle names
 * a limit for its requests; the solo practice and the rest of the app name none, so for them nothing changes.
 */

type Sent = { signal?: AbortSignal; headers: Record<string, string> }

const client = (getToken: () => string = () => 'jwt', onUnauthorized: () => Promise<string> = async () => '') => createApiClient({ getToken, onUnauthorized })

/** A connection that never answers; the request ends only when its signal says so (the way `fetch` ends). */
function hang(): void {
  vi.mocked(ofetch).mockImplementation(((_path: string, options: Sent) =>
    new Promise((_resolve, reject) => {
      options.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
    })) as never)
}

const sent = (call = 0): Sent => vi.mocked(ofetch).mock.calls[call]![1] as unknown as Sent

beforeEach(() => {
  vi.mocked(ofetch).mockReset()
  vi.useFakeTimers()
})
afterEach(() => vi.useRealTimers())

describe('api client — the limit of a JSON request', () => {
  it('gives up a request that hangs at the limit, not before, as a lost connection (network, code timeout)', async () => {
    hang()
    const result = client().get('/practice/battles/15/poll', undefined, { timeoutMs: 8000 }).catch((err: unknown) => err)

    await vi.advanceTimersByTimeAsync(7999)
    expect(vi.getTimerCount()).toBe(1) // still waiting
    await vi.advanceTimersByTimeAsync(1)
    const err = await result

    expect(err).toBeInstanceOf(ApiClientError)
    expect(err).toMatchObject({ kind: 'network', status: 0, code: 'timeout' })
    expect(sent().signal?.aborted).toBe(true)
  })

  it('also stops an action with a body at its own limit', async () => {
    hang()
    const result = client().post('/practice/battles/15/answer', { round: 2 }, { timeoutMs: 10_000 }).catch((err: unknown) => err)

    await vi.advanceTimersByTimeAsync(10_000)

    expect(await result).toMatchObject({ kind: 'network', code: 'timeout' })
    expect(vi.mocked(ofetch).mock.calls[0]?.[1]).toMatchObject({ method: 'POST', body: { round: 2 } })
  })

  it('lets go of the timer once the reply is in: nothing is left to fire on a finished request', async () => {
    vi.mocked(ofetch).mockResolvedValue({ ok: true } as never)

    await expect(client().get('/practice/battles/15/poll', undefined, { timeoutMs: 8000 })).resolves.toEqual({ ok: true })

    expect(vi.getTimerCount()).toBe(0)
    await vi.advanceTimersByTimeAsync(20_000)
    expect(sent().signal?.aborted).toBe(false)
  })

  it('does not hide a refusal of the server behind the limit, and lets go of the timer then too', async () => {
    vi.mocked(ofetch).mockRejectedValue(Object.assign(new Error('409'), { response: { status: 409 }, data: { error: { code: 'round_closed', message: 'x', details: { round: 2, phase: 'reveal' } } } }))

    const err = await client().post('/practice/battles/15/answer', {}, { timeoutMs: 10_000 }).catch((e: unknown) => e)

    expect(err).toMatchObject({ kind: 'conflict', code: 'round_closed', details: { round: 2, phase: 'reveal' } })
    expect(vi.getTimerCount()).toBe(0)
  })

  it('counts a body that hangs under the same limit: the headers were in, the reading is cut off', async () => {
    // `ofetch` lets go of its own timer when the headers are in; the limit of the client does not.
    vi.mocked(ofetch).mockImplementation(((_path: string, options: Sent) =>
      new Promise((_resolve, reject) => {
        options.signal?.addEventListener('abort', () => reject(new DOMException('Reading the body was aborted.', 'AbortError')))
      })) as never)
    const result = client().get('/practice/battles/15', undefined, { timeoutMs: 8000 }).catch((err: unknown) => err)

    await vi.advanceTimersByTimeAsync(8000)

    expect(await result).toMatchObject({ kind: 'network', code: 'timeout' })
  })

  it('names no limit by itself: a request without the option has no signal and no timer (solo practice and the rest are untouched)', async () => {
    vi.mocked(ofetch).mockResolvedValue({ ok: true } as never)

    await client().get('/practice/hub', { course: 'english-a1' })
    await client().post('/practice/sessions', { mode: 'pairs' })

    expect(vi.getTimerCount()).toBe(0)
    expect('signal' in sent(0)).toBe(false)
    expect('signal' in sent(1)).toBe(false)
  })

  it('a refresh of the token after a 401 is not counted against the limit, and the repeated request has a limit of its own', async () => {
    let token = 'old'
    const refresh = vi.fn(async () => {
      await new Promise((resolve) => setTimeout(resolve, 5000))
      token = 'new'
      return token
    })
    vi.mocked(ofetch).mockImplementation(((_path: string, options: Sent) => {
      if (options.headers.Authorization === 'Bearer old') {
        return new Promise((_resolve, reject) => setTimeout(() => reject(Object.assign(new Error('401'), { response: { status: 401 }, data: {} })), 6000))
      }
      return new Promise((_resolve, reject) => {
        options.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
      })
    }) as never)

    const result = client(() => token, refresh).get('/practice/battles/15/poll', undefined, { timeoutMs: 8000 }).catch((err: unknown) => err)
    await vi.advanceTimersByTimeAsync(6000) // the first request fails with a 401 at 6 s
    await vi.advanceTimersByTimeAsync(5000) // the refresh takes 5 s: 11 s in all, more than the limit
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(vi.mocked(ofetch)).toHaveBeenCalledTimes(2) // the second request is on its way: the refresh did not end it
    expect(sent(0).signal?.aborted).toBe(false)

    await vi.advanceTimersByTimeAsync(7999)
    expect(sent(1).signal?.aborted).toBe(false)
    await vi.advanceTimersByTimeAsync(1)

    expect(await result).toMatchObject({ kind: 'network', code: 'timeout' })
  })
})
