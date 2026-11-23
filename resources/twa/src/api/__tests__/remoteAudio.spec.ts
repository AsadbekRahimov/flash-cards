import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiClientError, createApiClient } from '../client'
import { PROBE_GRACE_MS, probeRemoteAudio, REMOTE_AUDIO_FAILED, RemoteAudio, RemoteAudioError, remoteAudioUrl } from '../remoteAudio'

/*
 * docs/26 §9.9: a word on R2 is answered `200 {"url"}` by the audio endpoints. The address is loaded by an <audio> element only -
 * never by fetch and never with the token - so the bucket needs no CORS and the JWT of the learner stays on this domain.
 */

const R2 = 'https://audio.example.uz/audio/abc.mp3'

/** An <audio> the test drives: the events it fires, what it was given. */
class FakeMedia {
  static instances: FakeMedia[] = []
  src = ''
  preload = ''
  crossOrigin: string | null = null
  error: { code: number } | null = null
  load = vi.fn()
  removeAttribute = vi.fn((name: string) => {
    if (name === 'src') this.src = ''
  })
  listeners = new Map<string, Set<() => void>>()
  constructor() {
    FakeMedia.instances.push(this)
  }
  addEventListener(name: string, handler: () => void): void {
    if (!this.listeners.has(name)) this.listeners.set(name, new Set())
    this.listeners.get(name)!.add(handler)
  }
  removeEventListener(name: string, handler: () => void): void {
    this.listeners.get(name)?.delete(handler)
  }
  emit(name: string): void {
    for (const handler of [...(this.listeners.get(name) ?? [])]) handler()
  }
  listening(): number {
    let n = 0
    for (const set of this.listeners.values()) n += set.size
    return n
  }
}

const last = (): FakeMedia => FakeMedia.instances[FakeMedia.instances.length - 1]!

beforeEach(() => {
  FakeMedia.instances = []
  vi.stubGlobal('Audio', FakeMedia)
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('remoteAudioUrl — what the client accepts as the address of a clip', () => {
  it('takes an absolute http(s) address with a host', () => {
    expect(remoteAudioUrl({ url: R2 })).toBe(R2)
    expect(remoteAudioUrl({ url: 'http://localhost:9000/audio/a.mp3' })).toBe('http://localhost:9000/audio/a.mp3')
  })

  it.each([
    ['no object', null],
    ['a list', [R2]],
    ['no url', { href: R2 }],
    ['not a string', { url: 42 }],
    ['empty', { url: '' }],
    ['relative', { url: '/storage/audio/a.mp3' }],
    ['javascript:', { url: 'javascript:alert(1)' }],
    ['data:', { url: 'data:audio/mpeg;base64,AAAA' }],
    ['blob:', { url: 'blob:https://x/1' }],
    ['credentials in it', { url: 'https://user:pass@audio.example.uz/a.mp3' }],
  ])('refuses %s', (_name, data) => {
    expect(remoteAudioUrl(data)).toBeNull()
  })
})

describe('probeRemoteAudio — loading a word on R2 into an <audio>', () => {
  it('loads it with an <audio>, preload auto, WITHOUT crossorigin (no CORS request), and is done when it can play', async () => {
    const done = probeRemoteAudio(R2)
    const media = last()
    expect([media.src, media.preload, media.crossOrigin]).toEqual([R2, 'auto', null])
    expect(media.load).toHaveBeenCalled()

    media.emit('canplaythrough')

    await expect(done).resolves.toBeUndefined()
    expect(media.listening()).toBe(0)
  })

  it('is done on loadeddata too (a WebView that never says canplaythrough)', async () => {
    const done = probeRemoteAudio(R2)
    last().emit('loadeddata')

    await expect(done).resolves.toBeUndefined()
  })

  it('fails with the media error of the element: a 404 of the bucket, a lost connection', async () => {
    const done = probeRemoteAudio(R2)
    const media = last()
    media.error = { code: 4 }
    media.emit('error')

    const err = await done.catch((e: unknown) => e)
    expect(err).toBeInstanceOf(RemoteAudioError)
    expect(err).toMatchObject({ mediaCode: 4 })
    expect(media.listening()).toBe(0)
  })

  it('takes the clip as ready after the grace when the WebView neither loads nor fails before a tap', async () => {
    vi.useFakeTimers()
    let settled = false
    const done = probeRemoteAudio(R2).then(() => (settled = true))

    await vi.advanceTimersByTimeAsync(PROBE_GRACE_MS - 1)
    expect(settled).toBe(false)
    await vi.advanceTimersByTimeAsync(1)

    await done
    expect(settled).toBe(true)
    // A late error after the grace changes nothing (the tap will tell).
    last().emit('error')
  })

  it('stops the load when the caller drops it, and says AbortError', async () => {
    const leaving = new AbortController()
    const done = probeRemoteAudio(R2, leaving.signal)
    const media = last()

    leaving.abort()

    await expect(done).rejects.toMatchObject({ name: 'AbortError' })
    expect(media.removeAttribute).toHaveBeenCalledWith('src')
    expect(media.src).toBe('')
  })

  it('does not even start for a load dropped before it began', async () => {
    const leaving = new AbortController()
    leaving.abort()

    await expect(probeRemoteAudio(R2, leaving.signal)).rejects.toMatchObject({ name: 'AbortError' })
    expect(FakeMedia.instances).toEqual([])
  })
})

describe('api client — a clip answered with the address of R2', () => {
  const fetchMock = vi.fn()
  const located = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { 'content-type': 'application/json' } })
  const client = () => createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' })
  const PATH = '/api/twa/practice/sessions/7/audio/eD4abcdefg'

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  it('gives a RemoteAudio once the <audio> has loaded it; fetch went to the API only, the token never to R2', async () => {
    fetchMock.mockResolvedValue(located({ url: R2 }))

    const result = client().getBlob(PATH)
    await vi.waitFor(() => expect(FakeMedia.instances).toHaveLength(1))
    last().emit('canplaythrough')

    expect(await result).toEqual(new RemoteAudio(R2))
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe(PATH)
    expect(last().src).toBe(R2)
  })

  it('a word the device cannot load from R2 is a network error remote_audio_failed (the battle reports it, the server looks)', async () => {
    fetchMock.mockResolvedValue(located({ url: R2 }))

    const result = client().getBlob(PATH).catch((e: unknown) => e)
    await vi.waitFor(() => expect(FakeMedia.instances).toHaveLength(1))
    last().emit('error')

    const err = await result
    expect(err).toBeInstanceOf(ApiClientError)
    expect(err).toMatchObject({ kind: 'network', status: 0, code: REMOTE_AUDIO_FAILED })
  })

  it.each([
    ['an address that is not http(s)', { url: 'javascript:alert(1)' }],
    ['no address', { ok: true }],
    ['not JSON at all', '<html>'],
  ])('a JSON 200 with %s is a server error, and nothing is loaded', async (_name, body) => {
    fetchMock.mockResolvedValue(
      typeof body === 'string' ? new Response(body, { status: 200, headers: { 'content-type': 'application/json' } }) : located(body),
    )

    await expect(client().getBlob(PATH)).rejects.toMatchObject({ kind: 'server', code: 'bad_audio_location' })
    expect(FakeMedia.instances).toEqual([])
  })

  it('a leave of the screen while the <audio> loads stops it, as a network error', async () => {
    fetchMock.mockResolvedValue(located({ url: R2 }))
    const leaving = new AbortController()

    const result = client().getBlob(PATH, leaving.signal).catch((e: unknown) => e)
    await vi.waitFor(() => expect(FakeMedia.instances).toHaveLength(1))
    leaving.abort()

    expect(await result).toMatchObject({ kind: 'network' })
    expect(last().src).toBe('')
  })
})
