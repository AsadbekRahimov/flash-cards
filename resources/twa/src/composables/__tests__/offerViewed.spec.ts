import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { OFFER_PLACEMENTS, paywallPlacement, reportOfferViewed, resetOfferViewed } from '../offerViewed'
import { useBillingStore } from '@/stores/billing'
import type { Paywall } from '@/api/types'

vi.mock('@/api', () => ({ api: { billing: { offerViewed: vi.fn() } } }))

function sells(mode: Paywall['mode'] | null): void {
  useBillingStore().paywall = mode === null ? null : { mode, contact: null }
}

describe('reportOfferViewed', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    resetOfferViewed()
    vi.mocked(api.billing.offerViewed).mockResolvedValue(undefined)
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('reports the placement while Pro is for sale', () => {
    sells('purchase')

    reportOfferViewed('paywall_quota')

    expect(api.billing.offerViewed).toHaveBeenCalledExactlyOnceWith('paywall_quota')
  })

  it('stays quiet while nothing is for sale, and before /me is known (it never asks for it)', () => {
    sells('coming_soon')
    reportOfferViewed('billing_view')
    sells(null)
    reportOfferViewed('billing_view')

    expect(api.billing.offerViewed).not.toHaveBeenCalled()
  })

  it('sends a placement once a device day, each placement on its own', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 10, 19, 10, 0))
    sells('purchase')

    reportOfferViewed('finished_pro_tip')
    reportOfferViewed('finished_pro_tip')
    reportOfferViewed('lapsed_banner')
    expect(api.billing.offerViewed).toHaveBeenCalledTimes(2)

    vi.setSystemTime(new Date(2026, 10, 20, 0, 5))
    reportOfferViewed('finished_pro_tip')
    expect(vi.mocked(api.billing.offerViewed).mock.calls.map((c) => c[0])).toEqual(['finished_pro_tip', 'lapsed_banner', 'finished_pro_tip'])
  })

  it('never throws and never rejects: a failed request and a missing client are swallowed', async () => {
    sells('purchase')
    vi.mocked(api.billing.offerViewed).mockRejectedValueOnce(new Error('network'))
    expect(() => reportOfferViewed('paywall_game')).not.toThrow()
    await Promise.resolve()

    vi.mocked(api.billing.offerViewed).mockImplementationOnce(() => {
      throw new Error('no client')
    })
    expect(() => reportOfferViewed('paywall_decks')).not.toThrow()
  })

  it('names the purchase sheet by its reason, inside the closed list', () => {
    expect(paywallPlacement('quota')).toBe('paywall_quota')
    expect(paywallPlacement('practice_limit')).toBe('paywall_practice_limit')
    for (const reason of ['quota', 'info', 'decks', 'streak_freeze', 'exam_simulator', 'practice', 'practice_limit', 'game'] as const) {
      expect(OFFER_PLACEMENTS).toContain(paywallPlacement(reason))
    }
  })
})
