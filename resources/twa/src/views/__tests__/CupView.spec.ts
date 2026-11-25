import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupView from '../CupView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTodayStore } from '@/stores/today'
import { cupBoard, cupFields, cupRules, standing } from '@/api/__tests__/cupFixtures'
import type { CupBoardResponse, TodayResponse } from '@/api/types'

/** The cup screen (docs/33 §7.1, docs/04 A.23 `GET /cups/{id}`): the states, the table, my day, the name, the cancel. */

vi.mock('@/api', () => ({
  api: { cups: { show: vi.fn(), join: vi.fn(), rename: vi.fn(), cancel: vi.fn(), list: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

async function render(board: CupBoardResponse | Error = cupBoard()) {
  if (board instanceof Error) vi.mocked(api.cups.show).mockRejectedValue(board)
  else vi.mocked(api.cups.show).mockResolvedValue(board)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cups', name: 'cups', component: stub },
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/round', name: 'cup-round', component: stub },
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
const state = (r: Rendered) => tid(r, 'cup-view').attributes('data-state')
const body = () => document.body

const withToday = (today: Partial<CupBoardResponse['me'] & object>['today']) => {
  const board = cupBoard()
  return cupBoard({ me: { ...board.me!, today: today! } })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  setLocale('ru')
  document.body.innerHTML = ''
  useTodayStore().data = { timezone: 'Asia/Tashkent' } as TodayResponse
})

describe('CupView: the table', () => {
  it('places, the emoji of Pro, shared places, "left" and my row', async () => {
    const r = await render()

    expect(api.cups.show).toHaveBeenCalledWith(5)
    expect(state(r)).toBe('ready')
    const rows = r.wrapper.findAll('[data-testid=cup-standings] li')
    expect(rows.map((row) => row.get('[data-testid=rank]').text())).toEqual(['1', '2', '2', '4'])
    expect(rows[0]?.get('[data-testid=pro-emoji]').text()).toBe('🦄')
    expect(rows[0]?.attributes('aria-label')).toBe('1 место, Aziza, 30 очков')
    // A tie: the same place twice, said so to a screen reader.
    expect(rows[1]?.attributes('data-shared')).toBe('true')
    expect(rows[2]?.attributes('aria-label')).toBe('2 место (общее), Bobur, 20 очков')
    expect(rows[0]?.attributes('data-shared')).toBeUndefined()
    // My row: tinted, "· вы" (the word of the league).
    expect(rows[1]?.attributes('data-testid')).toBe('row-me')
    expect(rows[1]?.text()).toContain('· вы')
    // One who left: the mark on its own line.
    expect(rows[3]?.attributes('data-left')).toBe('true')
    expect(rows[3]?.get('[data-testid=left]').text()).toBe('вышел(а)')
    expect(tid(r, 'my-points').text()).toBe('20 очков')
    expect(tid(r, 'my-place').text()).toBe('Ваше место: 2')
  })

  it('a long name is cut, the emoji and the points stay (320 px)', async () => {
    const long = 'Абдурахмонбек Худойберди Мирзоулугбекович'
    const r = await render(cupBoard({ standings: [standing({ display_name: long, pro_emoji: '🔥', me: false })] }))

    const row = tid(r, 'row')
    expect(row.get('[data-testid=player-name-text]').classes()).toContain('truncate')
    expect(row.get('[data-testid=pro-emoji]').classes()).toContain('shrink-0')
    expect(row.get('[data-testid=points]').classes()).toContain('shrink-0')
  })

  it('my days: the points, the ones that count', async () => {
    const r = await render()

    const days = r.wrapper.findAll('[data-testid=day]')
    expect(days).toHaveLength(7)
    expect(days[0]?.attributes('aria-label')).toBe('День 1: 12 очков, в зачёте')
    expect(days[0]?.attributes('data-counted')).toBe('true')
    expect(days[2]?.attributes('aria-label')).toBe('День 3: не сыгран')
    expect(tid(r, 'days-hint').text()).toContain('5 лучших')
  })

  it('the rules: best 5 days, one attempt, a typo is 0', async () => {
    const r = await render()

    expect(tid(r, 'rule-round').text()).toContain('Одна попытка в день')
    expect(tid(r, 'rule-points').text()).toContain('с опечаткой — 0 очков')
    expect(tid(r, 'rule-best').text()).toBe('В зачёт идут 5 лучших дней из 7.')
    expect(tid(r, 'rule-summary').text()).toContain('20:00')
  })
})

describe('CupView: the states of the cup', () => {
  it('waiting for the start', async () => {
    const r = await render(cupBoard({ cup: { ...cupFields({ status: 'scheduled', day: null }), rules: cupRules() }, me: { ...cupBoard().me!, today: { state: 'not_available', day: null, attempt_id: null, points: null, expires_at: null } } }))

    expect(tid(r, 'cup-status').text()).toBe('Ждём старта: 1 декабря, 00:00')
    expect(has(r, 'play-btn')).toBe(false)
  })

  it('running: "Day N of 7"', async () => {
    const r = await render()

    expect(tid(r, 'cup-status').text()).toBe('День 3 из 7')
    expect(tid(r, 'cup-card').attributes('data-status')).toBe('running')
  })

  it('finished: my final place, no round', async () => {
    const board = cupBoard()
    const r = await render(cupBoard({ cup: { ...cupFields({ status: 'finished', day: null }), rules: cupRules() }, me: { ...board.me!, final_rank: 2, today: { state: 'not_available', day: null, attempt_id: null, points: null, expires_at: null } } }))

    expect(tid(r, 'cup-status').text()).toBe('Кубок завершён')
    expect(tid(r, 'my-place').text()).toBe('Итоговое место: 2')
    expect(has(r, 'play-btn')).toBe(false)
    expect(has(r, 'rename-btn')).toBe(false)
  })

  it('canceled', async () => {
    const r = await render(cupBoard({ cup: { ...cupFields({ status: 'canceled', day: null }), rules: cupRules() } }))

    expect(tid(r, 'cup-status').text()).toBe('Кубок отменён')
    expect(has(r, 'play-btn')).toBe(false)
  })

  it('on pause: why, and no round', async () => {
    const r = await render(cupBoard({ cup: { ...cupFields({ paused: 'bot_removed' }), rules: cupRules() } }))

    expect(tid(r, 'cup-status').text()).toBe('Кубок на паузе: бота удалили из группы')
    expect(has(r, 'play-btn')).toBe(false)
  })

  it('a stranger and a missing cup: one state "unavailable"', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_not_member'))

    expect(state(r)).toBe('unavailable')
    expect(r.wrapper.text()).toContain('Кубок недоступен')
  })

  it('the switched off cup', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_unavailable'))

    expect(state(r)).toBe('off')
  })

  it('no connection: "Retry"', async () => {
    const r = await render(apiError('network', 0, 'network'))

    expect(state(r)).toBe('error')
    vi.mocked(api.cups.show).mockResolvedValue(cupBoard())
    await tid(r, 'retry-btn').trigger('click')
    await flushPromises()
    expect(state(r)).toBe('ready')
  })
})

describe('CupView: my round of the day', () => {
  it('not started: "Play the round of the day" opens the round', async () => {
    const r = await render()

    await tid(r, 'play-btn').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5/round')
  })

  it('on: "Continue" and until when (the learner\'s zone)', async () => {
    const r = await render(withToday({ state: 'active', day: 3, attempt_id: 77, points: 4, expires_at: '2026-12-02T10:10:00.000Z' }))

    expect(tid(r, 'active-until').text()).toBe('Раунд идёт до 15:10')
    expect(tid(r, 'continue-btn').text()).toBe('Продолжить раунд')
  })

  it('played: the points and "the next one tomorrow at 00:00" (Tashkent midnight in the learner\'s zone)', async () => {
    const r = await render(withToday({ state: 'done', day: 3, attempt_id: 77, points: 14, expires_at: '2026-12-02T10:10:00.000Z' }))

    expect(tid(r, 'round-done').text()).toBe('Раунд сыгран: 14 очков. Следующий — завтра в 00:00.')
    expect(has(r, 'play-btn')).toBe(false)
  })

  it('played in a learner zone of Moscow: the next round at 22:00', async () => {
    useTodayStore().data = { timezone: 'Europe/Moscow' } as TodayResponse
    const r = await render(withToday({ state: 'done', day: 3, attempt_id: 77, points: 2, expires_at: null }))

    expect(tid(r, 'round-done').text()).toContain('завтра в 22:00')
  })

  it('the last day played: no "tomorrow"', async () => {
    const board = cupBoard({ cup: { ...cupFields({ day: 7 }), rules: cupRules() } })
    const r = await render(cupBoard({ ...board, me: { ...board.me!, today: { state: 'done', day: 7, attempt_id: 1, points: 20, expires_at: null } } }))

    expect(tid(r, 'round-done').text()).toBe('Раунд сыгран: 20 очков. Это был последний день кубка.')
  })

  it('one who left the chat: no new rounds', async () => {
    const board = cupBoard()
    const r = await render(cupBoard({ me: { ...board.me!, status: 'left' } }))

    expect(tid(r, 'me-left').text()).toContain('Вы вышли из группы')
    expect(has(r, 'play-btn')).toBe(false)
  })

  it('a member who has not joined: "Join" by id (the Telegram name), then the table again', async () => {
    vi.mocked(api.cups.join).mockResolvedValue({} as never)
    const r = await render(cupBoard({ me: null }))

    expect(tid(r, 'not-joined').text()).toContain('ещё не участвуете')
    vi.mocked(api.cups.show).mockResolvedValue(cupBoard())
    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(api.cups.join).toHaveBeenCalledWith(5)
    expect(api.cups.show).toHaveBeenCalledTimes(2)
    expect(has(r, 'play-btn')).toBe(true)
  })
})

describe('CupView: the name in the tournament', () => {
  it('changes it and reads the table again', async () => {
    vi.mocked(api.cups.rename).mockResolvedValue({} as never)
    const r = await render()

    await tid(r, 'rename-btn').trigger('click')
    expect(body().querySelector('[data-testid=rename-hint]')?.textContent).toContain('ещё 3 раза')
    const input = body().querySelector('[data-testid=rename-input]') as HTMLInputElement
    input.value = 'Новое имя'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(body().querySelector('[data-testid=rename-save]') as HTMLButtonElement).click()
    await flushPromises()

    expect(api.cups.rename).toHaveBeenCalledWith(5, 'Новое имя')
    expect(api.cups.show).toHaveBeenCalledTimes(2)
    expect(body().querySelector('[data-testid=rename-sheet]')).toBeNull()
  })

  it('the limit of changes is said in the sheet', async () => {
    vi.mocked(api.cups.rename).mockRejectedValue(apiError('conflict', 409, 'cup_rename_limit'))
    const r = await render()

    await tid(r, 'rename-btn').trigger('click')
    const input = body().querySelector('[data-testid=rename-input]') as HTMLInputElement
    input.value = 'X'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(body().querySelector('[data-testid=rename-save]') as HTMLButtonElement).click()
    await flushPromises()

    expect(body().querySelector('[data-testid=rename-error]')?.textContent).toContain('не больше 3 раз')
  })

  it('no changes left: no button', async () => {
    const board = cupBoard()
    const r = await render(cupBoard({ me: { ...board.me!, renames_left: 0 } }))

    expect(has(r, 'rename-btn')).toBe(false)
  })
})

describe('CupView: cancel (an administrator of the chat)', () => {
  it('no `can_manage`: no button', async () => {
    const r = await render()

    expect(has(r, 'cancel-btn')).toBe(false)
  })

  it('cancels after the confirmation and reads the cup again', async () => {
    vi.mocked(api.cups.cancel).mockResolvedValue({} as never)
    const r = await render(cupBoard({ can_manage: true }))

    await tid(r, 'cancel-btn').trigger('click')
    expect(api.cups.cancel).not.toHaveBeenCalled()
    ;(body().querySelector('[data-testid=cancel-confirm]') as HTMLButtonElement).click()
    await flushPromises()

    expect(api.cups.cancel).toHaveBeenCalledWith(5)
    expect(api.cups.show).toHaveBeenCalledTimes(2)
  })

  it('the server says "not an administrator": only an administrator of the group can', async () => {
    vi.mocked(api.cups.cancel).mockRejectedValue(apiError('forbidden', 403, 'cup_not_chat_admin'))
    const r = await render(cupBoard({ can_manage: true }))

    await tid(r, 'cancel-btn').trigger('click')
    ;(body().querySelector('[data-testid=cancel-confirm]') as HTMLButtonElement).click()
    await flushPromises()

    expect(body().querySelector('[data-testid=cancel-error]')?.textContent).toBe('Это может сделать только администратор группы.')
  })
})

describe('CupView: uz', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()

    expect(tid(r, 'cup-status').text()).toBe('7 kundan 3-kun')
    expect(tid(r, 'play-btn').text()).toBe("Kun raundini o'ynash")
    expect(tid(r, 'my-points').text()).toBe('20 ball')
    expect(r.wrapper.findAll('[data-testid=left]')[0]?.text()).toBe('chiqib ketgan')
  })
})
