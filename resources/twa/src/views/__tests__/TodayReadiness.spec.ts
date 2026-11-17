import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import type { TodayReadiness, TodayResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() },
    me: { get: vi.fn() }, exam: { openLevelQuiz: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const block = (overrides: Partial<TodayReadiness> = {}): TodayReadiness => ({
  level_label: 'A1–A2',
  percent: 42,
  words: { total: 100, learned: 42, mastered: 10, in_progress: 18, not_started: 40 },
  quiz: { enabled: true, week_start: '2026-11-16', this_week: null },
  ...overrides,
})

const today = (readiness: TodayReadiness | null | undefined, learned = 42): TodayResponse => ({
  due_count: 3,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
  active_course: {
    id: 3, slug: 'english-a1-a2', title: 'English A1-A2', progress: { learned, total: 100 },
    next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' },
    ...(readiness === undefined ? {} : { readiness }),
  },
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
})

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/courses/:slug/readiness', name: 'readiness', component: { template: '<div />' } },
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('TodayView readiness block', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
    } as never)
  })
  afterEach(() => setLocale('ru'))

  it('shows the share of the level, the legend and a link to the screen, with no extra request', async () => {
    const { wrapper, router } = await render(today(block()))

    expect(wrapper.get('[data-testid=readiness-title]').text()).toBe('Освоено 42% слов уровня A1–A2')
    expect(wrapper.get('[data-testid=readiness-legend]').text()).toContain('18 в работе')
    expect(wrapper.get('[data-testid=readiness-bar]').attributes('aria-valuenow')).toBe('42')
    expect(wrapper.get('[data-testid=readiness-seg-learned]').attributes('style')).toContain('width: 42%')
    expect(api.today.get).toHaveBeenCalledTimes(1)

    await wrapper.get('[data-testid=readiness-link]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('readiness')
    expect(router.currentRoute.value.params.slug).toBe('english-a1-a2')
    expect(router.currentRoute.value.query.source).toBe('today')
  })

  it('is absent for null and for an older server without the field', async () => {
    expect((await render(today(null))).wrapper.find('[data-testid=readiness-block]').exists()).toBe(false)
    expect((await render(today(undefined))).wrapper.find('[data-testid=readiness-block]').exists()).toBe(false)
  })

  it('is absent for a newcomer with no progress', async () => {
    const data = today(block({ percent: 0, words: { total: 100, learned: 0, mastered: 0, in_progress: 0, not_started: 100 } }), 0)
    data.due_count = 0
    data.goal = { target: 20, done: 0, met: false }
    data.streak = { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false }

    expect((await render(data)).wrapper.find('[data-testid=readiness-block]').exists()).toBe(false)
  })

  it('shows the result of the test of the week instead of the button', async () => {
    const { wrapper } = await render(today(block({ quiz: { enabled: true, week_start: '2026-11-16', this_week: { correct: 8, total: 12, percent: 66 } } })))

    expect(wrapper.get('[data-testid=readiness-quiz-result]').text()).toBe('Тест недели: 8 из 12')
    expect(wrapper.find('[data-testid=readiness-quiz-btn]').exists()).toBe(false)
  })

  it('has no test line when the quiz is off', async () => {
    const { wrapper } = await render(today(block({ quiz: { enabled: false } })))

    expect(wrapper.find('[data-testid=readiness-quiz-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=readiness-quiz-result]').exists()).toBe(false)
  })

  it('leads from the test button to the readiness screen without starting the test', async () => {
    const { wrapper, router } = await render(today(block()))

    await wrapper.get('[data-testid=readiness-quiz-btn]').trigger('click')
    await flushPromises()

    expect(api.exam.openLevelQuiz).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('readiness')
    expect(router.currentRoute.value.params.slug).toBe('english-a1-a2')
    expect(router.currentRoute.value.query.source).toBe('today')
    expect(router.currentRoute.value.query.focus).toBe('quiz')
  })

  it('names the course instead of a level when there is no label, in Uzbek too', async () => {
    setLocale('uz')
    const { wrapper } = await render(today(block({ level_label: null })))

    expect(wrapper.get('[data-testid=readiness-title]').text()).toBe("Kurs so'zlarining 42% o'zlashtirildi")
  })
})
