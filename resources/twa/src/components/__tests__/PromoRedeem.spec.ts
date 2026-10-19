import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PromoRedeem from '../PromoRedeem.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({ api: { promo: { redeem: vi.fn() } } }))

function me(until: string | null): MeResponse {
  return {
    learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
    groups: [],
    stats: { words_learned: 0, words_due_today: 0 },
    entitlement: { plan: 'pro', source: 'subscription', until, new_words_per_day: null },
    paywall: { mode: 'coming_soon', contact: null },
    settings: settingsFixture(),
  }
}

async function submit(code: string) {
  const w = mount(PromoRedeem)
  await w.get('[data-testid=promo-toggle]').trigger('click')
  await w.get('[data-testid=promo-input]').setValue(code)
  await w.get('form').trigger('submit')
  await flushPromises()
  return w
}

describe('PromoRedeem', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => setLocale('ru'))

  it('is collapsed until the toggle is clicked', () => {
    const w = mount(PromoRedeem)
    expect(w.find('[data-testid=promo-input]').exists()).toBe(false)
    expect(w.get('[data-testid=promo-toggle]').text()).toBe('Есть промокод?')
  })

  it('sends the trimmed code and shows the end of the new plan', async () => {
    vi.mocked(api.promo.redeem).mockResolvedValue(me('2026-12-31T12:00:00+00:00'))

    const w = await submit('  ab-12  ')

    expect(api.promo.redeem).toHaveBeenCalledWith('ab-12')
    expect(w.get('[data-testid=promo-success]').text()).toMatch(/^Pro активен до .*2026/)
    expect(w.find('[data-testid=promo-input]').exists()).toBe(false)
  })

  it('tells the parent which plan was redeemed', async () => {
    const pro = me('2026-12-31T12:00:00+00:00')
    vi.mocked(api.promo.redeem).mockResolvedValue(pro)

    const w = await submit('AB-12')

    expect(w.emitted('redeemed')).toEqual([[pro]])
  })

  it('says so when the plan has no end', async () => {
    vi.mocked(api.promo.redeem).mockResolvedValue(me(null))

    const w = await submit('FOREVER')

    expect(w.get('[data-testid=promo-success]').text()).toBe('Pro активен без ограничения по сроку.')
  })

  it.each([
    ['not_found', 404, 'promo_not_found', 'Промокод не найден. Проверьте, нет ли опечатки.'],
    ['gone', 410, 'promo_expired', 'Срок действия промокода истёк.'],
    ['conflict', 409, 'promo_already_redeemed', 'Вы уже активировали этот промокод.'],
  ] as const)('explains %s by code, not by status', async (kind, status, code, text) => {
    vi.mocked(api.promo.redeem).mockRejectedValue(new ApiClientError(kind, status, code, 'English backend message'))

    const w = await submit('X')

    expect(w.get('[data-testid=promo-error]').text()).toBe(text)
    expect(w.find('[data-testid=promo-input]').exists()).toBe(true)
    expect(w.emitted('redeemed')).toBeUndefined()
  })

  it('localizes to Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.promo.redeem).mockRejectedValue(new ApiClientError('gone', 410, 'promo_expired', 'x'))

    const w = await submit('X')

    expect(w.get('[data-testid=promo-error]').text()).toBe('Promokodning amal qilish muddati tugagan.')
  })

  it('does not call the API for an empty code', async () => {
    const w = mount(PromoRedeem)
    await w.get('[data-testid=promo-toggle]').trigger('click')
    await w.get('form').trigger('submit')

    expect(api.promo.redeem).not.toHaveBeenCalled()
    expect(w.get('[data-testid=promo-submit]').attributes('disabled')).toBeDefined()
  })
})
