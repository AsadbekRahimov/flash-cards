import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PaywallSheet from '../PaywallSheet.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { POLL_INTERVAL_MS } from '@/stores/billing'
import type { BillingCatalog, MeResponse, OrderStatusResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

const tg = vi.hoisted(() => ({ openInvoice: vi.fn(), openLink: vi.fn() }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => tg }))
vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn() },
  },
}))

const me = (mode: 'coming_soon' | 'purchase' = 'purchase', plan: 'free' | 'pro' = 'free'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: {
    plan,
    source: plan === 'pro' ? 'subscription' : 'free',
    until: plan === 'pro' ? '2026-12-01T00:00:00+00:00' : null,
    new_words_per_day: plan === 'pro' ? null : 20,
    renewal: null,
  },
  paywall: { mode, contact: '@lexiflow_support' },
  settings: settingsFixture(),
})

const catalog = (overrides: Partial<BillingCatalog> = {}): BillingCatalog => ({
  items: [
    { plan: 'pro', tier: null, period: 'month', prices: { XTR: 150, UZS: 2_900_000 }, list_prices: { XTR: 150, UZS: 2_900_000 } },
    { plan: 'pro', tier: null, period: 'year', prices: { XTR: 1300, UZS: 24_900_000 }, list_prices: { XTR: 1300, UZS: 24_900_000 } },
  ],
  providers: ['telegram_stars', 'payme', 'click'],
  offer: null,
  subscription: null,
  ...overrides,
})

const order = (status: OrderStatusResponse['status']): OrderStatusResponse => ({ status, paid_at: null, subscription: null })

async function render(props: { reason?: 'quota' | 'info' | 'decks' } = {}) {
  const wrapper = mount(PaywallSheet, { props })
  await flushPromises()
  return wrapper
}

/** NBSP / narrow NBSP of Intl grouping become plain spaces. */
const plain = (text: string) => text.replace(/[\s  ]+/g, ' ').trim()

describe('PaywallSheet in purchase mode', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.me.get).mockResolvedValue(me())
    vi.mocked(api.today.get).mockResolvedValue({ new_words_remaining: null, due_count: 0 } as never)
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog())
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
  })

  it('shows the prices of the first period and a pay button per provider', async () => {
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')
    expect(w.get('[data-testid=paywall-lead]').text()).toBe('Новые слова без дневного лимита, колоды и подробная статистика.')
    expect(plain(w.get('[data-testid=pay-telegram_stars]').text())).toBe('Оплатить: ⭐ Stars · 150 ⭐')
    expect(plain(w.get('[data-testid=pay-payme]').text())).toBe('Оплатить: Payme · 29 000 сум')
    expect(plain(w.get('[data-testid=pay-click]').text())).toBe('Оплатить: Click · 29 000 сум')
    expect(w.find('[data-testid=list-price]').exists()).toBe(false)
  })

  it('switches the period and the prices with it', async () => {
    const w = await render()

    await w.get('[data-testid=period-year]').trigger('click')

    expect(plain(w.get('[data-testid=pay-telegram_stars]').text())).toBe('Оплатить: ⭐ Stars · 1 300 ⭐')
    expect(plain(w.get('[data-testid=pay-payme]').text())).toBe('Оплатить: Payme · 249 000 сум')
  })

  it('offers only the providers the server lists, with a price in their currency', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({ providers: ['payme'] }))
    const w = await render()

    expect(w.find('[data-testid=pay-telegram_stars]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-click]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-payme]').exists()).toBe(true)
  })

  it('shows the offer, the discounted price and the list price crossed out', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      items: [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 105 }, list_prices: { XTR: 150 } }],
      providers: ['telegram_stars'],
      offer: { kind: 'post_org_discount', discount_pct: 30, period: 'month', expires_at: '2026-11-02T12:00:00+00:00' },
    }))
    const w = await render()

    expect(w.get('[data-testid=purchase-offer]').text()).toContain('Скидка 30% на первый платёж')
    expect(plain(w.get('[data-testid=pay-telegram_stars]').text())).toContain('105 ⭐')
    expect(w.get('[data-testid=list-price]').text()).toBe('150 ⭐')
    expect(w.find('[data-testid=period-month]').exists()).toBe(false) // a single period needs no tabs
  })

  it('pays with Stars: opens the invoice, waits for the order, then says Pro is on and refreshes /me and /today', async () => {
    vi.useFakeTimers()
    tg.openInvoice.mockResolvedValue('paid')
    vi.mocked(api.billing.checkout).mockResolvedValue({
      order: { id: 1, token: 'tok', status: 'pending', amount: 150, currency: 'XTR', expires_at: '2026-10-20T00:00:00+00:00' },
      checkout: { url: 'https://t.me/$inv', kind: 'invoice_link' },
    })
    vi.mocked(api.billing.order).mockResolvedValue(order('paid'))
    const w = await render()
    vi.mocked(api.me.get).mockResolvedValue(me('purchase', 'pro'))

    await w.get('[data-testid=pay-telegram_stars]').trigger('click')
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()

    expect(api.billing.checkout).toHaveBeenCalledWith('month', 'telegram_stars')
    expect(tg.openInvoice).toHaveBeenCalledWith('https://t.me/$inv')
    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro')
    expect(w.get('[data-testid=paywall-paid]').text()).toMatch(/^Спасибо! Pro активен до .*2026/)
    expect(w.find('[data-testid=purchase]').exists()).toBe(false)
    expect(api.today.get).toHaveBeenCalled()
  })

  it('pays with Click through the payment page and shows the wait', async () => {
    vi.useFakeTimers()
    vi.mocked(api.billing.checkout).mockResolvedValue({
      order: { id: 1, token: 'tok', status: 'pending', amount: 2_900_000, currency: 'UZS', expires_at: '2026-10-20T00:00:00+00:00' },
      checkout: { url: 'https://my.click.uz/pay', kind: 'redirect' },
    })
    vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
    const w = await render()

    await w.get('[data-testid=pay-click]').trigger('click')
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()

    expect(tg.openLink).toHaveBeenCalledWith('https://my.click.uz/pay')
    expect(w.get('[data-testid=purchase-awaiting]').text()).toBe('Ждём подтверждение оплаты…')
    expect(w.get('[data-testid=pay-click]').attributes('disabled')).toBeDefined()

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(api.billing.order).toHaveBeenCalledTimes(2)
  })

  it('stops waiting when the sheet is closed', async () => {
    vi.useFakeTimers()
    vi.mocked(api.billing.checkout).mockResolvedValue({
      order: { id: 1, token: 'tok', status: 'pending', amount: 1, currency: 'UZS', expires_at: '2026-10-20T00:00:00+00:00' },
      checkout: { url: 'https://my.click.uz/pay', kind: 'redirect' },
    })
    vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
    const w = await render()
    await w.get('[data-testid=pay-click]').trigger('click')
    await vi.advanceTimersByTimeAsync(0)

    w.unmount()
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3)

    expect(api.billing.order).toHaveBeenCalledTimes(1)
  })

  it('shows why the checkout was refused and lets the learner try again', async () => {
    vi.mocked(api.billing.checkout).mockRejectedValue(new ApiClientError('conflict', 409, 'provider_disabled', 'English'))
    const w = await render()

    await w.get('[data-testid=pay-payme]').trigger('click')
    await flushPromises()

    expect(w.get('[data-testid=purchase-error]').text()).toBe('Этот способ оплаты сейчас недоступен. Выберите другой.')
    expect(w.get('[data-testid=pay-payme]').attributes('disabled')).toBeUndefined()
  })

  it('does not sell while the Stars auto-renewal is running', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      subscription: { status: 'active', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-12-01T00:00:00+00:00', auto_renew: true, can_cancel: true, can_resume: false },
    }))
    const w = await render()

    expect(w.find('[data-testid=pay-telegram_stars]').exists()).toBe(false)
    expect(w.get('[data-testid=purchase-renewing]').text()).toContain('Автопродление Stars включено')
  })

  it('explains the exhausted quota with the purchase lead', async () => {
    const w = await render({ reason: 'quota' })

    expect(w.get('[data-testid=paywall-lead]').text()).toBe(
      'Лимит новых слов на сегодня исчерпан — повторения доступны без ограничений. Новые слова без дневного лимита, колоды и подробная статистика.',
    )
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')
    expect(plain(w.get('[data-testid=pay-payme]').text())).toBe("To'lash: Payme · 29 000 so'm")
    expect(w.get('[data-testid=period-year]').text()).toBe('Yil')
  })

  it('falls back to coming soon when the server says coming_soon, without asking for the catalog', async () => {
    vi.mocked(api.me.get).mockResolvedValue(me('coming_soon'))
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro скоро')
    expect(w.find('[data-testid=purchase]').exists()).toBe(false)
    expect(api.billing.catalog).not.toHaveBeenCalled()
  })

  it('falls back to coming soon when the mode is purchase but nothing is for sale or the catalog cannot be loaded', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({ items: [], providers: [] }))
    expect((await render()).get('[data-testid=paywall-title]').text()).toBe('Pro скоро')

    setActivePinia(createPinia())
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    vi.mocked(api.billing.catalog).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))
    const w = await render()
    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro скоро')
    expect(w.find('[data-testid=purchase]').exists()).toBe(false)
    warn.mockRestore()
  })

  it('keeps the promo field in purchase mode', async () => {
    const w = await render()

    expect(w.find('[data-testid=promo-toggle]').exists()).toBe(true)
  })
})
