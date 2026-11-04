import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameBossView from '../GameBossView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useBillingStore } from '@/stores/billing'
import { gameLimits, gameMap, mapBoss, mapCity } from '@/api/__tests__/gameMapFixtures'
import type { GameMapResponse, TodayResponse } from '@/api/types'

/** The boss of a city (docs/05 §5b.8): the states by `boss.state`, the start and each refusal of it. */

vi.mock('@/api', () => ({
  api: { game: { map: vi.fn(), startRun: vi.fn() }, today: { get: vi.fn() }, me: { get: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const back = vi.hoisted(() => ({ target: null as unknown }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: (target: unknown) => (back.target = target) }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' } },
      emits: ['close', 'review'],
      setup: (props) => () => h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }),
    }),
  }
})

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const todayData = (slug: string | null = 'english-a1'): TodayResponse =>
  ({ due_count: 0, active_course: slug ? { id: 3, slug, title: 'English', progress: { learned: 1, total: 9 }, next_lesson: null } : null }) as TodayResponse

/** A map whose first city (stage 3) has the boss in `state`. */
const mapWith = (state: Parameters<typeof mapBoss>[0] = {}, extra: Partial<GameMapResponse> = {}): GameMapResponse =>
  gameMap({ cities: [mapCity({ stage_id: 3, number: 1, city: 'Самарканд', boss: mapBoss(state) })], ...extra })

async function render(map: GameMapResponse | Error = mapWith({ state: 'ready' }), props: { stageId?: number; course?: string | null } = {}) {
  if (map instanceof Error) vi.mocked(api.game.map).mockRejectedValue(map)
  else vi.mocked(api.game.map).mockResolvedValue(map)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/game', name: 'game', component: stub },
      { path: '/game/boss/:stageId(\\d+)', name: 'game-boss', component: stub },
      { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
    ],
  })
  await router.push('/game/boss/3?course=english-a1')
  const wrapper = mount(GameBossView, { props: { stageId: 3, course: 'english-a1', ...props }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const state = (r: Rendered) => r.wrapper.get('[data-testid=game-boss-view]').attributes('data-state')
const press = async (r: Rendered, id: string) => {
  await tid(r, id).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  back.target = null
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameBossView: the states', () => {
  it('ready: the keeper, the city, what is asked, "Бросить вызов"; the map of the course is read, never /me', async () => {
    const r = await render()

    expect(state(r)).toBe('ready')
    expect(tid(r, 'keeper').text()).toBe('Хранитель ворот')
    expect(tid(r, 'city-name').text()).toBe('Самарканд')
    expect(tid(r, 'rules').text()).toContain('12 слов')
    expect(tid(r, 'rules').text()).toContain('Нужно 80% верных ответов')
    expect(tid(r, 'start-btn').text()).toBe('Бросить вызов')
    expect(has(r, 'beaten')).toBe(false)
    expect(api.game.map).toHaveBeenCalledWith('english-a1')
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('shows the Free limit of the day with the tickets only where the server told the limits', async () => {
    const withLimits = await render(mapWith({ state: 'ready' }, { limits: gameLimits() }))
    expect(tid(withLimits, 'limit').text()).toBe('Сегодня бесплатно: 1 из 1 · билеты: 2')
    expect(useBillingStore().gameLimits?.tickets.balance).toBe(2)

    setActivePinia(createPinia())
    document.body.innerHTML = ''
    const without = await render(mapWith({ state: 'ready' }, { limits: null }))
    expect(has(without, 'limit')).toBe(false)
  })

  it('defeated: the same intro with "already beaten" and "Сразиться ещё раз"', async () => {
    const r = await render(mapWith({ state: 'defeated', defeated_at: '2026-11-05T10:00:00Z' }))

    expect(state(r)).toBe('defeated')
    expect(tid(r, 'beaten').text()).toContain('Вы уже прошли этот город')
    expect(tid(r, 'start-btn').text()).toBe('Сразиться ещё раз')
  })

  it('not_ready: what is missing - stops first, then words - and the way to the map; no start', async () => {
    const stops = await render(mapWith({ state: 'not_ready', cleared_stops: 1, required_stops: 3 }))
    expect(state(stops)).toBe('not_ready')
    expect(tid(stops, 'not-ready-text').text()).toBe('Хранитель ещё не ждёт: ещё 2 стоянки.')
    expect(has(stops, 'start-btn')).toBe(false)
    await press(stops, 'to-map')
    expect(stops.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')

    setActivePinia(createPinia())
    document.body.innerHTML = ''
    const words = await render(mapWith({ state: 'not_ready', cleared_stops: 3, required_stops: 3, introduced: 5, required: 8 }))
    expect(tid(words, 'not-ready-text').text()).toBe('Хранитель ещё не ждёт: ещё 3 слова.')
  })

  it('locked_by_plan: "для Pro", the Pro chip and a button that opens the paywall of the game', async () => {
    const r = await render(mapWith({ state: 'locked_by_plan' }))

    expect(state(r)).toBe('locked')
    expect(has(r, 'pro-chip')).toBe(true)
    expect(tid(r, 'locked-text').text()).toContain('для Pro')
    expect(has(r, 'start-btn')).toBe(false)
    await press(r, 'pro-btn')
    expect(tid(r, 'paywall').attributes('data-reason')).toBe('game')
    expect(api.game.startRun).not.toHaveBeenCalled()
  })

  it('a city that the map does not have is "no such city"', async () => {
    const r = await render(mapWith({ state: 'ready' }), { stageId: 99 })
    expect(state(r)).toBe('missing')
    expect(tid(r, 'missing').text()).toContain('Такого города нет')
  })

  it('403 game_unavailable, another failure of the map, no course', async () => {
    expect(state(await render(apiError('forbidden', 403, 'game_unavailable')))).toBe('unavailable')

    setActivePinia(createPinia())
    document.body.innerHTML = ''
    expect(state(await render(apiError('server', 500, 'server_error')))).toBe('error')
  })

  it('without ?course= it takes the active course of /today', async () => {
    vi.mocked(api.today.get).mockResolvedValue(todayData('ielts'))
    const r = await render(mapWith({ state: 'ready' }), { course: null })

    expect(api.game.map).toHaveBeenCalledWith('ielts')
    expect(state(r)).toBe('ready')
    expect(back.target).toEqual({ name: 'today' })
  })

  it('a learner without an active course is asked to choose one', async () => {
    vi.mocked(api.today.get).mockResolvedValue(todayData(null))
    const r = await render(mapWith(), { course: null })
    expect(state(r)).toBe('no_course')
  })

  it('Back leads to the map of the course', async () => {
    await render()
    expect(back.target).toEqual({ name: 'game', query: { course: 'english-a1' } })
  })
})

describe('GameBossView: the start', () => {
  it('201: the run, with the course and the stage of the boss in its address; the request is the boss of this city', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 12 })
    const r = await render()

    await press(r, 'start-btn')

    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'boss', course: 'english-a1', stage_id: 3 })
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/12?course=english-a1&boss=3')
  })

  it.each([['game_pro_required'], ['game_limit_reached']])('403 %s is the paywall of the game', async (code) => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('forbidden', 403, code))
    const r = await render()

    await press(r, 'start-btn')

    expect(tid(r, 'paywall').attributes('data-reason')).toBe('game')
    expect(showErrorToast).not.toHaveBeenCalled()
  })

  it('409 game_run_active: continue that run, or start this one with replace_active', async () => {
    vi.mocked(api.game.startRun).mockRejectedValueOnce(apiError('conflict', 409, 'game_run_active', { run_id: 7, kind: 'stop', progress: { answered: 2, total: 9 } }))
    const r = await render()

    await press(r, 'start-btn')
    expect(has(r, 'conflict-sheet')).toBe(true)

    vi.mocked(api.game.startRun).mockResolvedValueOnce({ id: 13 })
    await press(r, 'conflict-replace')
    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'boss', course: 'english-a1', stage_id: 3, replace_active: true })
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/13?course=english-a1&boss=3')
  })

  it('409 game_run_active, "Продолжить забег": into the run that is on', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'game_run_active', { run_id: 7, kind: 'stop', progress: { answered: 2, total: 9 } }))
    const r = await render()

    await press(r, 'start-btn')
    await press(r, 'conflict-continue')

    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/7')
  })

  it('409 boss_not_ready: a toast and the map is read again - the screen turns to what is true', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'boss_not_ready', { introduced: 5, required: 8, cleared_stops: 1, required_stops: 3 }))
    const r = await render()
    vi.mocked(api.game.map).mockResolvedValue(mapWith({ state: 'not_ready', cleared_stops: 1, required_stops: 3 }))

    await press(r, 'start-btn')

    expect(showErrorToast).toHaveBeenCalledWith('Босс пока не готов: пройдите больше стоянок.')
    expect(api.game.map).toHaveBeenCalledTimes(2)
    expect(state(r)).toBe('not_ready')
  })

  it('422 game_not_enough_material: the sheet "Слов пока мало" with the way to learn', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('validation', 422, 'game_not_enough_material', { reason: 'no_words' }))
    const r = await render()

    await press(r, 'start-btn')
    expect(has(r, 'material-sheet')).toBe(true)

    await press(r, 'material-learn')
    expect(r.router.currentRoute.value.fullPath).toBe('/courses/english-a1')
  })

  it('404 stage_not_found: "no such city"; another failure: a toast and the button stays', async () => {
    vi.mocked(api.game.startRun).mockRejectedValueOnce(apiError('not_found', 404, 'stage_not_found'))
    const gone = await render()
    await press(gone, 'start-btn')
    expect(state(gone)).toBe('missing')

    setActivePinia(createPinia())
    document.body.innerHTML = ''
    vi.mocked(api.game.startRun).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'fetch failed'))
    const offline = await render()
    await press(offline, 'start-btn')
    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(state(offline)).toBe('ready')
  })

  it('a double tap is one request; a reply after the learner has left leads nowhere', async () => {
    let created!: (value: { id: number }) => void
    vi.mocked(api.game.startRun).mockReturnValue(new Promise((resolve) => (created = resolve)))
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await tid(r, 'start-btn').trigger('click')
    expect(api.game.startRun).toHaveBeenCalledTimes(1)
    expect(tid(r, 'start-btn').attributes('disabled')).toBeDefined()

    const push = vi.spyOn(r.router, 'push')
    r.wrapper.unmount()
    created({ id: 5 })
    await flushPromises()
    expect(push).not.toHaveBeenCalled()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()
    expect(tid(r, 'keeper').text()).toBe("Darvoza qo'riqchisi")
    expect(tid(r, 'start-btn').text()).toBe('Jangga chiqish')
  })
})
