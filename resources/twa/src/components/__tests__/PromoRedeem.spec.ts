import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PromoRedeem from '../PromoRedeem.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { ICONS } from '@/components/ui/icons'
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

  it('collapsed is a plain button, expanded is a field and a primary button side by side', async () => {
    const w = mount(PromoRedeem)
    const toggle = w.get('[data-testid=promo-toggle]')
    expect(toggle.element.tagName).toBe('BUTTON')
    expect(toggle.classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain']))

    await toggle.trigger('click')

    expect(w.find('[data-testid=promo-toggle]').exists()).toBe(false)
    const input = w.get('[data-testid=promo-input]')
    expect(input.classes()).toEqual(expect.arrayContaining(['field', 'uppercase']))
    expect(input.attributes('autocapitalize')).toBe('characters')
    expect(input.attributes('aria-label')).toBe('Промокод')
    expect(input.attributes('placeholder')).toBe('Промокод')
    const submit = w.get('[data-testid=promo-submit]')
    expect(submit.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary']))
    expect(submit.text()).toBe('Активировать')
    expect(w.get('form').classes()).toContain('flex')
  })

  it('puts the cursor into the field when it opens', async () => {
    const w = mount(PromoRedeem, { attachTo: document.body })

    await w.get('[data-testid=promo-toggle]').trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(w.get('[data-testid=promo-input]').element)
    w.unmount()
  })

  it('sends the trimmed code and shows the end of the new plan', async () => {
    vi.mocked(api.promo.redeem).mockResolvedValue(me('2026-12-31T12:00:00+00:00'))

    const w = await submit('  ab-12  ')

    expect(api.promo.redeem).toHaveBeenCalledWith('ab-12')
    expect(w.get('[data-testid=promo-success]').text()).toMatch(/^Pro активен до .*2026/)
    expect(w.find('[data-testid=promo-input]').exists()).toBe(false)
  })

  it('shows the success as a green status message with a check icon, the end date in the interface language', async () => {
    vi.mocked(api.promo.redeem).mockResolvedValue(me('2026-12-31T12:00:00+00:00'))

    const w = await submit('AB-12')

    const success = w.get('[data-testid=promo-success]')
    expect(success.text()).toBe('Pro активен до 31 декабря 2026 г.') // the date ends with a period of its own
    expect(success.get('path').attributes('d')).toBe(ICONS['check-circle'].outline[0]?.d)
    expect(success.classes()).toEqual(expect.arrayContaining(['bg-success-soft', 'text-success-ink']))
    expect(success.attributes('role')).toBe('status')
    expect(w.find('[data-testid=promo-toggle]').exists()).toBe(false)
    expect(w.find('[data-testid=promo-error]').exists()).toBe(false)
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

  it('shows a refused code as a red alert with an icon, and the field stays', async () => {
    vi.mocked(api.promo.redeem).mockRejectedValue(new ApiClientError('not_found', 404, 'promo_not_found', 'x'))

    const w = await submit('X')

    const alert = w.get('[data-testid=promo-error]')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.classes()).toEqual(expect.arrayContaining(['bg-danger-soft', 'text-danger-ink']))
    expect(alert.get('path').attributes('d')).toBe(ICONS['exclamation-circle'].outline[0]?.d)
    expect(w.find('[data-testid=promo-success]').exists()).toBe(false)
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
