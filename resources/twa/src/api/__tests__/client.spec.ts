import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ofetch } from 'ofetch'
import { createApiClient } from '../client'
import { setLocale } from '@/i18n'

vi.mock('ofetch', () => ({ ofetch: vi.fn() }))

function sentHeaders(): Record<string, string> {
  const opts = vi.mocked(ofetch).mock.calls[0]![1] as { headers: Record<string, string> }
  return opts.headers
}

describe('api client — Accept-Language', () => {
  beforeEach(() => {
    vi.mocked(ofetch).mockReset()
    vi.mocked(ofetch).mockResolvedValue({} as never)
  })
  afterEach(() => setLocale('ru'))

  const client = () => createApiClient({ getToken: () => 'jwt', onUnauthorized: async () => '' })

  it('sends the current interface locale, authenticated requests included', async () => {
    setLocale('uz')
    await client().get('/me')

    expect(sentHeaders()['Accept-Language']).toBe('uz')
    expect(sentHeaders().Authorization).toBe('Bearer jwt')
  })

  it('follows a locale change between requests, and is sent on the public /auth call too', async () => {
    setLocale('uz')
    await client().postPublic('/auth', { init_data: 'x' })
    expect(sentHeaders()['Accept-Language']).toBe('uz')
    expect(sentHeaders().Authorization).toBeUndefined()

    vi.mocked(ofetch).mockClear()
    setLocale('ru')
    await client().get('/me')
    expect(sentHeaders()['Accept-Language']).toBe('ru')
  })
})

describe('api client — single-flight 401 refresh', () => {
  const unauthorized = () => Object.assign(new Error('401'), { response: { status: 401 }, data: {} })

  function setup(refresh: () => Promise<string>) {
    let token = 'old'
    const onUnauthorized = vi.fn(async () => {
      try {
        token = await refresh()
      } catch (e) {
        token = ''
        throw e
      }
      return token
    })
    const client = createApiClient({ getToken: () => token, onUnauthorized })
    vi.mocked(ofetch).mockReset()
    vi.mocked(ofetch).mockImplementation((async (_p: string, o: { headers: Record<string, string> }) => {
      if (o.headers.Authorization === 'Bearer new') return { ok: true }
      throw unauthorized()
    }) as never)
    return { client, onUnauthorized, getToken: () => token }
  }

  it('5 parallel 401s trigger exactly one refresh and all are retried once', async () => {
    const { client, onUnauthorized } = setup(async () => {
      await new Promise((r) => setTimeout(r, 10))
      return 'new'
    })

    const res = await Promise.all([1, 2, 3, 4, 5].map((i) => client.get(`/x${i}`)))

    expect(res).toHaveLength(5)
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(vi.mocked(ofetch)).toHaveBeenCalledTimes(10)
  })

  it('a failed refresh rejects every waiter, clears the token, and the next call tries again', async () => {
    let fail = true
    const { client, onUnauthorized, getToken } = setup(async () => {
      await new Promise((r) => setTimeout(r, 5))
      if (fail) throw new Error('auth down')
      return 'new'
    })

    const results = await Promise.allSettled([1, 2, 3].map((i) => client.get(`/x${i}`)))
    expect(results.every((r) => r.status === 'rejected')).toBe(true)
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(getToken()).toBe('')

    fail = false
    await expect(client.get('/again')).resolves.toEqual({ ok: true })
    expect(onUnauthorized).toHaveBeenCalledTimes(2)
  })

  it('does not loop when the retried request is still 401', async () => {
    const { client, onUnauthorized } = setup(async () => 'still-bad')

    await expect(client.get('/x')).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(vi.mocked(ofetch)).toHaveBeenCalledTimes(2)
  })
})
