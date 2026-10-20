import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import type { MeResponse, TodayResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

// The paywall entry point of the Today screen (task 1.4b); the rest of the screen is in TodayView.spec.ts.

vi.mock('@/api', () => ({
  api: { today: { get: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn() }, promo: { redeem: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 3,
  new_words_remaining: 12,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
  active_course: null,
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null },
  onboarding_completed: true,
  ...overrides,
})

const me = (plan: 'free' | 'pro', contact: string | null = null): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: null, new_words_per_day: plan === 'free' ? 20 : null },
  paywall: { mode: 'coming_soon', contact },
  settings: settingsFixture(),
})

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/decks', name: 'decks', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })
  await router.push('/today')
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('TodayView paywall entry', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.me.get).mockResolvedValue(me('free', '@lexiflow_help'))
  })

  it('offers the paywall to a Free learner and opens it on tap', async () => {
    const w = await render(today())

    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    await w.get('[data-testid=paywall-open]').trigger('click')
    await flushPromises()

    expect(w.get('[data-testid=paywall-lead]').text()).toBe('Pro скоро откроется.')
    expect(w.get('[data-testid=paywall-free]').text()).toContain('20 новых слов в день')
    expect(w.get('[data-testid=paywall-contact-link]').attributes('href')).toBe('https://t.me/lexiflow_help')
  })

  it('explains the exhausted quota when there are no new words left today', async () => {
    const w = await render(today({ new_words_remaining: 0 }))

    await w.get('[data-testid=paywall-open]').trigger('click')

    expect(w.get('[data-testid=paywall-lead]').text()).toContain('Лимит новых слов на сегодня исчерпан')
  })

  it('does not show the entry point to a Pro learner', async () => {
    const w = await render(today({ new_words_remaining: null, entitlement: { plan: 'pro', source: 'subscription', until: null } }))

    expect(w.find('[data-testid=paywall-open]').exists()).toBe(false)
  })

  it('refreshes the screen after a promo code: plan badge and quota follow the new plan', async () => {
    vi.mocked(api.promo.redeem).mockResolvedValue(me('pro'))
    const w = await render(today())
    expect(w.get('[data-testid=plan-badge]').text()).toBe('Free')

    vi.mocked(api.today.get).mockResolvedValue(
      today({ new_words_remaining: null, entitlement: { plan: 'pro', source: 'subscription', until: null } }),
    )
    await w.get('[data-testid=paywall-open]').trigger('click')
    await w.get('[data-testid=promo-toggle]').trigger('click')
    await w.get('[data-testid=promo-input]').setValue('PRO30')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(api.today.get).toHaveBeenCalledTimes(2)
    expect(w.get('[data-testid=plan-badge]').text()).toBe('Pro')
    expect(w.find('[data-testid=new-remaining]').exists()).toBe(false)
    expect(w.find('[data-testid=paywall-open]').exists()).toBe(false)
  })

  it('closes the sheet and stays on the Today screen', async () => {
    const w = await render(today())
    await w.get('[data-testid=paywall-open]').trigger('click')
    await w.get('[data-testid=paywall-close]').trigger('click')

    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    expect(w.find('[data-testid=review-btn]').exists()).toBe(true)
  })

  it('"Repeat words" in the paywall opens a daily review and leaves for the training screen', async () => {
    vi.mocked(api.training.open).mockResolvedValue({ session_id: 918, mode: 'daily_review', lesson: null, total_words: 3 })
    const w = await render(today({ new_words_remaining: 0 }))
    await w.get('[data-testid=paywall-open]').trigger('click')

    await w.get('[data-testid=paywall-review]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('does not offer "Repeat words" when nothing is due', async () => {
    const w = await render(today({ due_count: 0, new_words_remaining: 0 }))
    await w.get('[data-testid=paywall-open]').trigger('click')

    expect(w.find('[data-testid=paywall-review]').exists()).toBe(false)
  })
})
