import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ofetch } from 'ofetch'
import { ApiClientError, createApiClient } from '../client'
import { setLocale } from '@/i18n'

vi.mock('ofetch', () => ({ ofetch: vi.fn() }))

/** What ofetch throws for an error status: a FetchError with the parsed body in `data`. */
const fetchError = (status: number, data: unknown) => Object.assign(new Error(`HTTP ${status}`), { response: { status }, data })

describe('api client — error details (docs/16 §7.6)', () => {
  const client = () => createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' })

  beforeEach(() => {
    vi.mocked(ofetch).mockReset()
  })

  it('keeps the `details` object of the envelope on the error', async () => {
    const details = { session_id: 77, mode: 'pairs', progress: { done: 1, total: 3 } }
    vi.mocked(ofetch).mockRejectedValue(fetchError(409, { error: { code: 'practice_session_active', message: 'x', details } }))

    const err = await client().post('/practice/sessions', {}).catch((e: unknown) => e)

    expect(err).toBeInstanceOf(ApiClientError)
    expect(err).toMatchObject({ kind: 'conflict', status: 409, code: 'practice_session_active', details })
  })

  it('has null details when the server sent none, or something that is not an object', async () => {
    vi.mocked(ofetch).mockRejectedValueOnce(fetchError(409, { error: { code: 'battle_closed', message: 'x' } }))
    vi.mocked(ofetch).mockRejectedValueOnce(fetchError(409, { error: { code: 'battle_closed', message: 'x', details: ['a'] } }))
    vi.mocked(ofetch).mockRejectedValueOnce(fetchError(409, { error: { code: 'battle_closed', message: 'x', details: 'a' } }))

    for (let i = 0; i < 3; i++) {
      await expect(client().get('/x')).rejects.toMatchObject({ code: 'battle_closed', details: null })
    }
  })

  it('an error built without details has null (the older call sites)', () => {
    expect(new ApiClientError('server', 500, 'server_error', 'x').details).toBeNull()
  })
})

describe('api client — getBlob (the clip of a dictation)', () => {
  const fetchMock = vi.fn()
  const clip = () => new Response(new Blob(['mp3-bytes'], { type: 'audio/mpeg' }), { status: 200 })
  const problem = (status: number, body: unknown) =>
    new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })

  let token = 'old'
  const client = (onUnauthorized: () => Promise<string> = async () => '') =>
    createApiClient({ getToken: () => token, onUnauthorized })

  const calledUrl = (n = 0): string => String(fetchMock.mock.calls[n]?.[0])
  const calledHeaders = (n = 0): Record<string, string> => (fetchMock.mock.calls[n]?.[1] as { headers: Record<string, string> }).headers

  beforeEach(() => {
    token = 'old'
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    setLocale('ru')
  })

  it('fetches with the token and returns the body as a Blob', async () => {
    fetchMock.mockResolvedValue(clip())

    const blob = await client().getBlob('/practice/sessions/7/audio/eD4abcdefg')

    expect(blob).toBeInstanceOf(Blob)
    expect(await blob.text()).toBe('mp3-bytes')
    expect(calledUrl()).toBe('/api/twa/practice/sessions/7/audio/eD4abcdefg')
    expect(calledHeaders().Authorization).toBe('Bearer old')
    expect(calledHeaders()['Accept-Language']).toBe('ru')
  })

  it('takes the audio_url of an element as it comes from the server (with the /api/twa prefix)', async () => {
    fetchMock.mockResolvedValue(clip())

    await client().getBlob('/api/twa/practice/battles/15/audio/eD4abcdefg')

    expect(calledUrl()).toBe('/api/twa/practice/battles/15/audio/eD4abcdefg')
  })

  it('sends the language of the interface', async () => {
    setLocale('uz')
    fetchMock.mockResolvedValue(clip())

    await client().getBlob('/practice/sessions/7/audio/eD4abcdefg')

    expect(calledHeaders()['Accept-Language']).toBe('uz')
  })

  it.each([
    ['an absolute URL', 'https://evil.example/steal.mp3'],
    ['a protocol-relative URL', '//evil.example/steal.mp3'],
    ['a path that climbs out of the prefix', '/practice/../../admin'],
    ['a path with a backslash', '/practice\\evil'],
    ['a relative path', 'practice/sessions/7/audio/e'],
    ['a path with a control character', '/practice/sessions/7/audio/e\n'],
  ])('never sends the token to %s', async (_name, path) => {
    await expect(client().getBlob(path)).rejects.toMatchObject({ code: 'invalid_path' })

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('maps the JSON envelope of a refusal like any other call: code, kind, details', async () => {
    fetchMock.mockResolvedValue(problem(503, { error: { code: 'practice_audio_unavailable', message: 'gone', details: { x: 1 } } }))

    await expect(client().getBlob('/practice/sessions/7/audio/e')).rejects.toMatchObject({
      kind: 'server',
      status: 503,
      code: 'practice_audio_unavailable',
      details: { x: 1 },
    })
  })

  it('maps 404 and 429 by status', async () => {
    fetchMock.mockResolvedValueOnce(problem(404, { error: { code: 'practice_session_not_found', message: 'x' } }))
    fetchMock.mockResolvedValueOnce(problem(429, { error: { code: 'rate_limited', message: 'x' } }))

    await expect(client().getBlob('/practice/sessions/7/audio/e')).rejects.toMatchObject({ kind: 'not_found', code: 'practice_session_not_found' })
    await expect(client().getBlob('/practice/sessions/7/audio/e')).rejects.toMatchObject({ kind: 'rate_limited' })
  })

  it('tells the kind from the status when the body is not JSON (a proxy page)', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 }))

    await expect(client().getBlob('/practice/sessions/7/audio/e')).rejects.toMatchObject({ kind: 'server', status: 502 })
  })

  it('is a network error when fetch itself fails', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    const err = await client().getBlob('/practice/sessions/7/audio/e').catch((e: unknown) => e)

    expect(err).toBeInstanceOf(ApiClientError)
    expect(err).toMatchObject({ kind: 'network', status: 0 })
  })

  it('refreshes the token once on a 401 and repeats the request with the new one', async () => {
    fetchMock.mockImplementation(async (_url: string, init: { headers: Record<string, string> }) =>
      init.headers.Authorization === 'Bearer new' ? clip() : problem(401, { error: { code: 'invalid_token', message: 'x' } }),
    )
    const onUnauthorized = vi.fn(async () => {
      token = 'new'
      return token
    })

    const blob = await client(onUnauthorized).getBlob('/practice/sessions/7/audio/e')

    expect(await blob.text()).toBe('mp3-bytes')
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('does not loop on a 401 that survives the refresh', async () => {
    fetchMock.mockImplementation(async () => problem(401, { error: { code: 'invalid_token', message: 'x' } }))
    const onUnauthorized = vi.fn(async () => {
      token = 'new'
      return token
    })

    await expect(client(onUnauthorized).getBlob('/practice/sessions/7/audio/e')).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})
