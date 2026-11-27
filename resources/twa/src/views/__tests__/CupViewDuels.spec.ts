import { beforeEach, describe, expect, it, vi, afterEach } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupView from '../CupView.vue'
import { api } from '@/api'
import { BACKGROUND } from '@/api/background'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useCupDuelsStore } from '@/stores/cupDuels'
import { useTodayStore } from '@/stores/today'
import { cupBoard, cupDuel, duelAvailability, duelList } from '@/api/__tests__/cupFixtures'
import type { CupBoardResponse, CupDuelAvailability, TodayResponse } from '@/api/types'

/** "Duels" on the cup screen (docs/33 §7.1, docs/04 A.23d `me.duel`): shown only while the duels are on; the counter of challenges to me. */

vi.mock('@/api', () => ({
  api: { cups: { show: vi.fn(), join: vi.fn(), rename: vi.fn(), cancel: vi.fn(), list: vi.fn(), duels: { list: vi.fn() } } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))

enableAutoUnmount(afterEach)

const withDuel = (duel: CupDuelAvailability | null | undefined): CupBoardResponse => {
  const board = cupBoard()
  return cupBoard({ me: { ...board.me!, duel } })
}

async function render(board: CupBoardResponse) {
  vi.mocked(api.cups.show).mockResolvedValue(board)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cups', name: 'cups', component: stub },
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/round', name: 'cup-round', component: stub },
      { path: '/cups/:id(\\d+)/duels', name: 'cup-duels', component: stub },
      { path: '/cups/:id(\\d+)/duel', name: 'cup-duel-new', component: stub },
      { path: '/cups/:id(\\d+)/duels/:duelId(\\d+)', name: 'cup-duel', component: stub },
    ],
  })
  await router.push('/cups/5')
  const wrapper = mount(CupView, { props: { id: 5 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  setLocale('ru')
  document.body.innerHTML = ''
  useTodayStore().data = { timezone: 'Asia/Tashkent' } as TodayResponse
})

describe('CupView: the duels block', () => {
  it.each([
    ['no `me.duel` (an older server)', undefined],
    ['`me.duel` null (the duels are off)', null],
  ])('%s: no block and no request of the duels', async (_name, duel) => {
    const r = await render(withDuel(duel))

    expect(has(r, 'duel-block')).toBe(false)
    expect(api.cups.duels.list).not.toHaveBeenCalled()
  })

  it('"Challenge to a duel", what a rated duel is, the challenges to me and the way to the list', async () => {
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList({ incoming: [cupDuel({ id: 1, role: 'opponent' }), cupDuel({ id: 2, role: 'opponent' })] }))
    const r = await render(withDuel(duelAvailability()))

    // A block of the board: a background read.
    expect(api.cups.duels.list).toHaveBeenCalledWith(5, BACKGROUND)
    expect(tid(r, 'challenge-btn').attributes('href')).toBe('/cups/5/duel')
    expect(tid(r, 'challenge-btn').classes()).not.toContain('btn-primary')
    expect(tid(r, 'duel-hint').text()).toContain('очко кубка за каждый свой верный ответ')
    expect(tid(r, 'incoming-count').text()).toBe('2 вызова вам')
    expect(tid(r, 'duels-link').attributes('href')).toBe('/cups/5/duels')
  })

  it('my rated duel of the day is played: a friendly match is offered', async () => {
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList())
    const r = await render(withDuel(duelAvailability({ rated_available: false })))

    expect(tid(r, 'duel-hint').text()).toContain('товарищеский матч')
    expect(has(r, 'incoming-count')).toBe(false)
  })

  it('my open challenge: shown instead of a new one', async () => {
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList())
    const r = await render(withDuel(duelAvailability({ can_challenge: false, open_duel_id: 41 })))

    expect(has(r, 'challenge-btn')).toBe(false)
    expect(tid(r, 'duel-waiting').text()).toBe('Ваш вызов ждёт соперника.')
    expect(tid(r, 'open-mine').attributes('href')).toBe('/cups/5/duels/41')
  })

  it.each([
    [{ can_challenge: false, challenges_left: 0 }, 'Вызовы на сегодня закончились.'],
    [{ can_challenge: false, day: null }, 'Дуэли — только пока идёт кубок.'],
    [{ can_challenge: false }, 'Сегодня вызвать на дуэль нельзя.'],
  ] as const)('why not today: %o', async (over, text) => {
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList())
    const r = await render(withDuel(duelAvailability(over)))

    expect(has(r, 'challenge-btn')).toBe(false)
    expect(tid(r, 'duel-reason').text()).toBe(text)
  })

  it('409 cup_duel_disabled: the block goes, and the duels are not asked again in the session', async () => {
    vi.mocked(api.cups.duels.list).mockRejectedValue(new ApiClientError('conflict', 409, 'cup_duel_disabled', 'x'))
    const r = await render(withDuel(duelAvailability()))

    expect(api.cups.duels.list).toHaveBeenCalledTimes(1)
    expect(useCupDuelsStore().off).toBe(true)
    expect(has(r, 'duel-block')).toBe(false)

    r.wrapper.unmount()
    const again = await render(withDuel(duelAvailability()))
    expect(api.cups.duels.list).toHaveBeenCalledTimes(1)
    expect(has(again, 'duel-block')).toBe(false)
  })

  it('a failed list (network) keeps the block without the counter', async () => {
    vi.mocked(api.cups.duels.list).mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
    const r = await render(withDuel(duelAvailability()))

    expect(has(r, 'duel-block')).toBe(true)
    expect(has(r, 'incoming-count')).toBe(false)
  })

  it('uz', async () => {
    setLocale('uz')
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList({ incoming: [cupDuel({ role: 'opponent' })] }))
    const r = await render(withDuel(duelAvailability()))

    expect(tid(r, 'challenge-btn').text()).toBe('Duelga chaqirish')
    expect(tid(r, 'incoming-count').text()).toBe('Sizga 1 ta chaqiruv')
  })
})
