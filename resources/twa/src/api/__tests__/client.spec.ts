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
