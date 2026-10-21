import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { POLL_ATTEMPTS, POLL_INTERVAL_MS, useBillingStore } from '../billing'
import type { BillingCatalog, CheckoutResponse, MeResponse, OrderStatus, OrderStatusResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

const tg = vi.hoisted(() => ({ openInvoice: vi.fn(), openLink: vi.fn() }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => tg }))
vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn(), cancel: vi.fn(), resume: vi.fn() },
  },
}))

const catalog = (overrides: Partial<BillingCatalog> = {}): BillingCatalog => ({
  items: [
    { plan: 'pro', tier: null, period: 'month', prices: { XTR: 150, UZS: 2_900_000 }, list_prices: { XTR: 150, UZS: 2_900_000 } },
  ],
  providers: ['telegram_stars', 'payme'],
  offer: null,
  subscription: null,
  ...overrides,
})

const me = (plan: 'free' | 'pro' = 'free', mode: 'coming_soon' | 'purchase' = 'purchase'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: {
    plan,
    source: plan === 'pro' ? 'subscription' : 'free',
    until: plan === 'pro' ? '2026-12-01T00:00:00+00:00' : null,
    new_words_per_day: plan === 'pro' ? null : 20,
    renewal: plan === 'pro' ? { auto: true, provider: 'telegram_stars', ends_at: '2026-12-01T00:00:00+00:00', reminder_sent: false } : null,
  },
  paywall: { mode, contact: null },
  settings: settingsFixture(),
})

const created = (kind: 'invoice_link' | 'redirect' = 'invoice_link'): CheckoutResponse => ({
  order: { id: 7, token: 'tok7', status: 'pending', amount: 150, currency: 'XTR', expires_at: '2026-10-20T00:00:00+00:00' },
  checkout: { url: 'https://t.me/$inv', kind },
})

const order = (status: OrderStatus): OrderStatusResponse => ({ status, paid_at: status === 'paid' ? '2026-10-19T00:00:00+00:00' : null, reason: null, replaced_by: null, subscription: null })

describe('billing store: catalog and purchase', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers()
    vi.mocked(api.me.get).mockResolvedValue(me('pro'))
    vi.mocked(api.today.get).mockResolvedValue({} as never)
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog())
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
  })

  it('can purchase only when the server says purchase and the catalog has something to sell', async () => {
    const billing = useBillingStore()
    billing.apply(me('free', 'purchase'))
    expect(billing.canPurchase).toBe(false) // no catalog yet

    await billing.loadCatalog()
    expect(billing.canPurchase).toBe(true)

    billing.apply(me('free', 'coming_soon'))
    expect(billing.canPurchase).toBe(false)

    billing.apply(me('free', 'purchase'))
    billing.catalog = catalog({ items: [] })
    expect(billing.canPurchase).toBe(false)
  })

  it('keeps going without a catalog when it fails to load', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    vi.mocked(api.billing.catalog).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))
    const billing = useBillingStore()

    await billing.loadCatalog()

    expect(billing.catalog).toBeNull()
    expect(billing.catalogLoading).toBe(false)
    expect(warn).toHaveBeenCalled()
    warn.mockRestore()
  })

  it('buys with Stars: creates the order, opens the invoice, then waits for the webhook and refreshes /me', async () => {
    tg.openInvoice.mockResolvedValue('paid')
    vi.mocked(api.billing.checkout).mockResolvedValue(created('invoice_link'))
    vi.mocked(api.billing.order).mockResolvedValueOnce(order('pending')).mockResolvedValueOnce(order('paid'))
    const billing = useBillingStore()

    const done = billing.buy('month', 'telegram_stars')
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await done

    expect(api.billing.checkout).toHaveBeenCalledWith('month', 'telegram_stars')
    expect(tg.openInvoice).toHaveBeenCalledWith('https://t.me/$inv')
    expect(tg.openLink).not.toHaveBeenCalled()
    expect(api.billing.order).toHaveBeenCalledTimes(2)
    expect(billing.purchase.status).toBe('paid')
    expect(billing.entitlement?.plan).toBe('pro')
    expect(api.me.get).toHaveBeenCalled()
    expect(api.today.get).toHaveBeenCalled()
  })

  it('does not take the invoice callback for a confirmation: even `paid` waits for the order', async () => {
    tg.openInvoice.mockResolvedValue('paid')
    vi.mocked(api.billing.checkout).mockResolvedValue(created())
    vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
    const billing = useBillingStore()

    const done = billing.buy('month', 'telegram_stars')
    await vi.advanceTimersByTimeAsync(0)

    expect(billing.purchase.status).toBe('awaiting')
    billing.resetPurchase()
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await done
    expect(billing.purchase.status).toBe('idle')
  })

  it('buys with Payme: opens the payment page and polls no more often than every 3 seconds', async () => {
    vi.mocked(api.billing.checkout).mockResolvedValue(created('redirect'))
    vi.mocked(api.billing.order)
      .mockResolvedValueOnce(order('pending'))
      .mockResolvedValueOnce(order('pending'))
      .mockResolvedValueOnce(order('paid'))
    const billing = useBillingStore()

    const done = billing.buy('month', 'payme')
    await vi.advanceTimersByTimeAsync(0)
    expect(tg.openLink).toHaveBeenCalledWith('https://t.me/$inv')
    expect(tg.openInvoice).not.toHaveBeenCalled()
    expect(api.billing.order).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS - 1)
    expect(api.billing.order).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1)
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await done

    expect(api.billing.order).toHaveBeenCalledTimes(3)
    expect(billing.purchase.status).toBe('paid')
  })

  it('goes back to idle when the invoice is closed without paying', async () => {
    tg.openInvoice.mockResolvedValue('cancelled')
    vi.mocked(api.billing.checkout).mockResolvedValue(created())
    const billing = useBillingStore()

    await billing.buy('month', 'telegram_stars')

    expect(billing.purchase.status).toBe('idle')
    expect(api.billing.order).not.toHaveBeenCalled()
  })

  it('fails when Telegram cannot open the invoice', async () => {
    tg.openInvoice.mockResolvedValue('failed')
    vi.mocked(api.billing.checkout).mockResolvedValue(created())
    const billing = useBillingStore()

    await billing.buy('month', 'telegram_stars')

    expect(billing.purchase.status).toBe('failed')
    expect(billing.purchase.error).toBe('Платёж не прошёл или счёт закрыт. Попробуйте ещё раз.')
  })

  it('explains a refused checkout by its error code, in the current language', async () => {
    vi.mocked(api.billing.checkout).mockRejectedValue(new ApiClientError('conflict', 409, 'checkout_conflict', 'English'))
    const billing = useBillingStore()

    await billing.buy('month', 'payme')

    expect(billing.purchase.status).toBe('failed')
    expect(billing.purchase.error).toBe('У вас включено автопродление Stars. Отключите его в «Подписке», прежде чем оформлять новую оплату.')

    setLocale('uz')
    vi.mocked(api.billing.checkout).mockRejectedValue(new ApiClientError('rate_limited', 429, 'too_many_pending', 'English'))
    await billing.buy('month', 'payme')
    expect(billing.purchase.error).toBe("Sizda to'lanmagan hisoblar bor. Ulardan birini to'lang yoki kuting — ular o'zi yopiladi.")
  })

  it('fails on a dead order and survives a network blip while polling', async () => {
    vi.mocked(api.billing.checkout).mockResolvedValue(created('redirect'))
    vi.mocked(api.billing.order)
      .mockRejectedValueOnce(new ApiClientError('network', 0, 'x', 'x'))
      .mockResolvedValueOnce(order('expired'))
    const billing = useBillingStore()

    const done = billing.buy('month', 'payme')
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await done

    expect(api.billing.order).toHaveBeenCalledTimes(2)
    expect(billing.purchase.status).toBe('failed')
  })

  it('follows a replaced order to the new one and finishes when that is paid', async () => {
    vi.mocked(api.billing.checkout).mockResolvedValue(created('redirect'))
    vi.mocked(api.billing.order)
      .mockResolvedValueOnce({ ...order('canceled'), reason: 'replaced', replaced_by: 'tok8' })
      .mockResolvedValueOnce(order('paid'))
    const billing = useBillingStore()

    const done = billing.buy('month', 'payme')
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await done

    expect(vi.mocked(api.billing.order).mock.calls.map((c) => c[0])).toEqual(['tok7', 'tok8'])
    expect(billing.purchase.status).toBe('paid')
  })

  it('ends in the informational `replaced` state when the successor is unknown (not failed)', async () => {
    vi.mocked(api.billing.checkout).mockResolvedValue(created('redirect'))
    vi.mocked(api.billing.order).mockResolvedValue({ ...order('canceled'), reason: 'replaced', replaced_by: null })
    const billing = useBillingStore()

    await billing.buy('month', 'payme')

    expect(billing.purchase.status).toBe('replaced')
    expect(billing.purchase.error).toBe('')
    expect(billing.purchase.notice).toBe('Заказ заменён новым заказом.')
    expect(api.billing.order).toHaveBeenCalledTimes(1)
  })

  it('still fails on a plainly canceled order', async () => {
    vi.mocked(api.billing.checkout).mockResolvedValue(created('redirect'))
    vi.mocked(api.billing.order).mockResolvedValue(order('canceled'))
    const billing = useBillingStore()

    await billing.buy('month', 'payme')

    expect(billing.purchase.status).toBe('failed')
  })

  it('times out after two minutes and can check again', async () => {
    vi.mocked(api.billing.checkout).mockResolvedValue(created('redirect'))
    vi.mocked(api.billing.order).mockResolvedValue(order('pending'))
    const billing = useBillingStore()

    const done = billing.buy('month', 'payme')
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * POLL_ATTEMPTS)
    await done

    expect(billing.purchase.status).toBe('timeout')
    expect(api.billing.order).toHaveBeenCalledTimes(POLL_ATTEMPTS)

    vi.mocked(api.billing.order).mockResolvedValue(order('paid'))
    await billing.waitForPayment()
    expect(billing.purchase.status).toBe('paid')
  })

  it('ignores a second tap while the order is being created', async () => {
    let finish: (value: CheckoutResponse) => void = () => {}
    vi.mocked(api.billing.checkout).mockReturnValue(new Promise((resolve) => { finish = resolve }))
    tg.openInvoice.mockResolvedValue('cancelled')
    const billing = useBillingStore()

    const first = billing.buy('month', 'telegram_stars')
    await billing.buy('month', 'telegram_stars')
    finish(created())
    await first

    expect(api.billing.checkout).toHaveBeenCalledTimes(1)
  })

  it('cancels and resumes the renewal from the server answer and refreshes the catalog', async () => {
    const canceled = me('pro')
    canceled.entitlement.renewal = { auto: false, provider: 'telegram_stars', ends_at: '2026-12-01T00:00:00+00:00', reminder_sent: false }
    vi.mocked(api.billing.cancel).mockResolvedValue(canceled)
    vi.mocked(api.billing.resume).mockResolvedValue(me('pro'))
    const billing = useBillingStore()

    await billing.cancelRenewal()
    expect(billing.entitlement?.renewal?.auto).toBe(false)
    expect(api.billing.catalog).toHaveBeenCalledTimes(1)

    await billing.resumeRenewal()
    expect(billing.entitlement?.renewal?.auto).toBe(true)
    expect(billing.acting).toBe(false)
    expect(billing.actionError).toBe('')
  })

  it('shows why cancel was refused and keeps the state', async () => {
    vi.mocked(api.billing.cancel).mockRejectedValue(new ApiClientError('conflict', 409, 'not_cancelable', 'English'))
    const billing = useBillingStore()
    billing.apply(me('pro'))

    await billing.cancelRenewal()

    expect(billing.actionError).toBe('Автопродление сейчас нечего отключать.')
    expect(billing.entitlement?.renewal?.auto).toBe(true)
    expect(billing.acting).toBe(false)
  })
})
