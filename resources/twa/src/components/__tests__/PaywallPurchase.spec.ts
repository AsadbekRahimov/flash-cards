import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PaywallSheet from '../PaywallSheet.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { POLL_ATTEMPTS, POLL_INTERVAL_MS } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
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

const order = (status: OrderStatusResponse['status']): OrderStatusResponse => ({ status, paid_at: null, reason: null, replaced_by: null, subscription: null })

async function render(props: { reason?: 'quota' | 'info' | 'decks' } = {}) {
  const wrapper = mount(PaywallSheet, { props })
  await flushPromises()
  return wrapper
}

/** NBSP / narrow NBSP of Intl grouping become plain spaces. */
const plain = (text: string) => text.replace(/[\s  ]+/g, ' ').trim()

type Wrapper = Awaited<ReturnType<typeof render>>

/** A pay button reads «provider ... price [crossed-out list price]»: its parts, left to right. */
const payName = (w: Wrapper, provider: string) => plain(w.get(`[data-testid=pay-${provider}] > span:first-child`).text())
const payPrice = (w: Wrapper, provider: string) => plain(w.get(`[data-testid=pay-${provider}] > span:last-child > span`).text())

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

  it('shows the prices of the first period and a pay button per provider: the name on the left, the price on the right', async () => {
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')
    expect(w.get('[data-testid=paywall-lead]').text()).toBe(
      'Новые слова без дневного лимита, упражнения на написание, свои колоды, заморозки серии и пробные тесты.',
    )
    expect([payName(w, 'telegram_stars'), payPrice(w, 'telegram_stars')]).toEqual(['Telegram Stars', '150 ⭐'])
    expect([payName(w, 'payme'), payPrice(w, 'payme')]).toEqual(['Payme', '29 000 сум'])
    expect([payName(w, 'click'), payPrice(w, 'click')]).toEqual(['Click', '29 000 сум'])
    expect(w.find('[data-testid=list-price]').exists()).toBe(false)
  })

  it('names the provider without a star: the star is already in the Stars price', async () => {
    const w = await render()

    expect(payName(w, 'telegram_stars')).toBe('Telegram Stars')
    expect(payName(w, 'telegram_stars')).not.toContain('⭐')
  })

  it('makes only the first pay button primary, the others are secondary', async () => {
    const w = await render()

    const classes = (provider: string) => w.get(`[data-testid=pay-${provider}]`).classes()
    expect(classes('telegram_stars')).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-block']))
    expect(classes('payme')).toContain('btn-secondary')
    expect(classes('payme')).not.toContain('btn-primary')
    expect(classes('click')).toContain('btn-secondary')
    expect(w.get('[data-testid=paywall]').findAll('.btn-primary')).toHaveLength(1)
  })

  it('makes the first offered provider the primary one even when Stars are not offered', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({ providers: ['payme', 'click'] }))
    const w = await render()

    expect(w.get('[data-testid=pay-payme]').classes()).toContain('btn-primary')
    expect(w.get('[data-testid=pay-click]').classes()).toContain('btn-secondary')
  })

  it('says what the button does for a screen reader', async () => {
    const w = await render()

    expect(plain(w.get('[data-testid=pay-payme]').attributes('aria-label') ?? '')).toBe('Оплатить: Payme · 29 000 сум')
  })

  it('lists what Pro gives and names the way to pay above the buttons', async () => {
    const w = await render()

    expect(w.findAll('[data-testid=benefit]')).toHaveLength(5)
    expect(w.get('[data-testid=pay-method]').text()).toBe('Способ оплаты')
  })

  it('switches the period and the prices with it', async () => {
    const w = await render()

    await w.get('[data-testid=period-year]').trigger('click')

    expect(payPrice(w, 'telegram_stars')).toBe('1 300 ⭐')
    expect(payPrice(w, 'payme')).toBe('249 000 сум')
    expect(w.get('[data-testid=period-year]').attributes('aria-checked')).toBe('true')
    expect(w.get('[data-testid=period-month]').attributes('aria-checked')).toBe('false')

    await w.get('[data-testid=period-month]').trigger('click')
    expect(payPrice(w, 'payme')).toBe('29 000 сум')
  })

  describe('the period switch', () => {
    it('says how much the year saves, from the prices of one currency (sums first)', async () => {
      const w = await render()

      // 1 − 249 000 / (12 × 29 000) = 28 %
      expect(w.get('[data-testid=period-month]').text()).toBe('Месяц')
      expect(w.get('[data-testid=period-year]').text()).toBe('Год · −28%')
    })

    it('reads Stars when there are no sum prices', async () => {
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
        items: [
          { plan: 'pro', tier: null, period: 'month', prices: { XTR: 100 }, list_prices: { XTR: 100 } },
          { plan: 'pro', tier: null, period: 'year', prices: { XTR: 900 }, list_prices: { XTR: 900 } },
        ],
        providers: ['telegram_stars'],
      }))
      const w = await render()

      expect(w.get('[data-testid=period-year]').text()).toBe('Год · −25%') // 1 − 900 / 1200
    })

    it('shows a plain «Year» when it saves nothing', async () => {
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
        items: [
          { plan: 'pro', tier: null, period: 'month', prices: { XTR: 100 }, list_prices: { XTR: 100 } },
          { plan: 'pro', tier: null, period: 'year', prices: { XTR: 1200 }, list_prices: { XTR: 1200 } },
        ],
        providers: ['telegram_stars'],
      }))
      const w = await render()

      expect(w.get('[data-testid=period-year]').text()).toBe('Год')
    })

    it('does not count a one-time discount of the month as a saving of the year', async () => {
      vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
        items: [
          { plan: 'pro', tier: null, period: 'month', prices: { XTR: 105 }, list_prices: { XTR: 150 } },
          { plan: 'pro', tier: null, period: 'year', prices: { XTR: 1300 }, list_prices: { XTR: 1300 } },
        ],
        providers: ['telegram_stars'],
        offer: { kind: 'post_org_discount', discount_pct: 30, period: 'month', expires_at: '2026-11-02T12:00:00+00:00' },
      }))
      const w = await render()

      expect(w.get('[data-testid=period-year]').text()).toBe('Год · −28%') // 1 − 1300 / (12 × 150), not 12 × 105
    })

    it('is a labelled group of two radios that is disabled while an order is being paid', async () => {
      vi.useFakeTimers()
      vi.mocked(api.billing.checkout).mockResolvedValue({
        order: { id: 1, token: 'tok', status: 'pending', amount: 1, currency: 'UZS', expires_at: '2026-10-20T00:00:00+00:00' },
        checkout: { url: 'https://my.click.uz/pay', kind: 'redirect' },
      })
      vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
      const w = await render()

      expect(w.get('[role=radiogroup]').attributes('aria-label')).toBe('Срок оплаты')
      expect(w.findAll('[role=radio]')).toHaveLength(2)
      expect(w.get('[data-testid=period-month]').attributes('disabled')).toBeUndefined()

      await w.get('[data-testid=pay-click]').trigger('click')
      await vi.advanceTimersByTimeAsync(0)
      await flushPromises()

      expect(w.get('[data-testid=period-month]').attributes('disabled')).toBeDefined()
      expect(w.get('[data-testid=period-year]').attributes('disabled')).toBeDefined()
    })
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
    expect(w.get('[data-testid=purchase-offer]').classes()).toContain('chip-warning')
    expect(payPrice(w, 'telegram_stars')).toBe('105 ⭐')
    expect(w.get('[data-testid=list-price]').text()).toBe('150 ⭐')
    expect(w.get('[data-testid=list-price]').element.tagName).toBe('S')
    expect(w.find('[data-testid=period-month]').exists()).toBe(false) // a single period needs no tabs
  })

  describe('the chip of the offer belongs to the period the offer covers', () => {
    const withOffer = (period: 'month' | 'year' | null) => catalog({
      items: [
        { plan: 'pro', tier: null, period: 'month', prices: { XTR: period === 'year' ? 150 : 105 }, list_prices: { XTR: 150 } },
        { plan: 'pro', tier: null, period: 'year', prices: { XTR: period === 'month' ? 1300 : 910 }, list_prices: { XTR: 1300 } },
      ],
      providers: ['telegram_stars'],
      offer: { kind: 'post_org_discount', discount_pct: 30, period, expires_at: '2026-11-02T12:00:00+00:00' },
    })

    it('shows the chip next to the month the offer is about, and takes it away on the year', async () => {
      vi.mocked(api.billing.catalog).mockResolvedValue(withOffer('month'))
      const w = await render()

      expect(w.get('[data-testid=purchase-offer]').text()).toContain('Скидка 30% на первый платёж')
      expect(payPrice(w, 'telegram_stars')).toBe('105 ⭐')
      expect(w.get('[data-testid=list-price]').text()).toBe('150 ⭐')

      await w.get('[data-testid=period-year]').trigger('click')
      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(false) // a year at the list price must not wear the month's chip
      expect(payPrice(w, 'telegram_stars')).toBe('1 300 ⭐')
      expect(w.find('[data-testid=list-price]').exists()).toBe(false)

      await w.get('[data-testid=period-month]').trigger('click')
      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(true)
    })

    it('shows it on the year only when the offer is about the year', async () => {
      vi.mocked(api.billing.catalog).mockResolvedValue(withOffer('year'))
      const w = await render()
      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(false) // the month is selected first

      await w.get('[data-testid=period-year]').trigger('click')
      expect(w.get('[data-testid=purchase-offer]').text()).toContain('Скидка 30%')
      expect(payPrice(w, 'telegram_stars')).toBe('910 ⭐')
    })

    it('shows it on every period when the offer covers any of them', async () => {
      vi.mocked(api.billing.catalog).mockResolvedValue(withOffer(null))
      const w = await render()
      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(true)

      await w.get('[data-testid=period-year]').trigger('click')
      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(true)
    })

    it('shows no chip without an offer on either period', async () => {
      const w = await render()

      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(false)
      await w.get('[data-testid=period-year]').trigger('click')
      expect(w.find('[data-testid=purchase-offer]').exists()).toBe(false)
    })
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

    const error = w.get('[data-testid=purchase-error]')
    expect(error.text()).toBe('Этот способ оплаты сейчас недоступен. Выберите другой.')
    expect(error.attributes('role')).toBe('alert')
    expect(error.classes()).toContain('text-danger-ink')
    expect(error.find('svg').exists()).toBe(true)
    expect(w.get('[data-testid=pay-payme]').attributes('disabled')).toBeUndefined()
  })

  describe('status lines', () => {
    const redirect = {
      order: { id: 1, token: 'tok', status: 'pending', amount: 1, currency: 'UZS', expires_at: '2026-10-20T00:00:00+00:00' },
      checkout: { url: 'https://my.click.uz/pay', kind: 'redirect' },
    } as const

    it('shows the creation of the invoice as a status line with a spinning icon', async () => {
      vi.mocked(api.billing.checkout).mockReturnValue(new Promise(() => {}))
      const w = await render()

      await w.get('[data-testid=pay-click]').trigger('click')
      await flushPromises()

      const line = w.get('[data-testid=purchase-starting]')
      expect(line.text()).toBe('Создаём счёт…')
      expect(line.attributes('role')).toBe('status')
      expect(line.get('svg').classes()).toContain('animate-spin')
      expect(w.get('[data-testid=pay-click]').attributes('disabled')).toBeDefined()
    })

    it('shows the wait for the confirmation with a clock', async () => {
      vi.useFakeTimers()
      vi.mocked(api.billing.checkout).mockResolvedValue(redirect)
      vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
      const w = await render()

      await w.get('[data-testid=pay-click]').trigger('click')
      await vi.advanceTimersByTimeAsync(0)
      await flushPromises()

      const line = w.get('[data-testid=purchase-awaiting]')
      expect(line.attributes('role')).toBe('status')
      expect(line.find('svg').exists()).toBe(true)
    })

    it('says the order was replaced with an info icon and keeps the pay buttons', async () => {
      vi.mocked(api.billing.checkout).mockResolvedValue(redirect)
      vi.mocked(api.billing.order).mockResolvedValue({ ...order('canceled'), reason: 'replaced', replaced_by: null })
      const w = await render()

      await w.get('[data-testid=pay-click]').trigger('click')
      await flushPromises()

      const line = w.get('[data-testid=purchase-replaced]')
      expect(line.text()).toBe('Заказ заменён новым заказом.')
      expect(line.find('svg').exists()).toBe(true)
      expect(w.get('[data-testid=pay-click]').attributes('disabled')).toBeUndefined()
    })

    it('after two minutes of waiting offers to check again with a tinted button, and finishes when the order is paid', async () => {
      vi.useFakeTimers()
      vi.mocked(api.billing.checkout).mockResolvedValue(redirect)
      vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
      const w = await render()

      await w.get('[data-testid=pay-click]').trigger('click')
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * POLL_ATTEMPTS)
      await flushPromises()

      const timeout = w.get('[data-testid=purchase-timeout]')
      expect(timeout.text()).toContain('Подтверждение ещё не пришло')
      expect(timeout.find('svg').exists()).toBe(true)
      const check = w.get('[data-testid=purchase-check]')
      expect(check.text()).toBe('Проверить ещё раз')
      expect(check.classes()).toEqual(expect.arrayContaining(['btn-tinted', 'btn-block']))

      vi.mocked(api.billing.order).mockResolvedValue(order('paid'))
      vi.mocked(api.me.get).mockResolvedValue(me('purchase', 'pro'))
      await check.trigger('click')
      await vi.advanceTimersByTimeAsync(0)
      await flushPromises()

      expect(w.find('[data-testid=purchase-timeout]').exists()).toBe(false)
      expect(w.get('[data-testid=paywall-paid]').text()).toMatch(/^Спасибо! Pro активен до .*2026/)
    })
  })

  it('explains offer_busy at checkout in Russian and in Uzbek', async () => {
    vi.mocked(api.billing.checkout).mockRejectedValue(new ApiClientError('conflict', 409, 'offer_busy', 'English'))
    const w = await render()

    await w.get('[data-testid=pay-payme]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid=purchase-error]').text()).toBe('Предыдущая оплата со скидкой ещё обрабатывается — попробуйте через минуту.')

    setLocale('uz')
    await w.get('[data-testid=pay-payme]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid=purchase-error]').text()).toBe("Chegirmali oldingi to'lov hali qayta ishlanmoqda — bir daqiqadan so'ng urinib ko'ring.")
  })

  it('shows a replaced order as information, not as an error, and follows the new order', async () => {
    vi.useFakeTimers()
    vi.mocked(api.billing.checkout).mockResolvedValue({
      order: { id: 1, token: 'old', status: 'pending', amount: 1, currency: 'UZS', expires_at: '2026-10-20T00:00:00+00:00' },
      checkout: { url: 'https://my.click.uz/pay', kind: 'redirect' },
    })
    vi.mocked(api.billing.order)
      .mockResolvedValueOnce({ ...order('canceled'), reason: 'replaced', replaced_by: 'new' })
      .mockResolvedValue(order('pending'))
    const w = await render()

    await w.get('[data-testid=pay-click]').trigger('click')
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()

    expect(w.find('[data-testid=purchase-error]').exists()).toBe(false)
    expect(w.get('[data-testid=purchase-notice]').text()).toBe('Заказ заменён новым — ждём оплату по нему.')
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(api.billing.order).toHaveBeenLastCalledWith('new')
  })

  it('does not sell while the Stars auto-renewal is running', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      subscription: { status: 'active', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-12-01T00:00:00+00:00', auto_renew: true, can_cancel: true, can_resume: false, perpetual: false },
    }))
    const w = await render()

    expect(w.find('[data-testid=pay-telegram_stars]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-method]').exists()).toBe(false)
    expect(w.get('[data-testid=purchase-renewing]').text()).toContain('Автопродление Stars включено')
    expect(w.get('[data-testid=purchase-renewing]').text()).toContain('«Профиль → Подписка»')
    expect(w.get('[data-testid=purchase-renewing]').find('svg').exists()).toBe(true)
  })

  it('does not sell while a Stars renewal is in grace: the server refuses every payment, so the sheet says what to do instead', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      subscription: { status: 'past_due', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-11-03T12:00:00+00:00', auto_renew: false, can_cancel: false, can_resume: false, perpetual: false },
    }))
    const w = await render()

    expect(w.find('[data-testid=pay-telegram_stars]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-payme]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-method]').exists()).toBe(false)
    const grace = w.get('[data-testid=purchase-grace]')
    expect(grace.text()).toBe(
      'Telegram не смог списать Stars за продление. Пополните баланс Stars — Telegram повторит списание. Pro действует до 3 ноября 2026 г.',
    )
    expect(grace.attributes('role')).toBe('status')
    expect(grace.classes()).toContain('text-warning-ink')
    expect(grace.find('svg').exists()).toBe(true)
  })

  it('says the grace in Uzbek and keeps the other buttons of the sheet', async () => {
    setLocale('uz')
    useTodayStore().data = { due_count: 5 } as never
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      subscription: { status: 'past_due', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-11-03T12:00:00+00:00', auto_renew: false, can_cancel: false, can_resume: false, perpetual: false },
    }))
    const w = await render()

    expect(w.get('[data-testid=purchase-grace]').text()).toContain("Stars balansini to'ldiring")
    expect(w.find('[data-testid=pay-payme]').exists()).toBe(false)
    expect(w.find('[data-testid=paywall-review]').exists()).toBe(true) // reviews stay free of a payment
    expect(w.find('[data-testid=paywall-close]').exists()).toBe(true)
  })

  it('still sells over a lapsed or canceled period of another provider', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      subscription: { status: 'canceled', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-12-01T00:00:00+00:00', auto_renew: false, can_cancel: false, can_resume: true, perpetual: false },
    }))
    const w = await render()

    expect(w.find('[data-testid=purchase-grace]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-payme]').exists()).toBe(true)
  })

  it('does not sell on top of a perpetual Pro', async () => {
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog({
      subscription: { status: 'active', provider: 'manual', plan: 'pro', ends_at: null, auto_renew: false, can_cancel: false, can_resume: false, perpetual: true },
    }))
    const w = await render()

    expect(w.find('[data-testid=pay-telegram_stars]').exists()).toBe(false)
    expect(w.find('[data-testid=pay-method]').exists()).toBe(false)
    expect(w.get('[data-testid=purchase-perpetual]').text()).toContain('без ограничения по сроку')
    expect(w.get('[data-testid=purchase-perpetual]').find('svg').exists()).toBe(true)
  })

  it('explains the exhausted quota with the purchase lead', async () => {
    const w = await render({ reason: 'quota' })

    expect(w.get('[data-testid=paywall-lead]').text()).toBe(
      'Лимит новых слов на сегодня исчерпан — повторения доступны без ограничений. Новые слова без дневного лимита, упражнения на написание, свои колоды, заморозки серии и пробные тесты.',
    )
  })

  it('promises nothing that does not exist: no «statistics» in either language', async () => {
    for (const locale of ['ru', 'uz'] as const) {
      setActivePinia(createPinia())
      setLocale(locale)
      const w = await render({ reason: 'quota' })

      expect(w.text().toLowerCase(), locale).not.toMatch(/статистик|statistik/)
    }
  })

  describe('repeat words', () => {
    it('is a tinted button next to the pay buttons, so the purchase stays the one primary action', async () => {
      useTodayStore().data = { due_count: 5 } as never
      const w = await render({ reason: 'quota' })

      const review = w.get('[data-testid=paywall-review]')
      expect(review.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
      expect(review.classes()).not.toContain('btn-primary')
    })

    it('is the primary action while nothing is for sale yet', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me('coming_soon'))
      useTodayStore().data = { due_count: 5 } as never
      const w = await render({ reason: 'quota' })

      expect(w.get('[data-testid=paywall-review]').classes()).toContain('btn-primary')
    })
  })

  describe('layout', () => {
    it('has a grabber sheet with a close button that has a name, besides the «Understood» button', async () => {
      const w = await render()

      const sheet = w.get('[data-testid=paywall]')
      expect(sheet.classes()).toContain('sheet')
      expect(sheet.attributes('role')).toBe('dialog')

      const x = w.get('[data-testid=paywall-x]')
      expect(x.attributes('aria-label')).toBe('Закрыть')
      expect(x.find('svg').exists()).toBe(true)
      expect(w.get('[data-testid=paywall-close]').text()).toBe('Понятно')
      expect(w.get('[data-testid=paywall-close]').classes()).toContain('btn-plain')

      await x.trigger('click')
      expect(w.emitted('close')).toHaveLength(1)
    })

    it('shows the benefits of Pro in a compact list while there is something to read, and not once Pro is on', async () => {
      const w = await render()
      expect(w.find('[data-testid=pro-benefits]').exists()).toBe(true)

      vi.useFakeTimers()
      tg.openInvoice.mockResolvedValue('paid')
      vi.mocked(api.billing.checkout).mockResolvedValue({
        order: { id: 1, token: 'tok', status: 'pending', amount: 150, currency: 'XTR', expires_at: '2026-10-20T00:00:00+00:00' },
        checkout: { url: 'https://t.me/$inv', kind: 'invoice_link' },
      })
      vi.mocked(api.billing.order).mockResolvedValue(order('paid'))
      vi.mocked(api.me.get).mockResolvedValue(me('purchase', 'pro'))
      await w.get('[data-testid=pay-telegram_stars]').trigger('click')
      await vi.advanceTimersByTimeAsync(0)
      await flushPromises()

      expect(w.find('[data-testid=pro-benefits]').exists()).toBe(false)
      expect(w.get('[data-testid=paywall-paid]').find('svg').exists()).toBe(true)
    })

    it('lets a long support contact wrap by words, not by letters', async () => {
      vi.mocked(api.me.get).mockResolvedValue({ ...me(), paywall: { mode: 'purchase', contact: 'https://lexiflow.uz/support/centers/tashkent' } })
      const w = await render()

      const link = w.get('[data-testid=paywall-contact-link]')
      expect(link.classes()).toContain('break-words')
      expect(link.classes()).not.toContain('break-all')
    })

    it('keeps the contact for centers under the pay buttons', async () => {
      const w = await render()

      expect(w.get('[data-testid=paywall-contact]').text()).toContain('Учитесь в центре?')
      expect(w.get('[data-testid=paywall-contact-link]').attributes('href')).toBe('https://t.me/lexiflow_support')
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')
    expect([payName(w, 'payme'), payPrice(w, 'payme')]).toEqual(['Payme', "29 000 so'm"])
    expect(plain(w.get('[data-testid=pay-payme]').attributes('aria-label') ?? '')).toBe("To'lash: Payme · 29 000 so'm")
    expect(payName(w, 'telegram_stars')).toBe('Telegram Stars')
    expect(w.get('[data-testid=period-year]').text()).toBe('Yil · −28%')
    expect(w.get('[data-testid=pay-method]').text()).toBe("To'lov usuli")
    expect(w.get('[role=radiogroup]').attributes('aria-label')).toBe("To'lov muddati")
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
