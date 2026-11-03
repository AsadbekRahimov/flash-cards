import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameDailyView from '../GameDailyView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { gameMap, mapDaily } from '@/api/__tests__/gameMapFixtures'
import type { GameMapResponse, TodayResponse } from '@/api/types'

/** The caravan of the day (docs/05 §5b.4): the states, the start, the refusals. */

vi.mock('@/api', () => ({
  api: { game: { map: vi.fn(), startRun: vi.fn() }, today: { get: vi.fn() }, me: { get: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const back = vi.hoisted(() => ({ target: null as unknown }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: (target: unknown) => (back.target = target) }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

const review = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useReviewFromPaywall', () => ({ useReviewFromPaywall: () => ({ reviewFromPaywall: review }) }))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const todayData = (slug: string | null = 'english-a1'): TodayResponse =>
  ({
    due_count: 0,
    active_course: slug ? { id: 3, slug, title: 'English', progress: { learned: 1, total: 9 }, next_lesson: null } : null,
  }) as TodayResponse

async function render(map: GameMapResponse | Promise<GameMapResponse> | Error = gameMap({ daily: mapDaily() }), course: string | null = 'english-a1') {
  if (map instanceof Error) vi.mocked(api.game.map).mockRejectedValue(map)
  else vi.mocked(api.game.map).mockReturnValue(Promise.resolve(map))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/game', name: 'game', component: stub },
      { path: '/game/daily', name: 'game-daily', component: stub },
      { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
    ],
  })
  await router.push('/game/daily')
  const wrapper = mount(GameDailyView, { props: { course }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const state = (r: Rendered) => r.wrapper.get('[data-testid=game-daily-view]').attributes('data-state')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  back.target = null
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameDailyView: the states', () => {
  it('ready: how many words are due and a "Go" button', async () => {
    const r = await render()

    expect(state(r)).toBe('ready')
    expect(tid(r, 'ready-text').text()).toBe('К повторению 14 слов — разминка на 8–10 заданий.')
    expect(tid(r, 'start-btn').text()).toBe('В путь')
    expect(api.game.map).toHaveBeenCalledWith('english-a1')
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('ready: the words are counted in the right form, and in Uzbek', async () => {
    expect(tid(await render(gameMap({ daily: mapDaily({ due_count: 1 }) })), 'ready-text').text()).toContain('1 слово')
    expect(tid(await render(gameMap({ daily: mapDaily({ due_count: 3 }) })), 'ready-text').text()).toContain('3 слова')
    setLocale('uz')
    expect(tid(await render(), 'ready-text').text()).toBe("Takrorlash uchun 14 ta so'z — 8–10 topshiriqlik mashq.")
  })

  it('running: a run is on - "Continue" leads into it, no start button', async () => {
    const r = await render(gameMap({ daily: mapDaily({ available: false }), active_run: { id: 9, kind: 'daily', progress: { answered: 3, total: 9 } } }))

    expect(state(r)).toBe('running')
    expect(tid(r, 'running-progress').text()).toBe('3 из 9')
    expect(has(r, 'start-btn')).toBe(false)
    await tid(r, 'continue-btn').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/9')
  })

  it('done: the day is played - the next one is tomorrow, the usual review is in the cards', async () => {
    const r = await render(gameMap({ daily: mapDaily({ available: false, done_today: true }) }))

    expect(state(r)).toBe('done')
    expect(tid(r, 'done').text()).toContain('Караван дня сыгран')
    expect(tid(r, 'done').text()).toContain('Обычное повторение — в карточках')
    expect(tid(r, 'done').attributes('data-resets-at')).toBe('2026-11-06T19:00:00.000Z')
    expect(has(r, 'start-btn')).toBe(false)
    await tid(r, 'review-btn').trigger('click')
    expect(review).toHaveBeenCalledTimes(1)
  })

  it('empty: nothing is due; the way on is the map', async () => {
    const r = await render(gameMap({ daily: mapDaily({ available: false, due_count: 0 }) }))

    expect(state(r)).toBe('empty')
    expect(tid(r, 'empty').text()).toContain('Пока нечего повторять')
    await tid(r, 'to-map').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
  })

  it('a map with no daily block at all (an older server) reads as "nothing to repeat"', async () => {
    expect(state(await render(gameMap()))).toBe('empty')
  })

  it('403 game_unavailable is "not available yet"', async () => {
    expect(state(await render(apiError('forbidden', 403, 'game_unavailable')))).toBe('unavailable')
  })

  it('any other failure of the map is an error with "Retry"', async () => {
    const failed = await render(apiError('server', 500, 'server_error'))
    expect(state(failed)).toBe('error')
  })

  it('a learner without an active course is asked to choose one', async () => {
    vi.mocked(api.today.get).mockResolvedValue(todayData(null))
    expect(state(await render(gameMap(), null))).toBe('no_course')
    expect(api.game.map).not.toHaveBeenCalled()
  })

  it('without ?course= takes the active course of /today (never /me)', async () => {
    vi.mocked(api.today.get).mockResolvedValue(todayData('ielts'))

    const r = await render(gameMap({ daily: mapDaily() }), null)

    expect(api.game.map).toHaveBeenCalledWith('ielts')
    expect(state(r)).toBe('ready')
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('Back: to the map when it came from the map, to "Today" when it came from there', async () => {
    await render(gameMap({ daily: mapDaily() }), 'english-a1')
    expect(back.target).toEqual({ name: 'game', query: { course: 'english-a1' } })

    vi.mocked(api.today.get).mockResolvedValue(todayData())
    await render(gameMap({ daily: mapDaily() }), null)
    expect(back.target).toEqual({ name: 'today' })
  })
})

describe('GameDailyView: the start', () => {
  it('201 leads into the run; the request names the kind only', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 12 })
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await flushPromises()

    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'daily' })
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/12')
  })

  it('409 game_daily_done: the day was spent (a lost race, another tab) - "done" with the time, no toast', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'game_daily_done', { resets_at: '2026-11-07T19:00:00.000Z' }))
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await flushPromises()

    expect(state(r)).toBe('done')
    expect(tid(r, 'done').attributes('data-resets-at')).toBe('2026-11-07T19:00:00.000Z')
    expect(showErrorToast).not.toHaveBeenCalled()
    expect(r.router.currentRoute.value.fullPath).toBe('/game/daily')
  })

  it('422 game_not_enough_material: "nothing to repeat"', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('validation', 422, 'game_not_enough_material', { reason: 'no_words' }))
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await flushPromises()

    expect(state(r)).toBe('empty')
    expect(showErrorToast).not.toHaveBeenCalled()
  })

  it('409 game_run_active: shows the run that is on, with "Continue"', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(
      apiError('conflict', 409, 'game_run_active', { run_id: 7, kind: 'stop', progress: { answered: 2, total: 9 } }),
    )
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await flushPromises()

    expect(state(r)).toBe('running')
    expect(tid(r, 'running-progress').text()).toBe('2 из 9')
    await tid(r, 'continue-btn').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/7')
  })

  it('403 game_unavailable on the start: "not available yet"', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('forbidden', 403, 'game_unavailable'))
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await flushPromises()

    expect(state(r)).toBe('unavailable')
  })

  it('another failure is a toast and the button stays', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(new ApiClientError('network', 0, 'network', 'fetch failed'))
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(state(r)).toBe('ready')
  })

  it('a double tap is one request; a reply that comes after the learner has left leads nowhere', async () => {
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
})
