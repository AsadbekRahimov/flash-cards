import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useBillingStore } from '../billing'
import type { MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() } } }))

const me = (): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode: 'coming_soon', contact: '@lexiflow_help' },
  settings: settingsFixture(),
})

describe('billing store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => vi.restoreAllMocks())

  it('loads the plan and the paywall settings from /me', async () => {
    vi.mocked(api.me.get).mockResolvedValue(me())
    const billing = useBillingStore()

    await billing.load()

    expect(billing.entitlement?.new_words_per_day).toBe(20)
    expect(billing.paywall?.contact).toBe('@lexiflow_help')
    expect(billing.loading).toBe(false)
  })

  it('warns, keeps what it had and does not throw when /me fails', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const billing = useBillingStore()
    billing.apply(me())
    vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))

    await expect(billing.load()).resolves.toBeUndefined()

    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0]?.[0]).toContain('billing')
    expect(billing.entitlement?.new_words_per_day).toBe(20)
    expect(billing.loading).toBe(false)
  })
})
