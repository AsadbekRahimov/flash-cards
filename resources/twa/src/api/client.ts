import { ofetch, type FetchError } from 'ofetch'

/**
 * Categorizes failures from the TWA API so views can render different
 * states (retry button for network, "closed" message for 410, etc.).
 */
export class ApiClientError extends Error {
  constructor(
    public readonly kind:
      | 'network'      // connectivity / CORS / fetch threw
      | 'unauthorized' // 401 — JWT invalid/expired
      | 'forbidden'    // 403 — policy / group mismatch
      | 'gone'         // 410 — session closed
      | 'not_found'    // 404
      | 'validation'   // 422
      | 'rate_limited' // 429
      | 'server'       // 5xx
      | 'unknown',
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly raw?: unknown,
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
   * resolves with '', the original 401 is propagated.
   */
  onUnauthorized: () => Promise<string>
}

export function createApiClient(opts: ClientOptions) {
  const baseURL = opts.baseURL ?? '/api/twa'

  async function request<T>(
    path: string,
    init: {
      method?: 'GET' | 'POST'
      body?: Record<string, unknown>
      query?: Record<string, unknown>
      withAuth?: boolean
      _retry?: boolean
    } = {},
  ): Promise<T> {
    const withAuth = init.withAuth ?? true
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (withAuth) {
      const token = opts.getToken()
      if (token) headers.Authorization = `Bearer ${token}`
    }

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
        const fresh = await opts.onUnauthorized().catch(() => '')
        if (fresh) {
          return request<T>(path, { ...init, _retry: true })
        }
      }
      throw apiErr
    }
  }

  return {
    get: <T>(path: string, query?: Record<string, unknown>) =>
      request<T>(path, { method: 'GET', query }),
    post: <T>(path: string, body?: Record<string, unknown>) =>
      request<T>(path, { method: 'POST', body }),
    /** Unauthenticated POST — used only for /auth. */
    postPublic: <T>(path: string, body: Record<string, unknown>) =>
      request<T>(path, { method: 'POST', body, withAuth: false }),
  }
}

function mapError(err: unknown): ApiClientError {
  const fe = err as FetchError
  const status = Number(fe?.response?.status ?? 0)
  const data = fe?.data as { error?: { code?: string; message?: string } } | undefined
  const code = data?.error?.code ?? fe?.statusText ?? 'unknown'
  const message = data?.error?.message ?? fe?.message ?? 'Request failed'

  if (status === 0) return new ApiClientError('network', 0, code, message, err)
  if (status === 401) return new ApiClientError('unauthorized', 401, code, message, err)
  if (status === 403) return new ApiClientError('forbidden', 403, code, message, err)
  if (status === 404) return new ApiClientError('not_found', 404, code, message, err)
  if (status === 410) return new ApiClientError('gone', 410, code, message, err)
  if (status === 422) return new ApiClientError('validation', 422, code, message, err)
  if (status === 429) return new ApiClientError('rate_limited', 429, code, message, err)
  if (status >= 500) return new ApiClientError('server', status, code, message, err)
  return new ApiClientError('unknown', status, code, message, err)
}
