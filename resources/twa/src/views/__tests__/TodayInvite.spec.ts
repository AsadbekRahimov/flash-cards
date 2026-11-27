import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { Renewal, TodayResponse } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn(), updateSettings: vi.fn() } } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure, learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const NOW = new Date('2026-11-16T12:00:00+00:00')
const DAY = 86_400_000
const KEY = 'lexiflow:invite-card-hidden'

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 23,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 120, total: 900 }, next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' } },
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
  ...overrides,
})

/** The first visit: no course, nothing to review, no assignment. */
const startState = (overrides: Partial<TodayResponse> = {}): TodayResponse => today({ active_course: null, due_count: 0, ...overrides })

const trialEntitlement = (endsInMs: number) => {
  const ends = new Date(NOW.getTime() + endsInMs).toISOString()
  return { plan: 'pro', source: 'subscription', until: ends } as const
}

function meWith(renewal: Renewal | null): void {
  vi.mocked(api.me.get).mockResolvedValue({
    learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
    groups: [],
    stats: { words_learned: 0, words_due_today: 0 },
    entitlement: { plan: renewal ? 'pro' : 'free', source: renewal ? 'subscription' : 'free', until: renewal?.ends_at ?? null, new_words_per_day: null, renewal, lapsed: null },
    paywall: { mode: 'purchase', contact: null },
    settings: settingsFixture(),
  } as never)
}

const trial = (endsInMs: number): Renewal => ({ auto: false, provider: 'trial', ends_at: new Date(NOW.getTime() + endsInMs).toISOString(), reminder_sent: false })

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
      { path: '/settings', name: 'settings', component: stub },
      { path: '/league', name: 'league', component: stub },
    ],
  })
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

describe('TodayView: the invitation card', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    meWith(null)
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
  })

  it('invites a Free learner: a calm card with the text without numbers and "How it works", under the course block', async () => {
    const w = await render(today())

    expect(w.get('[data-testid=invite-text]').text()).toBe('Пригласите друзей — получите Pro бесплатно')
    expect(w.get('[data-testid=invite-more]').text()).toBe('Как это работает')
    // a card of the kit with the plate of a gift
    expect(w.find('[data-testid=invite-card] .icon-tile').exists()).toBe(true)
    expect(w.get('[data-testid=invite-card]').classes()).toContain('card')
    const course = w.get('[data-testid=course-card]').element
    expect(Boolean(course.compareDocumentPosition(w.get('[data-testid=invite-card]').element) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true)
  })

  it('leads to the referrals screen with Today as the way back', async () => {
    const w = await render(today())

    await w.get('[data-testid=invite-more]').trigger('click')
    await flushPromises()

    expect(w.router.currentRoute.value.name).toBe('referrals')
    expect(w.router.currentRoute.value.query.from).toBe('today')
  })

  it('is hidden by the cross for 14 days and comes back after them', async () => {
    const w = await render(today())

    await w.get('[data-testid=invite-dismiss]').trigger('click')
    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
    expect(Number(window.localStorage.getItem(KEY))).toBe(NOW.getTime() + 14 * DAY)

    resetSeenSession()
    vi.setSystemTime(new Date(NOW.getTime() + 13 * DAY))
    expect((await render(today())).find('[data-testid=invite-card]').exists()).toBe(false)

    resetSeenSession()
    vi.setSystemTime(new Date(NOW.getTime() + 15 * DAY))
    expect((await render(today())).find('[data-testid=invite-card]').exists()).toBe(true)
  })

  it('still hides for the visit when the storage is unavailable', async () => {
    const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied')
    })
    const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })
    const w = await render(today())

    await w.get('[data-testid=invite-dismiss]').trigger('click')

    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
    set.mockRestore()
    get.mockRestore()
  })

  it('is not shown on the start card', async () => {
    const w = await render(startState())

    expect(w.find('[data-testid=start-card]').exists()).toBe(true)
    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
  })

  it('is not shown next to the "Pro ended" banner', async () => {
    meWith(null)
    const w = await render(today({ entitlement: { plan: 'free', source: 'free', until: null, lapsed: { provider: 'trial', ended_at: '2026-11-15T10:00:00+00:00' } } }))

    expect(w.find('[data-testid=lapsed-banner]').exists()).toBe(true)
    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
  })

  it('is not shown to a Pro learner with no trial', async () => {
    const w = await render(today({ entitlement: { plan: 'pro', source: 'subscription', until: null } }))

    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = await render(today())

    expect(w.get('[data-testid=invite-text]').text()).toBe("Do'stlarni taklif qiling — Pro'ni bepul oling")
    expect(w.get('[data-testid=invite-more]').text()).toBe('Bu qanday ishlaydi')
  })

  it('opens the lapsed banner invite button to the referrals screen', async () => {
    const w = await render(today({ entitlement: { plan: 'free', source: 'free', until: null, lapsed: { provider: 'trial', ended_at: '2026-11-15T10:00:00+00:00' } } }))

    await w.get('[data-testid=lapsed-invite]').trigger('click')
    await flushPromises()

    expect(w.router.currentRoute.value.name).toBe('referrals')
    expect(w.router.currentRoute.value.query.from).toBe('today')
  })
})

describe('TodayView: the trial line', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
  })

  it('says how many days of the trial are left, rounded up', async () => {
    meWith(trial(6.2 * DAY))
    const w = await render(today({ entitlement: trialEntitlement(6.2 * DAY) }))

    expect(w.get('[data-testid=trial-line]').text()).toBe('У вас пробный Pro — ещё 7 дн.')
    expect(api.me.get).toHaveBeenCalled()
  })

  it('says "ends today" on the last day, never "0 days"', async () => {
    meWith(trial(0.4 * DAY))
    const w = await render(today({ entitlement: trialEntitlement(0.4 * DAY) }))

    expect(w.get('[data-testid=trial-line]').text()).toBe('Пробный Pro заканчивается сегодня')
  })

  it('leads to the subscription', async () => {
    meWith(trial(3 * DAY))
    const w = await render(today({ entitlement: trialEntitlement(3 * DAY) }))

    await w.get('[data-testid=trial-line]').trigger('click')
    await flushPromises()

    expect(w.router.currentRoute.value.name).toBe('billing')
  })

  it('is shown on the first visit too, under the start card', async () => {
    meWith(trial(7 * DAY))
    const w = await render(startState({ entitlement: trialEntitlement(7 * DAY) }))

    const start = w.get('[data-testid=start-card]').element
    const line = w.get('[data-testid=trial-line]')
    expect(Boolean(start.compareDocumentPosition(line.element) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true)
    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
  })

  it('goes with the invitation card (a trial learner can earn more days) but is a line, not a card', async () => {
    meWith(trial(3 * DAY))
    const w = await render(today({ entitlement: trialEntitlement(3 * DAY) }))

    expect(w.find('[data-testid=invite-card]').exists()).toBe(true)
    expect(w.get('[data-testid=trial-line]').classes()).not.toContain('card')
  })

  it('is not shown for a paid subscription', async () => {
    meWith({ auto: false, provider: 'payme', ends_at: new Date(NOW.getTime() + 20 * DAY).toISOString(), reminder_sent: false })
    const w = await render(today({ entitlement: trialEntitlement(20 * DAY) }))

    expect(w.find('[data-testid=trial-line]').exists()).toBe(false)
    expect(w.find('[data-testid=invite-card]').exists()).toBe(false)
  })

  it('is not shown for a Free learner, and a perpetual Pro does not ask /me', async () => {
    meWith(null)
    expect((await render(today())).find('[data-testid=trial-line]').exists()).toBe(false)

    vi.mocked(api.me.get).mockClear()
    await render(today({ entitlement: { plan: 'pro', source: 'subscription', until: null } }))
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    meWith(trial(3 * DAY))
    const w = await render(today({ entitlement: trialEntitlement(3 * DAY) }))

    expect(w.get('[data-testid=trial-line]').text()).toBe('Pro sinov muddati tugashiga 3 kun qoldi')

    setActivePinia(createPinia())
    meWith(trial(0.2 * DAY))
    const last = await render(today({ entitlement: trialEntitlement(0.2 * DAY) }))
    expect(last.get('[data-testid=trial-line]').text()).toBe('Pro sinov muddati bugun tugaydi')
  })
})
