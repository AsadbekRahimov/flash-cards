import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { resetSeenSession } from '@/composables/seenFlag'
import { trialEndedKey } from '@/composables/trialEnded'
import type { Lapsed, MeResponse, TodayResponse } from '@/api/types'

/*
 * The one-off sheet "Trial Pro is over" (docs/05 §8c-1, docs/34 §2в P7): the first Today after the welcome trial ended opens the paywall
 * `trial_ended` by itself, once per `ended_at` (`localStorage` `trial_ended_seen:<ended_at>`); a paid Pro that lapsed, a learner on Pro
 * and a learner without a lapse get nothing.
 */

vi.mock('@/api', () => ({
  api: {
    today: { get: vi.fn() },
    assignments: { list: vi.fn() },
    training: { open: vi.fn() },
    me: { get: vi.fn() },
    promo: { redeem: vi.fn() },
    billing: { catalog: vi.fn(), offerViewed: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

const ENDED = '2026-11-20T07:00:00+00:00'

const today = (lapsed: Lapsed | null, plan: 'free' | 'pro' = 'free'): TodayResponse => ({
  due_count: 3,
  new_words_remaining: 10,
  goal: { target: 10, done: 2, met: false },
  streak: { current: 3, best: 5, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
  active_course: null,
  open_session_id: null,
  entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: null, lapsed },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
})

const me = (): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 40, words_due_today: 3 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 10 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: settingsFixture({ daily_goal: 10, options: { ...settingsFixture().options, daily_goal_max: 10 } }),
})

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })
  await router.push('/today')
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('TodayView: the sheet of the ended trial', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    // A learner who has studied: the first-run intro is not in the way.
    window.localStorage.setItem('lexiflow:intro-seen', '1')
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    vi.mocked(api.me.get).mockResolvedValue(me())
  })
  afterEach(() => {
    window.localStorage.clear()
  })

  it('opens the paywall trial_ended by itself at the first Today after the end, with the line about the goal', async () => {
    const w = await render(today({ provider: 'trial', ended_at: ENDED }))

    const sheet = w.get('[data-testid=paywall]')
    expect(sheet.attributes('aria-label')).toBe('Пробный Pro закончился')
    expect(sheet.text()).toContain('Цель дня теперь 10 карточек — это максимум на Free.')
    expect(window.localStorage.getItem(trialEndedKey(ENDED))).toBe('1')
    // The banner stays as it was.
    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
  })

  it('opens it once per ended_at: not on the next Today, nor after a reload of the app', async () => {
    const first = await render(today({ provider: 'trial', ended_at: ENDED }))
    await first.get('[data-testid=paywall-x]').trigger('click')
    first.unmount()

    const second = await render(today({ provider: 'trial', ended_at: ENDED }))
    expect(second.find('[data-testid=paywall]').exists()).toBe(false)
    second.unmount()

    // A new app run: the memory of the session is gone, the device remembers.
    resetSeenSession()
    setActivePinia(createPinia())
    const third = await render(today({ provider: 'trial', ended_at: ENDED }))
    expect(third.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('opens it again for another end (a later trial would be another ended_at)', async () => {
    window.localStorage.setItem(trialEndedKey('2026-10-01T00:00:00+00:00'), '1')

    const w = await render(today({ provider: 'trial', ended_at: ENDED }))

    expect(w.find('[data-testid=paywall]').exists()).toBe(true)
  })

  it('stays closed for a paid Pro that lapsed, for no lapse and for a learner on Pro', async () => {
    for (const response of [today({ provider: 'payme', ended_at: ENDED }), today(null), today({ provider: 'trial', ended_at: ENDED }, 'pro')]) {
      const w = await render(response)
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
      w.unmount()
    }
    expect(window.localStorage.getItem(trialEndedKey(ENDED))).toBeNull()
  })
})
