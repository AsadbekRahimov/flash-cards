import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import BillingView from '../BillingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { BillingCatalog, MeResponse, Renewal, SubscriptionSummary } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

const state = vi.hoisted(() => ({ ensure: vi.fn(), back: undefined as undefined | (() => void), hideBack: vi.fn() }))

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn(), cancel: vi.fn(), resume: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    openInvoice: vi.fn(),
    openLink: vi.fn(),
    showBackButton: (handler: () => void) => {
      state.back = handler
      return state.hideBack
    },
  }),
}))

interface Setup {
  plan?: 'free' | 'pro'
  source?: string
  until?: string | null
  renewal?: Renewal | null
  mode?: 'coming_soon' | 'purchase'
}

const me = ({ plan = 'free', source, until = null, renewal = null, mode = 'purchase' }: Setup = {}): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: source ?? (plan === 'pro' ? 'subscription' : 'free'), until, new_words_per_day: plan === 'pro' ? null : 20, renewal },
  paywall: { mode, contact: null },
  settings: settingsFixture(),
})

const summary = (overrides: Partial<SubscriptionSummary> = {}): SubscriptionSummary => ({
  status: 'active', provider: 'telegram_stars', plan: 'pro', ends_at: '2026-12-01T00:00:00+00:00', auto_renew: true, can_cancel: true, can_resume: false, perpetual: false, ...overrides,
})

const catalog = (subscription: SubscriptionSummary | null = null, items = true): BillingCatalog => ({
  items: items ? [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 150 }, list_prices: { XTR: 150 } }] : [],
  providers: ['telegram_stars'],
  offer: null,
  subscription,
})

const offerCatalog = (): BillingCatalog => ({
  items: [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 105, UZS: 2_030_000 }, list_prices: { XTR: 150, UZS: 2_900_000 } }],
  providers: ['telegram_stars', 'payme'],
  offer: { kind: 'post_org_discount', discount_pct: 30, period: 'month', expires_at: '2026-11-02T12:00:00+00:00' },
  subscription: null,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: BillingView },
    ],
  })

async function render(response: MeResponse, cat: BillingCatalog) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  vi.mocked(api.billing.catalog).mockResolvedValue(cat)
  const router = makeRouter()
  await router.push('/billing')
  await router.isReady()
  const wrapper = mount(BillingView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

const renewing: Renewal = { auto: true, provider: 'telegram_stars', ends_at: '2026-12-01T00:00:00+00:00', reminder_sent: false }

describe('BillingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.today.get).mockResolvedValue({} as never)
  })
  afterEach(() => setLocale('ru'))

  it('shows a Free learner the plan and the buy button', async () => {
    const { wrapper } = await render(me(), catalog())

    expect(wrapper.get('[data-testid=plan-name]').text()).toBe('Тариф Free')
    expect(wrapper.get('[data-testid=plan-free]').text()).toContain('20 новых слов в день')
    expect(wrapper.get('[data-testid=buy]').text()).toBe('Купить Pro')
    expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
  })

  it('says payment is not open yet while nothing is for sale', async () => {
    const { wrapper } = await render(me({ mode: 'coming_soon' }), catalog(null, false))

    expect(wrapper.find('[data-testid=buy]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=unavailable]').text()).toBe('Оплата скоро откроется.')
  })

  it('shows Pro with its end, the provider and the running auto-renewal with a cancel button only', async () => {
    const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

    expect(wrapper.get('[data-testid=plan-name]').text()).toBe('LexiFlow Pro')
    expect(wrapper.get('[data-testid=plan-until]').text()).toMatch(/^Действует до .*2026/)
    expect(wrapper.get('[data-testid=plan-provider]').text()).toBe('Оплата: ⭐ Stars.')
    expect(wrapper.get('[data-testid=renewal-state]').text()).toBe('Автопродление включено: следующий период Telegram спишет сам.')
    expect(wrapper.find('[data-testid=resume-renewal]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=buy]').exists()).toBe(false) // it renews by itself: nothing to pay
  })

  it('cancels the renewal, then offers to resume it and to renew by paying', async () => {
    const canceledRenewal: Renewal = { ...renewing, auto: false }
    vi.mocked(api.billing.cancel).mockResolvedValue(me({ plan: 'pro', until: renewing.ends_at, renewal: canceledRenewal }))
    const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog(summary({ status: 'canceled', auto_renew: false, can_cancel: false, can_resume: true })))

    await wrapper.get('[data-testid=cancel-renewal]').trigger('click')
    await flushPromises()

    expect(api.billing.cancel).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid=renewal-state]').text()).toBe('Автопродление отключено — Pro действует до конца оплаченного периода.')
    expect(wrapper.find('[data-testid=cancel-renewal]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=resume-renewal]').text()).toBe('Включить автопродление')
    expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')
  })

  it('resumes the renewal', async () => {
    vi.mocked(api.billing.resume).mockResolvedValue(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }))
    const { wrapper } = await render(
      me({ plan: 'pro', until: renewing.ends_at, renewal: { ...renewing, auto: false } }),
      catalog(summary({ status: 'canceled', auto_renew: false, can_cancel: false, can_resume: true })),
    )
    vi.mocked(api.billing.catalog).mockResolvedValue(catalog(summary()))

    await wrapper.get('[data-testid=resume-renewal]').trigger('click')
    await flushPromises()

    expect(api.billing.resume).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=cancel-renewal]').exists()).toBe(true)
  })

  it('shows the reason when cancel is refused and keeps the button', async () => {
    vi.mocked(api.billing.cancel).mockRejectedValue(new ApiClientError('server', 502, 'provider_unavailable', 'English'))
    const { wrapper } = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), catalog(summary()))

    await wrapper.get('[data-testid=cancel-renewal]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=renewal-error]').text()).toBe('Платёжный сервис не отвечает. Попробуйте через несколько минут.')
    expect(wrapper.find('[data-testid=cancel-renewal]').exists()).toBe(true)
  })

  it('does not offer to cancel a Payme period or a promo grant, only to renew', async () => {
    const { wrapper } = await render(
      me({ plan: 'pro', until: '2026-11-20T00:00:00+00:00', renewal: { auto: false, provider: 'payme', ends_at: '2026-11-20T00:00:00+00:00', reminder_sent: false } }),
      catalog(summary({ provider: 'payme', auto_renew: false, can_cancel: false, can_resume: false })),
    )

    expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=plan-provider]').text()).toBe('Оплата: Payme.')
    expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')
  })

  it('shows no buy button for a perpetual Pro grant', async () => {
    const { wrapper } = await render(
      me({ plan: 'pro', until: null }),
      catalog(summary({ provider: 'manual', ends_at: null, auto_renew: false, can_cancel: false, can_resume: false, perpetual: true })),
    )

    expect(wrapper.find('[data-testid=buy]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
  })

  it('says Pro comes from the center when it is sponsored', async () => {
    const { wrapper } = await render(me({ plan: 'pro', source: 'sponsor:organization:3', until: null }), catalog())

    expect(wrapper.get('[data-testid=plan-sponsored]').text()).toBe('Pro подключён вашим центром.')
    expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Без ограничения по сроку.')
  })

  it('opens the purchase paywall from the buy button', async () => {
    const { wrapper } = await render(me(), catalog())

    await wrapper.get('[data-testid=buy]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=paywall-title]').text()).toBe('LexiFlow Pro')

    await wrapper.get('[data-testid=paywall-close]').trigger('click')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('shows "Pro ended" to a Free learner whose subscription lapsed: the banner and the button both say renew', async () => {
    const lapsedMe = me()
    lapsedMe.entitlement.lapsed = { provider: 'payme', ended_at: '2026-10-18T09:30:00+00:00' }
    window.localStorage.clear()
    const { wrapper } = await render(lapsedMe, catalog())

    expect(wrapper.get('[data-testid=lapsed-title]').text()).toBe('Pro закончился')
    expect(wrapper.get('[data-testid=buy]').text()).toBe('Продлить Pro')

    await wrapper.get('[data-testid=lapsed-renew]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(true)
  })

  it('has no banner without a lapse and none for a Pro learner', async () => {
    window.localStorage.clear()
    const free = await render(me(), catalog())
    expect(free.wrapper.find('[data-testid=lapsed-banner]').exists()).toBe(false)

    const proMe = me({ plan: 'pro', until: renewing.ends_at, renewal: renewing })
    proMe.entitlement.lapsed = { provider: 'payme', ended_at: '2026-10-18T09:30:00+00:00' }
    const pro = await render(proMe, catalog(summary()))
    expect(pro.wrapper.find('[data-testid=lapsed-banner]').exists()).toBe(false)
  })

  it('takes the Telegram back button to the settings and hides it on leave', async () => {
    const { wrapper, router } = await render(me(), catalog())

    state.back?.()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('settings')

    wrapper.unmount()
    expect(state.hideBack).toHaveBeenCalled()
  })

  it('offers a retry when the learner cannot be authenticated', async () => {
    state.ensure.mockRejectedValueOnce(new ApiClientError('network', 0, 'x', 'x'))
    const { wrapper } = await render(me(), catalog())

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)

    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=plan-card]').exists()).toBe(true)
  })

  it('shows the discount: the offer text and the new price next to the struck-out list price per provider', async () => {
    const { wrapper } = await render(me(), offerCatalog())

    expect(wrapper.get('[data-testid=offer-text]').text()).toMatch(/^Скидка 30% на первый платёж — до .*2026/)
    expect(wrapper.get('[data-testid=offer-price-telegram_stars]').text()).toContain('105 ⭐')
    expect(wrapper.get('[data-testid=offer-price-telegram_stars] s').text()).toBe('150 ⭐')
    expect(wrapper.get('[data-testid=offer-price-payme] s').text()).toContain('29')
    expect(wrapper.get('[data-testid=offer-price-payme] strong').text()).toContain('20')
  })

  it('shows no discount block without an offer, nor while the renewal runs', async () => {
    const plain = await render(me(), catalog())
    expect(plain.wrapper.find('[data-testid=offer-card]').exists()).toBe(false)

    const withSub = offerCatalog()
    withSub.subscription = summary()
    const renewingView = await render(me({ plan: 'pro', until: renewing.ends_at, renewal: renewing }), withSub)
    expect(renewingView.wrapper.find('[data-testid=offer-card]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(me(), catalog())

    expect(wrapper.get('h1').text()).toBe('Obuna')
    expect(wrapper.get('[data-testid=buy]').text()).toBe("Pro'ni sotib olish")
  })
})
