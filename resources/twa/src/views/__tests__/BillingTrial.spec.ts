import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import BillingView from '../BillingView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { BillingCatalog, Lapsed, MeResponse, Renewal } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn(), cancel: vi.fn(), resume: vi.fn() },
    training: { open: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ openInvoice: vi.fn(), openLink: vi.fn(), showBackButton: () => vi.fn() }) }))

const TRIAL: Renewal = { auto: false, provider: 'trial', ends_at: '2026-11-23T12:00:00+00:00', reminder_sent: false }

const me = (plan: 'free' | 'pro', renewal: Renewal | null = null, lapsed: Lapsed | null = null): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: renewal?.ends_at ?? null, new_words_per_day: plan === 'pro' ? null : 20, renewal, lapsed },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
})

const catalog: BillingCatalog = {
  items: [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 150 }, list_prices: { XTR: 150 } }],
  providers: ['telegram_stars'],
  offer: null,
  subscription: null,
}

async function render(response: MeResponse) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  vi.mocked(api.billing.catalog).mockResolvedValue(catalog)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: stub },
      { path: '/billing', name: 'billing', component: BillingView },
      { path: '/referrals', name: 'referrals', component: stub },
    ],
  })
  await router.push('/billing')
  await router.isReady()
  const wrapper = mount(BillingView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('BillingView: the welcome trial and Pro for free', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('says "Trial period until ..." with no word on renewal or on who pays', async () => {
    const { wrapper } = await render(me('pro', TRIAL))

    expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Пробный период до 23 ноября 2026 г.')
    expect(wrapper.find('[data-testid=plan-provider]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=renewal-card]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=plan-chip]').text()).toBe('Pro')
  })

  it('keeps the usual line for a paid subscription', async () => {
    const paid: Renewal = { auto: false, provider: 'payme', ends_at: '2026-12-01T12:00:00+00:00', reminder_sent: false }
    const { wrapper } = await render(me('pro', paid))

    expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Действует до 1 декабря 2026 г.')
    expect(wrapper.get('[data-testid=plan-provider]').text()).toContain('Payme')
  })

  it('speaks Uzbek in the trial line', async () => {
    setLocale('uz')
    const { wrapper } = await render(me('pro', TRIAL))

    expect(wrapper.get('[data-testid=plan-until]').text()).toBe('Sinov davri 23-noyabr, 2026 gacha.')
  })

  it('offers "Or get Pro free - invite friends" to a Free learner and opens the referrals screen', async () => {
    const { wrapper, router } = await render(me('free'))

    expect(wrapper.get('[data-testid=invite-link]').text()).toBe('Или получите Pro бесплатно — пригласите друзей')
    await wrapper.get('[data-testid=invite-link]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('referrals')
    expect(router.currentRoute.value.query.from).toBe('billing')
  })

  it('offers it to a learner on the trial too (more days can be earned), not to a paid Pro', async () => {
    expect((await render(me('pro', TRIAL))).wrapper.find('[data-testid=invite-link]').exists()).toBe(true)
    const paid: Renewal = { auto: false, provider: 'payme', ends_at: '2026-12-01T12:00:00+00:00', reminder_sent: false }
    expect((await render(me('pro', paid))).wrapper.find('[data-testid=invite-link]').exists()).toBe(false)
  })

  it('shows the trial banner with an invite button once the trial is over, and the button opens the referrals screen', async () => {
    const { wrapper, router } = await render(me('free', null, { provider: 'trial', ended_at: '2026-11-20T12:00:00+00:00' }))

    expect(wrapper.get('[data-testid=lapsed-title]').text()).toBe('Пробный Pro закончился')
    await wrapper.get('[data-testid=lapsed-invite]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('referrals')
    expect(router.currentRoute.value.query.from).toBe('billing')
  })
})
