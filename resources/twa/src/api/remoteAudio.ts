/**
 * A clip that lives on the public domain of the word audio (Cloudflare R2, docs/26 §9.9).
 *
 * The audio endpoints of the practice, the battle and the game answer a word on R2 with `200 {"url": "https://…"}` instead of its
 * bytes. That address is played by an `<audio>` element and only by it (CSP `media-src`): it is never `fetch`ed, so the bucket
 * needs no CORS, a copy in the cache of Cloudflare without `Access-Control-Allow-Origin` cannot break it, and the token of the
 * learner never goes to that domain (a 302 would be followed by `fetch` WITH the `Authorization` header).
 *
 * A phrase of a dictation and a word on this server stay a `Blob` fetched with the token: nothing changes for them.
 */
export class RemoteAudio {
  constructor(public readonly url: string) {}
}

/** What a clip fetcher gives: the bytes (with the token, from this server), or the public address of a word on R2. */
export type ClipBody = Blob | RemoteAudio

/** The code of the error of a clip on R2 that the device could not load (`network` kind): a 404 of the bucket and a lost connection alike. */
export const REMOTE_AUDIO_FAILED = 'remote_audio_failed'

/**
 * How long the loading of a clip on R2 is waited for before it is taken as ready all the same. A WebView that does not load a
 * media element before a tap (an old iOS one) never says "loaded" nor "failed": its clip is then checked by the tap itself (the
 * `error` of the player makes the slot `failed`, as for a blob that does not decode). The address is vouched for by the server:
 * the `audio_path` of the word is the one its text hashes to.
 */
export const PROBE_GRACE_MS = 6_000

/** The address of `{url}` in the answer of an audio endpoint: absolute http(s) with a host, nothing else. Null: not an address. */
export function remoteAudioUrl(data: unknown): string | null {
  if (typeof data !== 'object' || data === null || Array.isArray(data)) return null
  const raw = (data as { url?: unknown }).url
  if (typeof raw !== 'string' || raw === '') return null
  let parsed: URL
  try {
    parsed = new URL(raw)
  } catch {
    return null
  }
  if ((parsed.protocol !== 'https:' && parsed.protocol !== 'http:') || parsed.hostname === '' || parsed.username !== '' || parsed.password !== '') return null
  return parsed.href
}

/** The device could not load the clip (`MediaError`): the bucket has no such file, or the connection failed. */
export class RemoteAudioError extends Error {
  constructor(public readonly mediaCode: number | null) {
    super('The audio could not be loaded')
    this.name = 'RemoteAudioError'
  }
}

/**
 * Loads a clip on R2 into an `<audio>` element without playing it, so that the tap plays it from the HTTP cache (the object is
 * `public, max-age=31536000, immutable`) and so that a file that is not there is known before the tap - the room of a battle
 * waits for its five clips before "Ready".
 *
 * Resolves when the element can play (`canplaythrough` / `loadeddata`), or after {@see PROBE_GRACE_MS} without an answer (see
 * there). Rejects with {@link RemoteAudioError} on the `error` of the element, and with an `AbortError` when `signal` fires.
 * Without `Audio` (no DOM) it resolves at once.
 */
export function probeRemoteAudio(url: string, signal?: AbortSignal, graceMs: number = PROBE_GRACE_MS): Promise<void> {
  if (typeof Audio === 'undefined') return Promise.resolve()
  if (signal?.aborted) return Promise.reject(new DOMException('The load was cancelled', 'AbortError'))

  return new Promise<void>((resolve, reject) => {
    const media = new Audio()
    let settled = false

    const finish = (outcome: () => void): void => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      media.removeEventListener('canplaythrough', onReady)
      media.removeEventListener('loadeddata', onReady)
      media.removeEventListener('error', onError)
      signal?.removeEventListener('abort', onAbort)
      outcome()
    }
    const onReady = (): void => finish(resolve)
    const onError = (): void => finish(() => reject(new RemoteAudioError(media.error?.code ?? null)))
    const onAbort = (): void =>
      finish(() => {
        // A load nobody waits for must not keep a connection.
        try {
          media.removeAttribute('src')
          media.load()
        } catch {
          // best effort
        }
        reject(new DOMException('The load was cancelled', 'AbortError'))
      })
    const timer = setTimeout(() => finish(resolve), graceMs)

    media.addEventListener('canplaythrough', onReady)
    media.addEventListener('loadeddata', onReady)
    media.addEventListener('error', onError)
    signal?.addEventListener('abort', onAbort, { once: true })
    media.preload = 'auto'
    media.src = url
    try {
      media.load()
    } catch {
      // an element that refuses to load explicitly still loads by `preload`, or the grace answers
    }
  })
}
