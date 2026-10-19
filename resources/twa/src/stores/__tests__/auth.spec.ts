import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../auth'
import { api } from '@/api'
import { getLocale, setLocale } from '@/i18n'

vi.mock('@/api', () => ({
  api: {
    auth: {
      authenticate: vi.fn(),
    },
  },
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ initData: () => 'signed-init-data' }),
}))

const learner = { id: 5, first_name: 'Alice', locale: 'ru', onboarding_completed: false }

describe('auth store — one learner for every session', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.auth.authenticate).mockResolvedValue({ token: 'jwt-1', expires_in: 900, learner })
  })

  it('stores the learner and the token on login', async () => {
    const auth = useAuthStore()

    await auth.login({ type: 'training', sessionId: 1 })

    expect(auth.learner).toEqual(learner)
    expect(auth.token).toBe('jwt-1')
    expect(auth.isAuthenticated).toBe(true)
  })

  it('switches the interface to the learner\'s own language on login, and ignores one it does not know', async () => {
    const auth = useAuthStore()
    vi.mocked(api.auth.authenticate).mockResolvedValue({ token: 'jwt-1', expires_in: 900, learner: { ...learner, locale: 'uz' } })

    await auth.login()
    expect(getLocale()).toBe('uz')

    vi.mocked(api.auth.authenticate).mockResolvedValue({ token: 'jwt-2', expires_in: 900, learner: { ...learner, locale: 'de' } })
    await auth.login()
    expect(getLocale()).toBe('uz')

    setLocale('ru')
  })

  it('does not log in again when only the context changes', async () => {
    const auth = useAuthStore()
    await auth.ensure({ type: 'training', sessionId: 1 })

    await auth.ensure({ type: 'exam', sessionId: 9 })

    expect(api.auth.authenticate).toHaveBeenCalledTimes(1)
    expect(auth.context).toEqual({ type: 'exam', sessionId: 9 })
  })

  it('logs in again when the token is close to expiry', async () => {
    const auth = useAuthStore()
    await auth.ensure({ type: 'training', sessionId: 1 })
    auth.expiresAt = Date.now() + 30_000

    await auth.ensure({ type: 'training', sessionId: 1 })

    expect(api.auth.authenticate).toHaveBeenCalledTimes(2)
  })

  it('keeps the current context when an expired token is refreshed by a screen without one', async () => {
    const auth = useAuthStore()
    await auth.ensure({ type: 'training', sessionId: 7 })
    auth.expiresAt = Date.now() - 1_000

    await auth.ensure()

    expect(api.auth.authenticate).toHaveBeenCalledTimes(2)
    expect(api.auth.authenticate).toHaveBeenLastCalledWith('signed-init-data', { type: 'training', sessionId: 7 })
    expect(auth.context).toEqual({ type: 'training', sessionId: 7 })
  })

  it('replaces the context when an expired token is refreshed with a new one', async () => {
    const auth = useAuthStore()
    await auth.ensure({ type: 'training', sessionId: 7 })
    auth.expiresAt = Date.now() - 1_000

    await auth.ensure({ type: 'exam', sessionId: 3 })

    expect(auth.context).toEqual({ type: 'exam', sessionId: 3 })
  })

  it('clears the learner on logout', async () => {
    const auth = useAuthStore()
    await auth.login(null)

    auth.logout()

    expect(auth.learner).toBeNull()
    expect(auth.token).toBe('')
  })
})
