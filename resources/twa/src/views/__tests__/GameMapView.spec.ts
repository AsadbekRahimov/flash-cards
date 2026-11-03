import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameMapView from '../GameMapView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { gameLimits, gameMap, mapBoss, mapCity, mapDaily, mapStop } from '@/api/__tests__/gameMapFixtures'
import type { GameMapResponse, TodayResponse } from '@/api/types'

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
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }, [h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') })]),
    }),
  }
})

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const todayData = (slug: string | null = 'english-a1'): TodayResponse =>
  ({
    due_count: 0,
    active_course: slug ? { id: 3, slug, title: 'English', progress: { learned: 1, total: 9 }, next_lesson: null } : null,
  }) as TodayResponse

type Props = { course?: string | null; stop?: number | null }

async function render(map: GameMapResponse | Promise<GameMapResponse> | Error = gameMap(), props: Props = {}, url = '/game') {
  if (map instanceof Error) vi.mocked(api.game.map).mockRejectedValue(map)
  else vi.mocked(api.game.map).mockReturnValue(Promise.resolve(map))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/game', name: 'game', component: stub },
      { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
    ],
  })
  await router.push(url)
  const wrapper = mount(GameMapView, {
    props: { course: 'english-a1', stop: null, ...props },
    global: { plugins: [router] },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']
const testid = (wrapper: Wrapper, id: string) => wrapper.find(`[data-testid="${id}"]`)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  back.target = null
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameMapView: states', () => {
  it('shows the skeleton while the map is being read', async () => {
    const { wrapper } = await render(new Promise<GameMapResponse>(() => {}))

    expect(wrapper.find('[data-testid=road]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=empty]').exists()).toBe(false)
  })

  it('draws the road of cities in the order of the modules, with their names', async () => {
    const { wrapper } = await render()

    expect(testid(wrapper, 'city-1').get('[data-testid=city-name]').text()).toBe('Самарканд')
    expect(testid(wrapper, 'city-1').get('[data-testid=city-title]').text()).toBe('Еда')
    // A module without a name is "Module N".
    expect(testid(wrapper, 'city-2').get('[data-testid=city-title]').text()).toBe('Модуль 2')
    const order = wrapper.findAll('[data-testid=city-name]').map((n) => n.text())
    expect(order).toEqual(['Самарканд', 'Бухара'])
  })

  it('draws stars of the stops: 12 px, filled up to the count', async () => {
    const { wrapper } = await render()

    const stars = testid(wrapper, 'stop-11').get('[data-testid=stars]')
    expect(stars.attributes('aria-label')).toBe('2 из 3 звёзд')
    const icons = stars.findAll('svg')
    expect(icons).toHaveLength(3)
    expect(icons.every((icon) => icon.attributes('width') === '12')).toBe(true)
    expect(icons.map((icon) => icon.classes().includes('text-warning'))).toEqual([true, true, false])
  })

  it('shows a closed stop grey, with "start the lesson", and does not open it', async () => {
    const { wrapper } = await render()

    const stop = testid(wrapper, 'stop-12')
    expect(stop.text()).toContain('начните урок')
    expect(stop.attributes('aria-disabled')).toBe('true')
    await stop.trigger('click')
    expect(wrapper.find('[data-testid=stop-sheet]').exists()).toBe(false)
  })

  it('is empty when no stop is open: "Learn" leads to the course', async () => {
    const closed = gameMap({ cities: [mapCity({ unlocked: false, stops: [mapStop({ unlocked: false })] })] })
    const { wrapper, router } = await render(closed)

    expect(testid(wrapper, 'empty').text()).toContain('Начните урок в карточках — откроется первая стоянка')
    expect(wrapper.find('[data-testid=road]').exists()).toBe(false)
    await testid(wrapper, 'learn-btn').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/courses/english-a1')
  })

  it('is "unavailable" on 403 game_unavailable', async () => {
    const { wrapper } = await render(apiError('forbidden', 403, 'game_unavailable'))

    expect(testid(wrapper, 'unavailable').exists()).toBe(true)
    expect(wrapper.find('[data-testid=road]').exists()).toBe(false)
  })

  it('shows the error of the map with Retry that reads it again', async () => {
    const { wrapper } = await render(apiError('not_found', 404, 'course_not_found'))

    expect(wrapper.text()).toContain('Курс не найден или недоступен.')
    vi.mocked(api.game.map).mockResolvedValue(gameMap())
    await wrapper.get('[data-testid=error-retry], button.btn').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=road]').exists()).toBe(true)
  })

  it('offers the banner of the unfinished run, which leads back into it', async () => {
    const { wrapper, router } = await render(gameMap({ active_run: { id: 9, kind: 'stop', progress: { answered: 3, total: 9 } } }))

    expect(testid(wrapper, 'active-run-progress').text()).toBe('3 из 9')
    await testid(wrapper, 'active-run').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game/runs/9')
  })

  it('goes back to "Today" with the Telegram Back button', async () => {
    await render()

    expect(back.target).toEqual({ name: 'today' })
  })
})

describe('GameMapView: the course', () => {
  it('takes the active course of /today when there is no ?course=, and never reads /me', async () => {
    vi.mocked(api.today.get).mockResolvedValue(todayData('ielts'))
    const { wrapper } = await render(gameMap({ course: { slug: 'ielts', title: 'IELTS' } }), { course: null })

    expect(api.today.get).toHaveBeenCalledTimes(1)
    expect(api.game.map).toHaveBeenCalledWith('ielts')
    expect(wrapper.find('[data-testid=road]').exists()).toBe(true)
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('uses the /today that is already there instead of asking again', async () => {
    useTodayStore().data = todayData('ielts')
    await render(gameMap(), { course: null })

    expect(api.today.get).not.toHaveBeenCalled()
    expect(api.game.map).toHaveBeenCalledWith('ielts')
  })

  it('asks to choose a course when there is none', async () => {
    vi.mocked(api.today.get).mockResolvedValue(todayData(null))
    const { wrapper } = await render(gameMap(), { course: null })

    expect(testid(wrapper, 'no-course').exists()).toBe(true)
    expect(api.game.map).not.toHaveBeenCalled()
  })

  it('does not read /me for the map, the sheet or the start', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 5 })
    const { wrapper } = await render()
    await testid(wrapper, 'stop-11').trigger('click')
    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()

    expect(api.me.get).not.toHaveBeenCalled()
  })
})

describe('GameMapView: the caravan of the day', () => {
  it.each([
    ['waiting', { daily: mapDaily() }, 'ready', 'К повторению 14 слов'],
    ['played', { daily: mapDaily({ available: false, done_today: true }) }, 'done', 'Сыграно, завтра снова'],
    ['nothing due', { daily: mapDaily({ available: false, due_count: 0 }) }, 'empty', 'Пока нечего повторять'],
    [
      'a run is on',
      { daily: mapDaily({ available: false }), active_run: { id: 9, kind: 'stop', progress: { answered: 3, total: 9 } } },
      'running',
      'Идёт забег — продолжить',
    ],
  ] as const)('is a card above the road when the caravan is %s', async (_name, overrides, dailyState, text) => {
    const { wrapper } = await render(gameMap(overrides))

    const card = testid(wrapper, 'daily-card')
    expect(card.attributes('data-state')).toBe(dailyState)
    expect(card.get('[data-testid=daily-text]').text()).toBe(text)
    expect(card.text()).toContain('Караван дня')
    // above the road
    expect(wrapper.html().indexOf('data-testid="daily-card"')).toBeLessThan(wrapper.html().indexOf('data-testid="road"'))
  })

  it('leads to /game/daily with the course', async () => {
    const { wrapper, router } = await render(gameMap({ daily: mapDaily() }))
    router.addRoute({ path: '/game/daily', name: 'game-daily', component: { template: '<div />' } })

    await testid(wrapper, 'daily-card').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/game/daily?course=english-a1')
  })

  it('stands over the empty state too (the words can be due before any stop is open)', async () => {
    const { wrapper } = await render(
      gameMap({ daily: mapDaily(), cities: [mapCity({ stops: [mapStop({ unlocked: false })] })] }),
    )

    expect(testid(wrapper, 'empty').exists()).toBe(true)
    expect(testid(wrapper, 'daily-card').exists()).toBe(true)
  })

  it('is not drawn when the map has no daily block', async () => {
    const { wrapper } = await render(gameMap())
    expect(testid(wrapper, 'daily-card').exists()).toBe(false)
  })
})

describe('GameMapView: friends and bosses', () => {
  it('marks the friends at their city, three names and "+N"', async () => {
    const friends = ['Anna', 'Bek', 'Cem', 'Dil', 'Eva'].map((name, i) => ({ friendship_id: i + 1, name, city_number: 1, stops_cleared: 3, camel_asset: null }))
    const { wrapper } = await render(gameMap({ friends }))

    expect(testid(wrapper, 'city-1').get('[data-testid=city-friends]').text()).toBe('Здесь: Anna, Bek, Cem +2')
    expect(testid(wrapper, 'city-2').find('[data-testid=city-friends]').exists()).toBe(false)
  })

  it.each([
    ['ready', {}, 'Босс ждёт'],
    ['defeated', {}, 'Босс побеждён'],
    ['not_ready', { cleared_stops: 1, required_stops: 3 }, 'ещё 2 стоянки'],
    ['not_ready', { cleared_stops: 2, required_stops: 3 }, 'ещё 1 стоянка'],
    ['not_ready', { cleared_stops: 3, required_stops: 3, introduced: 6, required: 8 }, 'ещё 2 слова'],
  ] as const)('shows the boss %s as a chip that is not a button (%j)', async (state, extra, text) => {
    const { wrapper } = await render(gameMap({ cities: [mapCity({ boss: mapBoss({ state, ...extra }) })] }))

    const chip = testid(wrapper, `boss-${state}`)
    expect(chip.text()).toBe(text)
    expect(chip.element.tagName).toBe('SPAN')
    expect(wrapper.find('[data-testid=boss-pro]').exists()).toBe(false)
  })

  it('opens the paywall of the game from the Pro chip of a boss', async () => {
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    await testid(wrapper, 'boss-pro').trigger('click')
    expect(testid(wrapper, 'paywall').attributes('data-reason')).toBe('game')
    await testid(wrapper, 'paywall-close').trigger('click')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })
})

describe('GameMapView: the sheet of a stop', () => {
  it('opens for an open stop with what a run is and the best result', async () => {
    const { wrapper } = await render()
    await testid(wrapper, 'stop-11').trigger('click')

    const sheet = testid(wrapper, 'stop-sheet')
    expect(sheet.text()).toContain('Стоянка 1 · Фрукты')
    expect(testid(wrapper, 'stop-text').text()).toBe('8–10 заданий, 3 фляги')
    expect(testid(wrapper, 'stop-best').text()).toContain('Лучший счёт: 120')
  })

  it('does not say a best result for a stop that was never played', async () => {
    const map = gameMap({ cities: [mapCity({ stops: [mapStop({ runs: 0, stars: 0, best_score: 0 })] })] })
    const { wrapper } = await render(map)
    await testid(wrapper, 'stop-11').trigger('click')

    expect(wrapper.find('[data-testid=stop-best]').exists()).toBe(false)
  })

  it('shows no line of limits while the limits are null (before G2.8)', async () => {
    const { wrapper } = await render(gameMap({ limits: null }))
    await testid(wrapper, 'stop-11').trigger('click')

    expect(wrapper.find('[data-testid=stop-limit]').exists()).toBe(false)
  })

  it('shows the Free line when the server told the limits: free runs and tickets', async () => {
    const { wrapper } = await render(gameMap({ limits: gameLimits() }))
    await testid(wrapper, 'stop-11').trigger('click')

    expect(testid(wrapper, 'stop-limit').text()).toBe('Сегодня бесплатно: 1 из 1 · билеты: 2')
  })

  it('takes the limits of /me that are already in the billing store', async () => {
    useBillingStore().gameLimits = gameLimits({ runs: { limit: 1, used: 1, remaining: 0, resets_at: '2999-01-01T19:00:00.000Z' } })
    const { wrapper } = await render(gameMap({ limits: undefined as never }))
    await testid(wrapper, 'stop-11').trigger('click')

    expect(testid(wrapper, 'stop-limit').text()).toContain('0 из 1')
  })

  it('says nothing for Pro (runs without a limit)', async () => {
    const { wrapper } = await render(gameMap({ limits: gameLimits({ runs: null }) }))
    await testid(wrapper, 'stop-11').trigger('click')

    expect(wrapper.find('[data-testid=stop-limit]').exists()).toBe(false)
  })

  it('closes with the close button', async () => {
    const { wrapper } = await render()
    await testid(wrapper, 'stop-11').trigger('click')
    await testid(wrapper, 'sheet-x').trigger('click')

    expect(wrapper.find('[data-testid=stop-sheet]').exists()).toBe(false)
  })
})

describe('GameMapView: ?stop=', () => {
  it('opens the sheet of that stop once the map is there', async () => {
    const { wrapper } = await render(gameMap(), { stop: 11 }, '/game?course=english-a1&stop=11')

    expect(testid(wrapper, 'stop-sheet').text()).toContain('Фрукты')
  })

  it('ignores a stop that is closed or not on the map', async () => {
    const closed = await render(gameMap(), { stop: 12 }, '/game?stop=12')
    expect(closed.wrapper.find('[data-testid=stop-sheet]').exists()).toBe(false)

    const missing = await render(gameMap(), { stop: 999 }, '/game?stop=999')
    expect(missing.wrapper.find('[data-testid=stop-sheet]').exists()).toBe(false)
  })

  it('takes ?stop= off the address when the sheet is closed', async () => {
    const { wrapper, router } = await render(gameMap(), { stop: 11 }, '/game?course=english-a1&stop=11')
    await testid(wrapper, 'sheet-x').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
  })
})

describe('GameMapView: starting', () => {
  async function openStop() {
    const view = await render()
    await testid(view.wrapper, 'stop-11').trigger('click')
    return view
  }

  it('starts the run and leads to it on 201', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 42 })
    const { wrapper, router } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()

    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'stop', course: 'english-a1', lesson_id: 11 })
    expect(router.currentRoute.value.fullPath).toBe('/game/runs/42')
  })

  it('does not start twice while the first start is on its way', async () => {
    let created!: (value: { id: number }) => void
    vi.mocked(api.game.startRun).mockReturnValue(new Promise((resolve) => (created = resolve)))
    const { wrapper } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await testid(wrapper, 'stop-go').trigger('click')
    created({ id: 1 })
    await flushPromises()

    expect(api.game.startRun).toHaveBeenCalledTimes(1)
  })

  it('opens the paywall of the game on 403 game_limit_reached', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('forbidden', 403, 'game_limit_reached', { kind: 'runs' }))
    const { wrapper } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()

    expect(testid(wrapper, 'paywall').attributes('data-reason')).toBe('game')
    expect(wrapper.find('[data-testid=stop-sheet]').exists()).toBe(false)
  })

  it('asks what to do on 409 game_run_active: continue leads into the run that is on', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'game_run_active', { run_id: 7, kind: 'stop', progress: { answered: 2, total: 9 } }))
    const { wrapper, router } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()
    expect(testid(wrapper, 'conflict-sheet').text()).toContain('прежний не засчитается')

    await testid(wrapper, 'conflict-continue').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/game/runs/7')
  })

  it('starts this one over the old on "Start this": replace_active', async () => {
    vi.mocked(api.game.startRun)
      .mockRejectedValueOnce(apiError('conflict', 409, 'game_run_active', { run_id: 7, kind: 'stop', progress: { answered: 2, total: 9 } }))
      .mockResolvedValueOnce({ id: 8 })
    const { wrapper, router } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()
    await testid(wrapper, 'conflict-replace').trigger('click')
    await flushPromises()

    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'stop', course: 'english-a1', lesson_id: 11, replace_active: true })
    expect(router.currentRoute.value.fullPath).toBe('/game/runs/8')
  })

  it('says to learn more on 422 game_not_enough_material', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('validation', 422, 'game_not_enough_material', { available: 3, required: 8, reason: 'few' }))
    const { wrapper, router } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()
    expect(testid(wrapper, 'material-sheet').text()).toContain('Слов пока мало')

    await testid(wrapper, 'material-learn').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/courses/english-a1')
  })

  it('reads the map again on 409 stop_locked, with a toast', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'stop_locked'))
    const { wrapper } = await openStop()
    vi.mocked(api.game.map).mockClear()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Эта стоянка закрыта: начните урок в карточках.')
    expect(api.game.map).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=stop-sheet]').exists()).toBe(false)
  })

  it('tells any other refusal in a toast and stays on the sheet', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(new ApiClientError('network', 0, 'network', 'fetch failed'))
    const { wrapper } = await openStop()

    await testid(wrapper, 'stop-go').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(wrapper.find('[data-testid=stop-sheet]').exists()).toBe(true)
  })

  it('does not carry the learner anywhere when the screen was left before the answer', async () => {
    let created!: (value: { id: number }) => void
    vi.mocked(api.game.startRun).mockReturnValue(new Promise((resolve) => (created = resolve)))
    const { wrapper, router } = await openStop()
    const push = vi.spyOn(router, 'push')

    await testid(wrapper, 'stop-go').trigger('click')
    wrapper.unmount()
    created({ id: 3 })
    await flushPromises()

    expect(push).not.toHaveBeenCalled()
  })
})

describe('GameMapView: Uzbek', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Karvon')
    expect(testid(wrapper, 'stop-12').text()).toContain('darsni boshlang')
    expect(testid(wrapper, 'city-2').get('[data-testid=city-title]').text()).toBe('2-modul')
  })
})
