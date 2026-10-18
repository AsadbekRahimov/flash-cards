import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../auth'
import { api } from '@/api'

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

  it('clears the learner on logout', async () => {
    const auth = useAuthStore()
    await auth.login(null)

    auth.logout()

    expect(auth.learner).toBeNull()
    expect(auth.token).toBe('')
  })
})
