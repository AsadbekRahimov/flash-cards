import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import GameMapView from '../GameMapView.vue'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useTodayStore } from '@/stores/today'
import { gameMap } from '@/api/__tests__/gameMapFixtures'
import type { TodayResponse } from '@/api/types'

/** The entry to the caravan of a class (docs/05 §5b.7): on the map (`map.class_caravan`) and on "Today" (`/today.game.class_caravan`). */

vi.mock('@/api', () => ({
  api: {
    game: { map: vi.fn(), startRun: vi.fn() },
    today: { get: vi.fn() },
    assignments: { list: vi.fn() },
    training: { open: vi.fn() },
    me: { get: vi.fn() },
    gameClass: { show: vi.fn(), list: vi.fn() },
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), learner: null }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))

const card = { id: 5, stars_total: 12, target_stars: 40, ends_at: '2026-11-06T13:00:00.000Z' }

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

const routes = () => {
  const stub = { template: '<div />' }
  return [
    { path: '/today', name: 'today', component: stub },
    { path: '/courses', name: 'courses', component: stub },
    { path: '/billing', name: 'billing', component: stub },
    { path: '/league', name: 'league', component: stub },
    { path: '/settings', name: 'settings', component: stub },
    { path: '/game', name: 'game', component: stub },
    { path: '/game/caravan', name: 'game-caravan', component: stub },
    { path: '/game/daily', name: 'game-daily', component: stub },
    { path: '/game/class/:id(\\d+)', name: 'game-class', component: stub },
    { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
  ]
}

async function renderToday(response: TodayResponse) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = createRouter({ history: createMemoryHistory(), routes: routes() })
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

async function renderMap(classCaravan: typeof card | null | undefined) {
  useTodayStore().data = today()
  const map = gameMap()
  if (classCaravan !== undefined) map.class_caravan = classCaravan
  vi.mocked(api.game.map).mockResolvedValue(map)
  const router = createRouter({ history: createMemoryHistory(), routes: routes() })
  await router.push('/game')
  const wrapper = mount(GameMapView, { props: { course: 'english-a1', stop: null }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  setLocale('ru')
  vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
  document.body.innerHTML = ''
})

describe('GameMapView: the card of the class caravan', () => {
  it('shows "Class caravan, stars N / M, until ..." and leads to the screen of the caravan, without another request', async () => {
    const { wrapper, router } = await renderMap(card)

    const entry = wrapper.get('[data-testid=class-caravan-card]')
    expect(entry.text()).toContain('Караван класса')
    expect(wrapper.get('[data-testid=class-caravan-text]').text()).toMatch(/^12 \/ 40 · до /)
    expect(wrapper.get('[data-testid=class-caravan-text]').text()).toContain('18:00')
    expect(entry.attributes('aria-label')).toContain('12 из 40')

    await entry.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game/class/5')
    expect(api.gameClass.show).not.toHaveBeenCalled()
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it.each([['null', null], ['absent (an older server)', undefined]] as const)('has no card when the field is %s', async (_name, value) => {
    const { wrapper } = await renderMap(value)

    expect(wrapper.find('[data-testid=class-caravan-card]').exists()).toBe(false)
  })
})

describe('TodayView: the card of the class caravan', () => {
  it('shows the card by /today.game.class_caravan and leads to the caravan, even with no caravan of the day', async () => {
    const { wrapper, router } = await renderToday(today({ game: { daily_available: false, active_run: null, class_caravan: card } }))

    expect(wrapper.get('[data-testid=class-caravan-text]').text()).toMatch(/^12 \/ 40 · до /)
    // 13:00 UTC is 18:00 in the zone of /today
    expect(wrapper.get('[data-testid=class-caravan-text]').text()).toContain('18:00')
    expect(wrapper.find('[data-testid=game-entry]').exists()).toBe(false)

    await wrapper.get('[data-testid=class-caravan-card]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game/class/5')
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('stands beside the entry of the caravan of the day', async () => {
    const { wrapper } = await renderToday(today({ game: { daily_available: true, active_run: null, class_caravan: card } }))

    expect(wrapper.find('[data-testid=class-caravan-card]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=game-entry]').exists()).toBe(true)
  })

  it.each([
    ['null', { daily_available: true, active_run: null, class_caravan: null }],
    ['absent', { daily_available: true, active_run: null }],
  ] as const)('has no card when the field is %s, and none without the game block', async (_name, game) => {
    const { wrapper } = await renderToday(today({ game }))
    expect(wrapper.find('[data-testid=class-caravan-card]').exists()).toBe(false)

    const bare = await renderToday(today())
    expect(bare.wrapper.find('[data-testid=class-caravan-card]').exists()).toBe(false)
  })
})
