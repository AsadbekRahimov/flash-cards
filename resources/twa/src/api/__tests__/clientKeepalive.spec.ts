import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApiClient } from '../client'

describe('api client — postKeepalive (the timing marks of an open, docs/04 A.21)', () => {
  const fetchMock = vi.fn()
  let token = 'jwt'
  const onUnauthorized = vi.fn(async () => 'fresh')
  const client = () => createApiClient({ getToken: () => token, onUnauthorized })

  beforeEach(() => {
    token = 'jwt'
    fetchMock.mockReset()
    onUnauthorized.mockClear()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('posts JSON with the token and keepalive, without cookies', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await client().postKeepalive('/client-timing', { open_id: 'x', cold: true })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/twa/client-timing')
    expect(init).toMatchObject({ method: 'POST', keepalive: true, credentials: 'omit', body: JSON.stringify({ open_id: 'x', cold: true }) })
    expect(init.headers).toMatchObject({ Authorization: 'Bearer jwt', 'Content-Type': 'application/json' })
  })

  it('sends nothing without a token', async () => {
    token = ''

    await expect(client().postKeepalive('/client-timing', {})).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('neither refreshes the token nor retries on an error', async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ error: { code: 'invalid_token', message: 'x' } }), { status: 401 }))
    await expect(client().postKeepalive('/client-timing', {})).rejects.toMatchObject({ kind: 'unauthorized', code: 'invalid_token' })

    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    await expect(client().postKeepalive('/client-timing', {})).rejects.toMatchObject({ kind: 'network' })

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(onUnauthorized).not.toHaveBeenCalled()
  })
})
