import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import PaywallSheet from '../PaywallSheet.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ openInvoice: vi.fn(), openLink: vi.fn() }) }))
vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn(), checkout: vi.fn(), order: vi.fn() },
  },
}))

const me = (plan: 'free' | 'pro' = 'free'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: null, new_words_per_day: plan === 'pro' ? null : 20, renewal: null },
  paywall: { mode: 'purchase', contact: null },
  settings: settingsFixture(),
})

async function render(plan: 'free' | 'pro' = 'free', at = '/today') {
  vi.mocked(api.me.get).mockResolvedValue(me(plan))
  vi.mocked(api.today.get).mockResolvedValue({ new_words_remaining: null, due_count: 0 } as never)
  vi.mocked(api.billing.catalog).mockResolvedValue({
    items: [{ plan: 'pro', tier: null, period: 'month', prices: { XTR: 150 }, list_prices: { XTR: 150 } }],
    providers: ['telegram_stars'],
    offer: null,
    subscription: null,
  })
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/billing', name: 'billing', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/referrals', name: 'referrals', component: stub },
    ],
  })
  await router.push(at)
  await router.isReady()
  const wrapper = mount(PaywallSheet, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('PaywallSheet: Pro for free', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('has a text link under the pay buttons', async () => {
    const { wrapper } = await render()

    const link = wrapper.get('[data-testid=invite-link]')
    expect(link.text()).toBe('Или получите Pro бесплатно — пригласите друзей')
    const pay = wrapper.get('[data-testid=pay-telegram_stars]').element
    expect(Boolean(pay.compareDocumentPosition(link.element) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true)
  })

  it('closes the sheet and opens the referrals screen, remembering Today as the way back', async () => {
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid=invite-link]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('referrals')
    expect(router.currentRoute.value.query.from).toBe('today')
  })

  it('leaves the way back to the Profile from a screen that is not Today or Billing', async () => {
    const { wrapper, router } = await render('free', '/courses')

    await wrapper.get('[data-testid=invite-link]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('referrals')
    expect(router.currentRoute.value.query.from).toBeUndefined()
  })

  it('is not offered to a Pro learner who comes to extend', async () => {
    const { wrapper } = await render('pro')

    expect(wrapper.find('[data-testid=invite-link]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=invite-link]').text()).toBe("Yoki Pro'ni bepul oling — do'stlarni taklif qiling")
  })
})
