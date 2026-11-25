import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import type { ScoreToday, ScoreTodayCount, TodayResponse } from '@/api/types'

/*
 * "Today N of 100" of the word points v2 (docs/32 §8.2, docs/04 A.2a `score_today`): a card in the style of the goal of the day, the
 * numbers and the cap are the server's; without the key (an older server, the rules off) Home is as it was.
 */

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn() } } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure, learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const score = (overrides: Partial<ScoreToday> = {}): ScoreToday => ({
  points: 40,
  cap: 100,
  category: 'free',
  new: [6, 20],
  review: [14, 20],
  remaining: 60,
  // 00:00 in Tashkent
  day_ends_at: '2026-11-25T19:00:00+00:00',
  month: null,
  ...overrides,
})

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

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
      { path: '/game', name: 'game', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
  await flushPromises()
  return w
}

describe('TodayView — "Today N of 100" (docs/32 §8.2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
    } as never)
  })
  afterEach(() => setLocale('ru'))

  it('shows nothing without `score_today` (an older server, the rules off): Home as before', async () => {
    const w = await render(today())

    expect(w.find('[data-testid=score-today]').exists()).toBe(false)
    expect(w.find('[data-testid=review-card]').exists()).toBe(true)
  })

  it('shows the points of the day out of the cap of the API, the table and what is left, under the goal', async () => {
    const w = await render(today({ score_today: score() }))

    const card = w.get('[data-testid=score-today]')
    expect(card.classes()).toContain('card')
    expect(card.text()).toContain('Баллы сегодня')
    expect(w.get('[data-testid=score-today-count]').text()).toBe('40 / 100 баллов')
    expect(w.get('[data-testid=score-today-category]').text()).toBe('Free')
    expect(w.get('[data-testid=score-today-aside]').text()).toBe('ещё 60 баллов')
    expect(w.get('[data-testid=score-today-bar] .progress-fill').attributes('style')).toContain('width: 40%')
    expect(w.find('[data-testid=score-today-capped]').exists()).toBe(false)
    expect(w.find('[data-testid=score-today-month]').exists()).toBe(false)
    // under the card of the goal of the day
    const html = w.html()
    expect(html.indexOf('data-testid="review-card"')).toBeLessThan(html.indexOf('data-testid="score-today"'))
    // one `/today`, no extra request for the points
    expect(api.today.get).toHaveBeenCalledTimes(1)
  })

  it('takes the cap from the API (Pro 150), and the plural of «балл»', async () => {
    const w = await render(today({ score_today: score({ points: 1, cap: 150, category: 'pro', remaining: 149 }) }))

    expect(w.get('[data-testid=score-today-count]').text()).toBe('1 / 150 баллов')
    expect(w.get('[data-testid=score-today-category]').text()).toBe('Pro')
    expect(w.get('[data-testid=score-today-aside]').text()).toBe('ещё 149 баллов')
  })

  it('at the cap says honestly that no more points come today, the words are still learned, and when the next rating day begins', async () => {
    const w = await render(today({ score_today: score({ points: 100, remaining: 0 }) }))

    expect(w.get('[data-testid=score-today-aside]').text()).toBe('Максимум на сегодня')
    expect(w.get('[data-testid=score-today-aside]').classes()).toContain('text-success-ink')
    expect(w.get('[data-testid=score-today-capped]').text()).toBe('На сегодня баллов больше не будет — слова всё равно учатся. Новые баллы — с 00:00')
    expect(w.get('[data-testid=score-today-bar] .progress-fill').attributes('style')).toContain('width: 100%')
  })

  describe('a full week with a month of its own (a Free week, a Pro month)', () => {
    const month = (overrides: Partial<ScoreTodayCount> = {}): ScoreTodayCount => ({
      points: 100, cap: 150, category: 'pro', new: [20, null], review: [20, null], remaining: 50, ...overrides,
    })

    it('the month still takes points: not "no more points today", but the maximum to the week and what the month has left', async () => {
      const w = await render(today({ score_today: score({ points: 100, remaining: 0, month: month() }) }))

      expect(w.get('[data-testid=score-today-aside]').text()).toBe('Максимум на сегодня')
      expect(w.find('[data-testid=score-today-capped]').exists()).toBe(false)
      expect(w.get('[data-testid=score-today-week-capped]').text()).toBe('В недельный рейтинг на сегодня максимум, в рейтинг месяца ещё 50 баллов')
      expect(w.get('[data-testid=score-today-month]').text()).toBe('В рейтинг месяца: 100 баллов')
    })

    it('a month without a cap of its own (the old rules) still takes points: said without a number', async () => {
      const w = await render(today({ score_today: score({ points: 100, remaining: 0, month: month({ cap: null, remaining: null }) }) }))

      expect(w.find('[data-testid=score-today-capped]').exists()).toBe(false)
      expect(w.get('[data-testid=score-today-week-capped]').text()).toBe('В недельный рейтинг на сегодня максимум, в рейтинг месяца баллы ещё идут')
    })

    it('both counts full: the day is over', async () => {
      const w = await render(today({ score_today: score({ points: 100, remaining: 0, month: month({ points: 150, remaining: 0 }) }) }))

      expect(w.find('[data-testid=score-today-week-capped]').exists()).toBe(false)
      expect(w.get('[data-testid=score-today-capped]').text()).toBe('На сегодня баллов больше не будет — слова всё равно учатся. Новые баллы — с 00:00')
    })

    it('the week not full yet: nothing of the cap, whatever the month', async () => {
      const w = await render(today({ score_today: score({ month: month({ points: 150, remaining: 0 }) }) }))

      expect(w.find('[data-testid=score-today-week-capped]').exists()).toBe(false)
      expect(w.find('[data-testid=score-today-capped]').exists()).toBe(false)
    })

    it('in Uzbek', async () => {
      setLocale('uz')
      const w = await render(today({ score_today: score({ points: 100, remaining: 0, month: month() }) }))

      expect(w.get('[data-testid=score-today-week-capped]').text()).toBe('Haftalik reytingga bugun maksimum, oylik reytingga yana 50 ball')
    })
  })

  it('says the monthly count only when it differs from the weekly one', async () => {
    const month = { points: 55, cap: 100, category: 'free' as const, new: [6, 20] as [number, number], review: [14, 20] as [number, number], remaining: 45 }
    const w = await render(today({ score_today: score({ month }) }))

    expect(w.get('[data-testid=score-today-month]').text()).toBe('В рейтинг месяца: 55 баллов')
  })

  it('a day on the old rules (no cap): the points alone, no line', async () => {
    const w = await render(today({ score_today: score({ cap: null, remaining: null, points: 12 }) }))

    expect(w.get('[data-testid=score-today-count]').text()).toBe('12 баллов')
    expect(w.find('[data-testid=score-today-bar]').exists()).toBe(false)
    expect(w.find('[data-testid=score-today-aside]').exists()).toBe(false)
  })

  it('in Uzbek', async () => {
    setLocale('uz')
    const w = await render(today({ score_today: score({ points: 100, remaining: 0 }) }))

    expect(w.get('[data-testid=score-today]').text()).toContain('Bugungi ballar')
    expect(w.get('[data-testid=score-today-count]').text()).toBe('100 / 100 ball')
    expect(w.get('[data-testid=score-today-aside]').text()).toBe('Bugungi maksimum')
    expect(w.get('[data-testid=score-today-capped]').text()).toContain("so'zlar baribir o'rganiladi")
  })
})
