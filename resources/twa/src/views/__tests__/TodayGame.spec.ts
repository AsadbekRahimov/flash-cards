import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { TodayResponse } from '@/api/types'

/** The entry of Karvon on "Today" (docs/05 §5b.4): by `/today.game`, never by `/me`; "the streak is held by a run". */

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn() } } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 23,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 120, total: 900 }, next_lesson: null },
  open_session_id: null,
  entitlement: { plan: 'pro', source: 'subscription', until: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
  ...overrides,
})

async function render(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/billing', name: 'billing', component: stub },
      { path: '/league', name: 'league', component: stub },
      { path: '/game/daily', name: 'game-daily', component: stub },
      { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
    ],
  })
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  setLocale('ru')
  vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
})

describe('TodayView: the entry of Karvon', () => {
  it('says "the caravan of the day is waiting" and leads to it, without asking /me (a Pro learner reads no /me on Today)', async () => {
    const { wrapper, router } = await render(today({ game: { daily_available: true, active_run: null } }))

    expect(wrapper.get('[data-testid=game-entry-text]').text()).toBe('Караван дня ждёт')
    expect(wrapper.get('[data-testid=game-entry]').attributes('data-kind')).toBe('daily')
    expect(wrapper.find('[data-testid=game-entry-progress]').exists()).toBe(false)

    await wrapper.get('[data-testid=game-entry]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game/daily')
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('a run that is on wins: "Continue the run" with its progress, to that run', async () => {
    const { wrapper, router } = await render(
      today({ game: { daily_available: true, active_run: { id: 9, kind: 'stop', progress: { answered: 3, total: 9 } } } }),
    )

    expect(wrapper.get('[data-testid=game-entry-text]').text()).toBe('Продолжить забег')
    expect(wrapper.get('[data-testid=game-entry-progress]').text()).toBe('3 из 9')
    await wrapper.get('[data-testid=game-entry]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game/runs/9')
  })

  it('has no entry while the game is closed (the server says nothing to do) or when there is no game block', async () => {
    expect((await render(today({ game: { daily_available: false, active_run: null } }))).wrapper.find('[data-testid=game-entry]').exists()).toBe(false)
    expect((await render(today())).wrapper.find('[data-testid=game-entry]').exists()).toBe(false)
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('tells "the streak is held by a run" when the run held the day, and the usual words otherwise', async () => {
    const held = (by: 'goal' | 'game' | 'freeze' | null) =>
      today({ streak: { current: 6, best: 6, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false, held_by: by } })

    const byGame = (await render(held('game'))).wrapper
    expect(byGame.get('[data-testid=streak-held-by-game]').text()).toBe('Серия удержана забегом')
    expect(byGame.find('[data-testid=streak-safe]').exists()).toBe(false)

    for (const by of ['goal', 'freeze', null] as const) {
      const other = (await render(held(by))).wrapper
      expect(other.find('[data-testid=streak-held-by-game]').exists(), String(by)).toBe(false)
      expect(other.get('[data-testid=streak-safe]').text()).toBe('На сегодня серия продлена')
    }
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(today({ game: { daily_available: true, active_run: null } }))
    expect(wrapper.get('[data-testid=game-entry-text]').text()).toBe('Bugungi karvon safarini boshlang')
  })
})
