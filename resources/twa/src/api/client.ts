import { ofetch, type FetchError } from 'ofetch'
import { getLocale } from '@/i18n'

/**
 * Categorizes failures from the TWA API so views can render different
 * states (retry button for network, "closed" message for 410, etc.).
 */
export class ApiClientError extends Error {
  constructor(
    public readonly kind:
      | 'network'      // connectivity / CORS / fetch threw
      | 'unauthorized' // 401 — JWT invalid/expired
      | 'forbidden'    // 403 — policy / not a group member
      | 'gone'         // 410 — session closed
      | 'not_found'    // 404
      | 'conflict'     // 409 — already answered / results not ready yet
      | 'validation'   // 422
      | 'rate_limited' // 429
      | 'server'       // 5xx
      | 'unknown',
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly raw?: unknown,
    /**
     * `error.details` of the envelope (docs/04 A.6, docs/16 §7.6): the fields a client needs for some codes
     * (`practice_session_active`, `answer_already_accepted`, …); null when the server sent none.
     */
    public readonly details: Record<string, unknown> | null = null,
  ) {
    super(message)
    this.name = 'ApiClientError'
  }
}

export interface ClientOptions {
  baseURL?: string
  /** Returns the currently-cached JWT, or '' if unauthenticated. */
  getToken: () => string
  /**
   * Called when the server returns 401. If the returned promise resolves
   * with a fresh token, the request is retried once. If it rejects or
   * resolves with '', the original 401 is propagated. Concurrent 401s share
   * one call (single-flight). On failure the implementation clears the stored token.
   */
  onUnauthorized: () => Promise<string>
}

/**
 * How long a clip may take, from the request to the last byte. A request that hangs is given up like a lost connection
 * (a `network` error), so that the dictation offers "Retry" / "Replace the task" instead of a spinner for ever (docs/15 §3.4:
 * the sound never blocks the screen).
 */
export const BLOB_TIMEOUT_MS = 15_000

/** The refresh in flight, shared by every request that got a 401 meanwhile. */
let refreshing: Promise<string> | null = null

function refreshToken(refresh: () => Promise<string>): Promise<string> {
  if (!refreshing) {
    const p: Promise<string> = refresh().finally(() => {
      if (refreshing === p) refreshing = null
    })
    refreshing = p
  }
  return refreshing
}

export function createApiClient(opts: ClientOptions) {
  const baseURL = opts.baseURL ?? '/api/twa'

  /**
   * After a 401: the token somebody else has refreshed while the request was in flight, else a fresh one from
   * `onUnauthorized` (single-flight). '' = give up and let the 401 through.
   */
  async function freshToken(sentToken: string): Promise<string> {
    const current = opts.getToken()
    return current && current !== sentToken ? current : await refreshToken(opts.onUnauthorized).catch(() => '')
  }

  async function request<T>(
    path: string,
    init: {
      method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
      body?: Record<string, unknown>
      query?: Record<string, unknown>
      withAuth?: boolean
      _retry?: boolean
    } = {},
  ): Promise<T> {
    const withAuth = init.withAuth ?? true
    // The server picks the language of validation/error texts: by the learner when authenticated,
    // by this header on /auth.
    const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': getLocale() }
    const sentToken = withAuth ? opts.getToken() : ''
    if (sentToken) headers.Authorization = `Bearer ${sentToken}`

    try {
      return await ofetch<T>(path, {
        baseURL,
        method: init.method ?? 'GET',
        headers,
        body: init.body,
        query: init.query,
      })
    } catch (err) {
      const apiErr = mapError(err)
      if (apiErr.kind === 'unauthorized' && withAuth && !init._retry) {
        const fresh = await freshToken(sentToken)
        if (fresh) {
          return request<T>(path, { ...init, _retry: true })
        }
      }
      throw apiErr
    }
  }

  /**
   * The address of a file the server named in an answer (`audio_url` is `/api/twa/practice/sessions/7/audio/eK7…`),
   * relative to `baseURL`. Only a path of this very origin under the API prefix is accepted: the token goes in the
   * header of this request, so an absolute URL from a response (or a path that climbs out of the prefix) must never
   * be followed.
   */
  function localPath(path: string): string {
    const rest = path.startsWith(`${baseURL}/`) ? path.slice(baseURL.length) : path
    // eslint-disable-next-line no-control-regex
    if (!rest.startsWith('/') || rest.startsWith('//') || rest.includes('\\') || /[\u0000-\u001f\u007f]/.test(rest) || rest.split('/').includes('..')) {
      throw new ApiClientError('unknown', 0, 'invalid_path', 'Not a path of the API')
    }
    return rest
  }

  /**
   * An authenticated GET of a binary body (the audio of a dictation). Plain `fetch` with the `Authorization` header:
   * an `<audio src>` cannot carry it, and a blob is what iOS needs to play on a tap anyway. Errors (JSON envelope, 401
   * refresh once) are mapped exactly like `request`.
   *
   * The request is stopped after `BLOB_TIMEOUT_MS`, or when the caller's `signal` fires (the screen was left, a newer
   * clip is wanted): both end as a `network` error - the headers and the body alike are under the same limit - and the
   * caller, which knows it dropped the load, ignores it.
   */
  async function requestBlob(path: string, signal?: AbortSignal, retried = false): Promise<Blob> {
    const url = baseURL + localPath(path)
    const headers: Record<string, string> = { Accept: 'audio/mpeg, application/json;q=0.5', 'Accept-Language': getLocale() }
    const sentToken = opts.getToken()
    if (sentToken) headers.Authorization = `Bearer ${sentToken}`

    const controller = new AbortController()
    const stop = (): void => controller.abort()
    const timer = setTimeout(stop, BLOB_TIMEOUT_MS)
    signal?.addEventListener('abort', stop, { once: true })

    let apiErr: ApiClientError
    try {
      // A load that was dropped before it started is not sent at all.
      if (signal?.aborted) throw new Error('The request was cancelled')
      const response = await fetch(url, { headers, credentials: 'omit', signal: controller.signal })
      if (response.ok) return await response.blob()
      apiErr = await errorOf(response)
    } catch (err) {
      apiErr = err instanceof ApiClientError ? err : new ApiClientError('network', 0, 'network', (err as Error)?.message ?? 'Request failed', err)
    } finally {
      clearTimeout(timer)
      signal?.removeEventListener('abort', stop)
    }

    if (apiErr.kind === 'unauthorized' && !retried) {
      const fresh = await freshToken(sentToken)
      if (fresh) return requestBlob(path, signal, true)
    }
    throw apiErr
  }

  return {
    get: <T>(path: string, query?: Record<string, unknown>) =>
      request<T>(path, { method: 'GET', query }),
    post: <T>(path: string, body?: Record<string, unknown>) =>
      request<T>(path, { method: 'POST', body }),
    patch: <T>(path: string, body?: Record<string, unknown>) =>
      request<T>(path, { method: 'PATCH', body }),
    delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
    /** Unauthenticated POST — used only for /auth. */
    postPublic: <T>(path: string, body: Record<string, unknown>) =>
      request<T>(path, { method: 'POST', body, withAuth: false }),
    /**
     * Authenticated GET of a file (audio): the body as a `Blob`. `path` may carry the `/api/twa` prefix, as `audio_url` does.
     * Gives up after `BLOB_TIMEOUT_MS`, or when `signal` fires: a `network` error either way.
     */
    getBlob: (path: string, signal?: AbortSignal) => requestBlob(path, signal),
  }
}

function mapError(err: unknown): ApiClientError {
  const fe = err as FetchError
  const status = Number(fe?.response?.status ?? 0)
  const data = fe?.data as ErrorEnvelope | undefined
  const code = data?.error?.code ?? fe?.statusText ?? 'unknown'
  const message = data?.error?.message ?? fe?.message ?? 'Request failed'

  return errorFromStatus(status, code, message, err, detailsOf(data))
}

/** The answer of an error status read with plain `fetch` (a blob request): the JSON envelope, when there is one. */
async function errorOf(response: Response): Promise<ApiClientError> {
  let data: ErrorEnvelope | undefined
  try {
    data = (await response.json()) as ErrorEnvelope
  } catch {
    // not JSON (a proxy page, an empty body): the status alone tells the kind
  }
  return errorFromStatus(
    response.status,
    data?.error?.code ?? (response.statusText || 'unknown'),
    data?.error?.message ?? `HTTP ${response.status}`,
    response,
    detailsOf(data),
  )
}

interface ErrorEnvelope {
  error?: { code?: string; message?: string; details?: unknown }
}

/** `error.details` when it is an object (the envelope never sends a list or a scalar there). */
function detailsOf(data: ErrorEnvelope | null | undefined): Record<string, unknown> | null {
  const details = data?.error?.details
  return typeof details === 'object' && details !== null && !Array.isArray(details) ? (details as Record<string, unknown>) : null
}

function errorFromStatus(
  status: number,
  code: string,
  message: string,
  raw: unknown,
  details: Record<string, unknown> | null,
): ApiClientError {
  if (status === 0) return new ApiClientError('network', 0, code, message, raw, details)
  if (status === 401) return new ApiClientError('unauthorized', 401, code, message, raw, details)
  if (status === 403) return new ApiClientError('forbidden', 403, code, message, raw, details)
  if (status === 404) return new ApiClientError('not_found', 404, code, message, raw, details)
  if (status === 409) return new ApiClientError('conflict', 409, code, message, raw, details)
  if (status === 410) return new ApiClientError('gone', 410, code, message, raw, details)
  if (status === 422) return new ApiClientError('validation', 422, code, message, raw, details)
  if (status === 429) return new ApiClientError('rate_limited', 429, code, message, raw, details)
  if (status >= 500) return new ApiClientError('server', status, code, message, raw, details)
  return new ApiClientError('unknown', status, code, message, raw, details)
}
