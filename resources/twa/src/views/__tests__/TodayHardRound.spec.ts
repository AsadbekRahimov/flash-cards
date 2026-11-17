import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import type { HardRound, TodayResponse } from '@/api/types'

const state = vi.hoisted(() => ({ ensure: vi.fn() }))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn(), updateSettings: vi.fn() } } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure, learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const hardRound = (overrides: Partial<HardRound> = {}): HardRound => ({ available: true, reason: null, words: 10, open_session_id: null, resets_at: null, ...overrides })

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 23,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 120, total: 900 }, next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' } },
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null, lapsed: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
  practice: { available: true, pro_required: false, modes: ['pairs'], battle: false, pro_promise: 'none' },
  ...overrides,
})

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

describe('TodayView: the invitation to the round of hard words', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    state.ensure.mockResolvedValue(undefined)
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    vi.mocked(api.me.get).mockRejectedValue(new Error('not asked'))
  })
  afterEach(() => setLocale('ru'))

  it('invites with the count and opens the round with source=self', async () => {
    vi.mocked(api.training.open).mockResolvedValue({ session_id: 90, mode: 'hard_review', lesson: null, total_words: 10 })
    const w = await render(today({ hard_round: hardRound({ words: 10 }) }))

    expect(w.get('[data-testid=hard-round-words]').text()).toBe('10 трудных слов ждут разбора')
    expect(w.get('[data-testid=hard-round-btn]').text()).toBe('Разобрать')
    await w.get('[data-testid=hard-round-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'hard_review', source: 'self' })
    expect(w.router.currentRoute.value.fullPath).toBe('/training/90')
  })

  it('stands under the session card and above the games card', async () => {
    const w = await render(today({ hard_round: hardRound() }))
    const html = w.html()

    expect(html.indexOf('data-testid="review-card"')).toBeLessThan(html.indexOf('data-testid="hard-round-card"'))
    expect(html.indexOf('data-testid="hard-round-card"')).toBeLessThan(html.indexOf('data-testid="games-card"'))
  })

  it('says "Continue" for a round that is open', async () => {
    const w = await render(today({ hard_round: hardRound({ open_session_id: 33 }) }))

    expect(w.get('[data-testid=hard-round-btn]').text()).toBe('Продолжить')
  })

  it.each([
    ['played today', hardRound({ available: false, reason: 'played_today', resets_at: '2026-11-18T00:00:00Z' })],
    ['not enough', hardRound({ available: false, reason: 'not_enough', words: 2 })],
    ['no exercise', hardRound({ available: false, reason: 'no_objective_exercise' })],
    ['off', null],
  ])('shows nothing: %s', async (_name, value) => {
    expect((await render(today({ hard_round: value }))).find('[data-testid=hard-round-card]').exists()).toBe(false)
  })

  it('a refusal (played in another tab) is told under the session button and Today is read again', async () => {
    vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('conflict', 409, 'hard_round_unavailable', 'x', undefined, { reason: 'played_today', resets_at: null }))
    const w = await render(today({ hard_round: hardRound() }))
    vi.mocked(api.today.get).mockResolvedValue(today({ hard_round: hardRound({ available: false, reason: 'played_today' }) }))

    await w.get('[data-testid=hard-round-btn]').trigger('click')
    await flushPromises()

    expect(w.get('[data-testid=open-error]').text()).toContain('на сегодня уже пройден')
    expect(api.today.get).toHaveBeenCalledTimes(2)
    expect(w.find('[data-testid=hard-round-card]').exists()).toBe(false)
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = await render(today({ hard_round: hardRound({ words: 5 }) }))

    expect(w.get('[data-testid=hard-round-words]').text()).toBe("5 ta qiyin so'z tahlilni kutmoqda")
  })
})
