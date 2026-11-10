import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameClassView from '../GameClassView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useTodayStore } from '@/stores/today'
import { classMember, gameClass } from '@/api/__tests__/gameClassFixtures'
import type { GameClass } from '@/api/gameClassTypes'
import type { TodayResponse } from '@/api/types'

/** The caravan of a class (docs/05 §5b.7): the states, the boss, the start of the fight and its refusals. */

vi.mock('@/api', () => ({
  api: { gameClass: { show: vi.fn(), list: vi.fn() }, game: { startRun: vi.fn() }, me: { get: vi.fn() }, today: { get: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const back = vi.hoisted(() => ({ target: null as unknown }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: (target: unknown) => (back.target = target) }))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

async function render(caravan: GameClass | Promise<GameClass> | Error = gameClass()) {
  if (caravan instanceof Error) vi.mocked(api.gameClass.show).mockRejectedValue(caravan)
  else vi.mocked(api.gameClass.show).mockReturnValue(Promise.resolve(caravan))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/game', name: 'game', component: stub },
      { path: '/game/class/:id(\\d+)', name: 'game-class', component: stub },
      { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
    ],
  })
  await router.push('/game/class/5')
  const wrapper = mount(GameClassView, { props: { id: 5 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const state = (r: Rendered) => r.wrapper.get('[data-testid=game-class-view]').attributes('data-state')

const unlocked = { unlocked: true, defeated: false, my_attempt: null } as const

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  back.target = null
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameClassView: active', () => {
  it('draws the star track, the deadline, my contribution and the classmates by contribution with me marked', async () => {
    const r = await render()

    expect(state(r)).toBe('active')
    expect(api.gameClass.show).toHaveBeenCalledWith(5)
    expect(tid(r, 'stars-text').text()).toBe('12 / 40')
    expect(tid(r, 'progress-fill').attributes('style')).toContain('width: 30%')
    expect(tid(r, 'until').text()).toMatch(/^до /)
    expect(tid(r, 'goal-text').text()).toBe('До цели осталось звёзд: 28')
    expect(tid(r, 'my-stats').text()).toBe('Звёзды: 3 · забеги: 2 · лучший счёт: 80')
    const members = r.wrapper.findAll('[data-testid=member]')
    expect(members.map((m) => m.attributes('data-me'))).toEqual(['false', 'true'])
    expect(members[0]?.text()).toContain('Dilnoza')
    expect(members[1]?.find('[data-testid=member-me]').text()).toBe('Вы')
    expect(members[0]?.text()).not.toContain('Вы')
    expect(r.wrapper.text()).toContain('Караван класса')
    expect(r.wrapper.text()).toContain('7-B')
    expect(back.target).toEqual({ name: 'today' })
  })

  it('shows the deadline in the learner time zone when /today is known', async () => {
    useTodayStore().data = { timezone: 'Asia/Tashkent' } as TodayResponse
    const r = await render(gameClass({ ends_at: '2026-11-06T13:00:00.000Z' }))

    // 13:00 UTC is 18:00 in Tashkent
    expect(tid(r, 'until').text()).toContain('18:00')
  })

  it('a contribution that has not begun says that any run counts; no members says so too', async () => {
    const r = await render(gameClass({ my: null, members: [] }))

    expect(has(r, 'my-stats')).toBe(false)
    expect(tid(r, 'my-none').text()).toContain('любой забег')
    expect(has(r, 'members-empty')).toBe(true)
  })

  it('the target is taken: the track is full and says so', async () => {
    const r = await render(gameClass({ stars_total: 45 }))

    expect(tid(r, 'progress-fill').attributes('style')).toContain('width: 100%')
    expect(tid(r, 'goal-text').text()).toBe('Цель взята!')
  })

  it('a member who won the boss has the mark', async () => {
    const r = await render(gameClass({ members: [classMember({ boss_won: true })] }))

    expect(tid(r, 'member').text()).toContain('победил хранителя')
  })
})

describe('GameClassView: the boss of the class', () => {
  it('locked: "opens on the target", no button', async () => {
    const r = await render()

    expect(tid(r, 'boss-card').attributes('data-state')).toBe('locked')
    expect(tid(r, 'boss-locked').text()).toBe('Откроется, когда караван возьмёт цель.')
    expect(has(r, 'boss-go')).toBe(false)
  })

  it('ready: "Fight" starts class_boss with the id of the caravan and leads to the run', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 77 })
    const r = await render(gameClass({ boss: unlocked }))

    expect(tid(r, 'boss-card').attributes('data-state')).toBe('ready')
    expect(tid(r, 'boss-go').text()).toBe('Сразиться')
    await tid(r, 'boss-go').trigger('click')
    await flushPromises()

    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'class_boss', class_caravan_id: 5 })
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/77')
  })

  it.each([
    ['won', 'Вы победили хранителя класса.'],
    ['lost', 'Хранитель оказался сильнее. Бой был один — повторить его нельзя.'],
  ] as const)('my fight is over (%s): its result, no button', async (attempt, text) => {
    const r = await render(gameClass({ boss: { unlocked: true, defeated: false, my_attempt: attempt } }))

    expect(tid(r, 'boss-card').attributes('data-state')).toBe(attempt)
    expect(tid(r, 'boss-attempt').text()).toBe(text)
    expect(has(r, 'boss-go')).toBe(false)
  })

  it('a boss that the class defeated says so', async () => {
    const r = await render(gameClass({ boss: { unlocked: true, defeated: true, my_attempt: 'won' } }))

    expect(tid(r, 'boss-defeated').text()).toBe('Класс победил хранителя')
  })
})

describe('GameClassView: the start is refused', () => {
  it('409 game_run_active: a sheet "continue or start this one"; continue leads to that run', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'game_run_active', { run_id: 31, kind: 'stop', progress: { answered: 2, total: 9 } }))
    const r = await render(gameClass({ boss: unlocked }))

    await tid(r, 'boss-go').trigger('click')
    await flushPromises()
    expect(has(r, 'conflict-sheet')).toBe(true)

    await tid(r, 'conflict-continue').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/31')
  })

  it('409 game_run_active: "start this one" repeats the start with replace_active', async () => {
    vi.mocked(api.game.startRun)
      .mockRejectedValueOnce(apiError('conflict', 409, 'game_run_active', { run_id: 31, kind: 'stop', progress: { answered: 2, total: 9 } }))
      .mockResolvedValueOnce({ id: 78 })
    const r = await render(gameClass({ boss: unlocked }))

    await tid(r, 'boss-go').trigger('click')
    await flushPromises()
    await tid(r, 'conflict-replace').trigger('click')
    await flushPromises()

    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'class_boss', class_caravan_id: 5, replace_active: true })
    expect(r.router.currentRoute.value.fullPath).toBe('/game/runs/78')
  })

  it('409 game_run_active without a run in details: a toast', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'game_run_active'))
    const r = await render(gameClass({ boss: unlocked }))

    await tid(r, 'boss-go').trigger('click')
    await flushPromises()
    expect(has(r, 'conflict-sheet')).toBe(false)
    expect(showErrorToast).toHaveBeenCalled()
  })

  it('409 class_boss_done: a toast and the screen is read again', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'class_boss_done'))
    const r = await render(gameClass({ boss: unlocked }))

    await tid(r, 'boss-go').trigger('click')
    await flushPromises()
    expect(showErrorToast).toHaveBeenCalledWith('Вы уже сражались с хранителем класса.')
    expect(api.gameClass.show).toHaveBeenCalledTimes(2)
  })

  it('403 game_unavailable closes the screen', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('forbidden', 403, 'game_unavailable'))
    const r = await render(gameClass({ boss: unlocked }))

    await tid(r, 'boss-go').trigger('click')
    await flushPromises()
    expect(state(r)).toBe('unavailable')
    expect(showErrorToast).not.toHaveBeenCalled()
  })

  it('another code: a toast, the screen stays', async () => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('validation', 422, 'game_not_enough_material'))
    const r = await render(gameClass({ boss: unlocked }))

    await tid(r, 'boss-go').trigger('click')
    await flushPromises()
    expect(showErrorToast).toHaveBeenCalled()
    expect(state(r)).toBe('active')
  })
})

describe('GameClassView: the other states', () => {
  it('loading while the answer is on its way', async () => {
    const r = await render(new Promise<GameClass>(() => {}))

    expect(state(r)).toBe('loading')
  })

  it('finished: the summary of the week and the classmates, no fight button', async () => {
    const r = await render(
      gameClass({
        status: 'finished',
        stars_total: 41,
        boss: { unlocked: true, defeated: false, my_attempt: null },
        summary: { target_reached: true, boss_defeated: false, finished_at: '2026-11-06T13:00:00.000Z' },
      }),
    )

    expect(state(r)).toBe('finished')
    expect(tid(r, 'finished-title').text()).toBe('Неделя каравана окончена')
    expect(tid(r, 'summary-target').text()).toBe('Цель взята')
    expect(tid(r, 'summary-boss').text()).toBe('Хранитель не побеждён')
    expect(has(r, 'until')).toBe(false)
    expect(has(r, 'boss-go')).toBe(false)
    expect(r.wrapper.findAll('[data-testid=member]')).toHaveLength(2)
  })

  it('finished with the target missed and the boss defeated', async () => {
    const r = await render(
      gameClass({
        status: 'finished',
        boss: { unlocked: false, defeated: true, my_attempt: null },
        summary: { target_reached: false, boss_defeated: true, finished_at: null },
      }),
    )

    expect(tid(r, 'summary-target').text()).toBe('Цель не взята')
    expect(tid(r, 'summary-boss').text()).toBe('Хранитель побеждён')
    expect(has(r, 'finished-at')).toBe(false)
  })

  it('canceled: a note and the way to the map', async () => {
    const r = await render(gameClass({ status: 'canceled' }))

    expect(state(r)).toBe('canceled')
    await tid(r, 'to-map').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.name).toBe('game')
  })

  it('404 class_caravan_not_found: not_found with the way to the map', async () => {
    const r = await render(apiError('not_found', 404, 'class_caravan_not_found'))

    expect(state(r)).toBe('not_found')
    await tid(r, 'to-map').trigger('click')
    await flushPromises()
    expect(r.router.currentRoute.value.name).toBe('game')
  })

  it('403 game_unavailable: unavailable', async () => {
    const r = await render(apiError('forbidden', 403, 'game_unavailable'))

    expect(state(r)).toBe('unavailable')
    expect(has(r, 'unavailable')).toBe(true)
  })

  it('another failure: error with Retry that reads again', async () => {
    const r = await render(apiError('server', 500, 'server_error'))

    expect(state(r)).toBe('error')
    vi.mocked(api.gameClass.show).mockResolvedValue(gameClass())
    await r.wrapper.get('button').trigger('click')
    await flushPromises()
    expect(state(r)).toBe('active')
  })

  it('a failed auth is an error too, and no caravan is asked for', async () => {
    authEnsure.mockRejectedValue(new Error('no auth'))
    const r = await render()

    expect(state(r)).toBe('error')
    expect(api.gameClass.show).not.toHaveBeenCalled()
  })
})
