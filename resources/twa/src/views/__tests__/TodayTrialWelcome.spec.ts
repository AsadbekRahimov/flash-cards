import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import { trialWelcomeKey } from '@/composables/trialWelcome'
import type { Entitlement, Renewal, TodayResponse } from '@/api/types'

/*
 * The one-off sheet of the welcome trial (docs/36 §4б, W3): Today opens it by itself once per end of the trial (`localStorage`
 * `trial_welcome_seen:<ends_at>`), only for the learner's own trial; a paid Pro and a center's Pro never see it. The days and the
 * date come from the API, the list of what Pro gives is the paywall's (`ProBenefits`).
 */

vi.mock('@/api', () => ({
  api: {
    today: { get: vi.fn() },
    assignments: { list: vi.fn() },
    training: { open: vi.fn() },
    me: { get: vi.fn(), updateSettings: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const NOW = new Date('2026-11-25T07:00:00+00:00')
const DAY = 86_400_000
const ENDS = new Date(NOW.getTime() + 14 * DAY).toISOString()

const today = (entitlement: TodayResponse['entitlement']): TodayResponse => ({
  due_count: 3,
  new_words_remaining: null,
  goal: { target: 20, done: 2, met: false },
  streak: { current: 1, best: 1, met_today: false, at_risk: false, freezes: 1, frozen_yesterday: false },
  active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 10, total: 900 }, next_lesson: { id: 41, stage: 1, lesson: 2, title: 'Food' } },
  open_session_id: null,
  entitlement,
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
})

const OWN_PRO = { plan: 'pro', source: 'subscription', until: ENDS, lapsed: null } as const

function meWith(entitlement: Partial<Entitlement>, renewal: Renewal | null): void {
  vi.mocked(api.me.get).mockResolvedValue({
    learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
    groups: [],
    stats: { words_learned: 10, words_due_today: 3 },
    entitlement: { plan: 'pro', source: 'subscription', until: ENDS, new_words_per_day: null, renewal, lapsed: null, ...entitlement },
    paywall: { mode: 'purchase', contact: null },
    practice: { available: true, pro_required: false, modes: ['pairs'], battle: true, pro_promise: 'both', limits: null },
    settings: settingsFixture(),
  } as never)
}

const trial = (endsAt = ENDS): Renewal => ({ auto: false, provider: 'trial', ends_at: endsAt, reminder_sent: false })

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/billing', name: 'billing', component: stub },
      { path: '/referrals', name: 'referrals', component: stub },
      { path: '/league', name: 'league', component: stub },
      { path: '/settings', name: 'settings', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
    ],
  })
  await router.push('/today')
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

describe('TodayView: the sheet of the welcome trial', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    window.localStorage.setItem('lexiflow:intro-seen', '1')
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
  })
  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
    window.localStorage.clear()
    setLocale('ru')
  })

  it('opens by itself on the trial: the days and the date of the API, the badge of the brand, the benefits of the paywall', async () => {
    meWith({}, trial())
    const w = await render(today(OWN_PRO))

    const sheet = w.get('[data-testid=trial-welcome-sheet]')
    expect(sheet.attributes('aria-label')).toBe('Пробный Pro')
    expect(w.get('[data-testid=trial-welcome-badge]').text()).toBe('Muhrla Pro')
    expect(w.get('[data-testid=trial-welcome-title]').text()).toBe('Pro на 14 дней — до 9 декабря')
    expect(sheet.findAll('[data-testid=benefit]').map((b) => b.attributes('data-benefit'))).toEqual([
      'benefits.new_words',
      'benefits.freezes',
      'benefits.practice',
    ])
    expect(window.localStorage.getItem(trialWelcomeKey(ENDS))).toBe('1')
  })

  it('opens once per ends_at: not on the next Today, nor after a reload of the app; again for another end', async () => {
    meWith({}, trial())
    const first = await render(today(OWN_PRO))
    await first.get('[data-testid=trial-welcome-ok]').trigger('click')
    expect(first.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
    first.unmount()

    const second = await render(today(OWN_PRO))
    expect(second.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
    second.unmount()

    resetSeenSession()
    setActivePinia(createPinia())
    const third = await render(today(OWN_PRO))
    expect(third.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
    third.unmount()

    // a later trial (another end) is another welcome
    const later = new Date(NOW.getTime() + 20 * DAY).toISOString()
    setActivePinia(createPinia())
    meWith({ until: later }, trial(later))
    const fourth = await render(today({ ...OWN_PRO, until: later }))
    expect(fourth.find('[data-testid=trial-welcome-sheet]').exists()).toBe(true)
  })

  it('leads to the subscription screen from "About Pro"', async () => {
    meWith({}, trial())
    const w = await render(today(OWN_PRO))

    await w.get('[data-testid=trial-welcome-more]').trigger('click')
    await flushPromises()

    expect(w.router.currentRoute.value.name).toBe('billing')
    expect(w.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
  })

  it('is not shown to a paid Pro', async () => {
    meWith({}, { auto: true, provider: 'telegram_stars', ends_at: ENDS, reminder_sent: false })
    const w = await render(today(OWN_PRO))

    expect(w.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
    expect(window.localStorage.getItem(trialWelcomeKey(ENDS))).toBeNull()
  })

  it("is not shown to a center's Pro, even with a trial renewal in /me", async () => {
    meWith({ source: 'sponsor:organization:5' }, trial())
    const w = await render(today({ plan: 'pro', source: 'sponsor:organization:5', until: ENDS, lapsed: null }))

    expect(w.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('does not break the screen when the storage is not there, and is not shown twice in the session', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    meWith({}, trial())

    const first = await render(today(OWN_PRO))
    expect(first.find('[data-testid=today-view]').exists()).toBe(true)
    expect(first.find('[data-testid=trial-welcome-sheet]').exists()).toBe(true)
    first.unmount()

    const second = await render(today(OWN_PRO))
    expect(second.find('[data-testid=course-card]').exists()).toBe(true)
    expect(second.find('[data-testid=trial-welcome-sheet]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    meWith({}, trial())
    const w = await render(today(OWN_PRO))

    expect(w.get('[data-testid=trial-welcome-title]').text()).toMatch(/^14 kunlik Pro — .+ gacha$/)
  })
})
