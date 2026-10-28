import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { ME_FRESH_MS, useBillingStore } from '../billing'
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
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

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

    // `apply()` made the data fresh, so only a forced load goes to the server at all.
    await expect(billing.load({ force: true })).resolves.toBeUndefined()

    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0]?.[0]).toContain('billing')
    expect(billing.entitlement?.new_words_per_day).toBe(20)
    expect(billing.loading).toBe(false)
  })

  describe('freshness of /me', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-10-28T12:00:00Z'))
      vi.mocked(api.me.get).mockResolvedValue(me())
    })

    it('does not ask /me again while the data is younger than 30 seconds', async () => {
      const billing = useBillingStore()

      await billing.load()
      await billing.load()
      vi.advanceTimersByTime(ME_FRESH_MS - 1)
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.entitlement?.new_words_per_day).toBe(20)
    })

    it('asks again once the data is 30 seconds old', async () => {
      const billing = useBillingStore()
      await billing.load()

      vi.advanceTimersByTime(ME_FRESH_MS)
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(2)
    })

    it('asks with force even when the data is fresh, and the answer starts a new 30 seconds', async () => {
      const billing = useBillingStore()
      await billing.load()
      vi.advanceTimersByTime(ME_FRESH_MS - 1)

      await billing.load({ force: true })
      expect(api.me.get).toHaveBeenCalledTimes(2)

      vi.advanceTimersByTime(ME_FRESH_MS - 1)
      await billing.load()
      expect(api.me.get).toHaveBeenCalledTimes(2)
    })

    it('does not send a second request while one is in flight, forced or not', async () => {
      let finish: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const billing = useBillingStore()

      const first = billing.load()
      await billing.load()
      await billing.load({ force: true })
      finish(me())
      await first

      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(billing.loading).toBe(false)
    })

    it('never calls the data fresh after a failed request: a forced load that fails makes the next one ask', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      const billing = useBillingStore()
      await billing.load()
      vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))

      await billing.load({ force: true })
      expect(billing.entitlement?.new_words_per_day).toBe(20) // still shown
      expect(billing.loadedAt).toBeNull()

      vi.mocked(api.me.get).mockResolvedValue(me())
      await billing.load()
      expect(api.me.get).toHaveBeenCalledTimes(3)
    })

    it('asks again after a failure on the very first load', async () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      vi.mocked(api.me.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'x'))
      const billing = useBillingStore()

      await billing.load()
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(2)
      expect(billing.entitlement).not.toBeNull()
    })

    it('counts an answer given to apply() as fresh (promo, cancel / resume)', async () => {
      const billing = useBillingStore()

      billing.apply(me())
      await billing.load()
      expect(api.me.get).not.toHaveBeenCalled()

      vi.advanceTimersByTime(ME_FRESH_MS)
      await billing.load()
      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('invalidate() makes the next load ask, however young the data is', async () => {
      const billing = useBillingStore()
      await billing.load()

      billing.invalidate()
      await billing.load()

      expect(api.me.get).toHaveBeenCalledTimes(2)
    })
  })
})
