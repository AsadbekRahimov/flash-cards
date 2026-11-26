import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { resetSeenSession } from '@/composables/seenFlag'
import { resetTimingMarks, setTimingSampleRate, startTimingMarks, timingMarks, type ClientTimingPayload } from '@/composables/useTimingMarks'
import { ASSIGNMENTS_POSSIBLE_KEY } from '@/stores/assignments'
import type { AssignmentItem, TodayResponse } from '@/api/types'

/*
 * The timing marks of «Сегодня» (docs/04 A.21): `button_ready` - the first moment the primary action can be used (the start card,
 * "Start / Continue session", a lesson of the center), `first_tap` - the first tap on it. The marks never add or reorder a request
 * of the screen: the same `/today`, `/assignments` and `/me` calls as with no marks at all.
 */

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure, learner: null }) }))

vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const OPEN_ID = '00000006-0000-4000-8000-000000000006'
let clock = 0
const send = vi.fn<(payload: ClientTimingPayload) => Promise<unknown>>()

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 23,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  active_course: {
    id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 120, total: 900 },
    next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' },
  },
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
  ...overrides,
})

const assignment: AssignmentItem = {
  id: 7, title: 'Food', group: 'Group A', lesson_id: 41, stage: 2, lesson: 4, due_at: '2026-10-25T13:00:00+00:00', overdue: false,
  progress_pct: 40, completed: false, completed_at: null, late: false,
}

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
      { path: '/game', name: 'game', component: { template: '<div />' } },
      { path: '/game/daily', name: 'game-daily', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
  await flushPromises()
  return w
}

/** The calls the screen made, in order. */
function requests(): string[] {
  const calls = [
    ...vi.mocked(api.today.get).mock.invocationCallOrder.map((n) => [n, 'today'] as const),
    ...vi.mocked(api.assignments.list).mock.invocationCallOrder.map((n) => [n, 'assignments'] as const),
    ...vi.mocked(api.me.get).mock.invocationCallOrder.map((n) => [n, 'me'] as const),
    ...vi.mocked(api.training.open).mock.invocationCallOrder.map((n) => [n, 'open'] as const),
  ]
  return calls.sort((a, b) => a[0] - b[0]).map(([, name]) => name)
}

describe('TodayView — timing marks', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    resetTimingMarks()
    clock = 0
    send.mockReset()
    send.mockResolvedValue(undefined)
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    vi.mocked(api.training.open).mockResolvedValue({ session_id: 5 } as never)
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
    } as never)
  })

  afterEach(() => {
    resetTimingMarks()
  })

  const started = () => {
    startTimingMarks({ now: () => clock, openId: OPEN_ID, platform: 'android', cold: true, entry: 'home', send })
    setTimingSampleRate(100)
  }

  it('marks the session button when it is drawn, and the first tap on it; the requests stay the same', async () => {
    started()
    clock = 800
    const w = await render(today())
    expect(w.get('[data-testid=review-btn]').attributes('disabled')).toBeUndefined()
    expect(timingMarks()).toEqual({ today_received: 800, button_ready: 800 })

    clock = 2300
    await w.get('[data-testid=review-btn]').trigger('click')
    await flushPromises()
    clock = 3000
    await w.get('[data-testid=review-btn]').trigger('click')

    expect(timingMarks()).toEqual({ today_received: 800, button_ready: 800, first_tap: 2300 })
    expect(requests()).toEqual(['today', 'me', 'assignments', 'open', 'open'])
    // Nothing is sent from Today: the marks go after the first acknowledged answer or when the page is hidden.
    expect(send).not.toHaveBeenCalled()
  })

  it('makes the same requests, in the same order, with the marks on and off', async () => {
    const run = async (withMarks: boolean, response: TodayResponse) => {
      vi.clearAllMocks()
      resetTimingMarks()
      window.localStorage.clear()
      resetSeenSession()
      setActivePinia(createPinia())
      vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
      state.ensure.mockResolvedValue(undefined)
      if (withMarks) started()
      const w = await render(response)
      w.unmount()
      return requests()
    }

    for (const response of [
      today(),
      today({ assignments_possible: false }),
      today({ active_course: null, due_count: 0, assignments_possible: false }),
      today({ entitlement: { plan: 'pro', source: 'subscription', until: '2026-12-01T00:00:00+00:00' }, assignments_possible: true }),
    ]) {
      expect(await run(true, response)).toEqual(await run(false, response))
    }
  })

  it('marks the start card of a newcomer and a tap on "Choose a course"', async () => {
    started()
    clock = 650
    const w = await render(today({ active_course: null, due_count: 0, assignments_possible: false }))
    expect(w.find('[data-testid=start-card]').exists()).toBe(true)
    expect(timingMarks()).toEqual({ today_received: 650, button_ready: 650 })

    clock = 4000
    await w.get('[data-testid=start-choose-course]').trigger('click')
    expect(timingMarks()).toEqual({ today_received: 650, button_ready: 650, first_tap: 4000 })
  })

  it('waits for the assignments of a class newcomer, then marks the lesson of the center and its tap', async () => {
    started()
    let answer!: (r: { assignments: AssignmentItem[] }) => void
    vi.mocked(api.assignments.list).mockReturnValue(new Promise((resolve) => (answer = resolve)))
    clock = 700
    const w = await render(today({ active_course: null, due_count: 0, new_words_remaining: 0, assignments_possible: true }))
    // The screen is held back for the assignments: no button yet.
    expect(timingMarks()).toEqual({ today_received: 700 })

    clock = 1400
    answer({ assignments: [assignment] })
    await flushPromises()
    expect(timingMarks()).toEqual({ today_received: 700, button_ready: 1400 })

    clock = 2600
    await w.get('[data-testid=assignment-btn]').trigger('click')
    expect(timingMarks()).toMatchObject({ first_tap: 2600 })
  })

  it('has no button mark when all is done and there is no assignment', async () => {
    started()
    window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '0')
    const w = await render(today({ due_count: 0, new_words_remaining: 0, assignments_possible: false }))

    expect(w.find('[data-testid=all-done]').exists()).toBe(true)
    expect(timingMarks()).toEqual({ today_received: 0 })
  })

  it('marks the done card of the plan only when it has a button', async () => {
    const plan = {
      step: null, done: true,
      lesson: { done: true, lesson_id: 40, next_lesson_id: null, words_left: null },
      training: { lesson_id: 40, title: 'Food', words: 5, done: true, open_session_id: null },
      game: null,
    }
    const pro = { plan: 'pro', source: 'subscription', until: null } as const
    const finished = (overrides: Partial<TodayResponse> = {}) => {
      const response = today({ due_count: 0, entitlement: pro, assignments_possible: false, plan, ...overrides })
      response.active_course = { ...response.active_course!, next_lesson: null }
      return response
    }

    started()
    window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '0')
    const bare = await render(finished())
    expect(bare.get('[data-testid=lesson-hero]').attributes('data-step')).toBe('done')
    expect(bare.find('[data-testid=lesson-hero] button').exists()).toBe(false)
    expect(timingMarks()).toEqual({ today_received: 0 })
    bare.unmount()

    resetTimingMarks()
    setActivePinia(createPinia())
    started()
    clock = 500
    const due = await render(finished({ due_count: 3 }))
    expect(due.find('[data-testid=hero-review]').exists()).toBe(true)
    expect(timingMarks()).toEqual({ today_received: 500, button_ready: 500 })
  })

  it('takes no mark at all without a started open (the other specs of the screen)', async () => {
    const w = await render(today())
    await w.get('[data-testid=review-btn]').trigger('click')

    expect(timingMarks()).toEqual({})
  })
})
