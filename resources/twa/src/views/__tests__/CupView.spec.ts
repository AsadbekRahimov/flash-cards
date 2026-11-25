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

  it('a participant who left the chat (403): the same "unavailable" as a stranger, without the group', async () => {
    const r = await render()
    expect(r.wrapper.text()).toContain('English Friends')

    // The screen opened again (the table of this cup is still in the store): the read is refused, nothing of the old table stays.
    vi.mocked(api.cups.show).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))
    r.wrapper.unmount()
    const again = mount(CupView, { props: { id: 5 }, global: { plugins: [r.router] }, attachTo: document.body })
    await flushPromises()

    expect(again.get('[data-testid=cup-view]').attributes('data-state')).toBe('unavailable')
    expect(again.text()).toContain('Кубок недоступен')
    expect(again.text()).not.toContain('English Friends')
    expect(again.find('[data-testid=cup-standings]').exists()).toBe(false)
  })

  it.each([
    ['paused', apiError('conflict', 409, 'cup_paused', { reason: 'bot_removed' }), 'Кубок на паузе', 'бота удалили из группы'],
    ['bot not admin', apiError('conflict', 409, 'cup_unverifiable', { reason: 'bot_not_admin' }), 'Нужен админ группы', 'он не администратор группы'],
    ['telegram', apiError('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' }), 'Не удалось проверить участие', 'Telegram сейчас не отвечает'],
    ['busy', apiError('rate_limited', 429, 'cup_check_busy'), 'Не удалось проверить участие', 'Подождите несколько секунд'],
  ])('a failed check of a participant (%s): its words and "Retry" by hand, never a loop', async (_name, error, title, text) => {
    vi.useFakeTimers()
    try {
      const r = await render(error)

      expect(state(r)).toBe('error')
      expect(r.wrapper.text()).toContain(title)
      expect(r.wrapper.text()).toContain(text)
      await vi.advanceTimersByTimeAsync(60_000)
      expect(api.cups.show).toHaveBeenCalledTimes(1)

      vi.mocked(api.cups.show).mockResolvedValue(cupBoard())
      await tid(r, 'retry-btn').trigger('click')
      await flushPromises()
      expect(api.cups.show).toHaveBeenCalledTimes(2)
      expect(state(r)).toBe('ready')
    } finally {
      vi.useRealTimers()
    }
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

  it('`next_day_at` of the server: the time of the next round in the learner\'s zone (no count of midnights)', async () => {
    useTodayStore().data = { timezone: 'Europe/Moscow' } as TodayResponse
    const board = withToday({ state: 'done', day: 3, attempt_id: 77, points: 14, expires_at: null })
    // A server's moment the client would not count itself (01:30 in Tashkent): only the field is believed.
    const r = await render(cupBoard({ ...board, cup: { ...board.cup, next_day_at: '2026-12-02T20:30:00.000Z' } }))

    expect(tid(r, 'round-done').text()).toBe('Раунд сыгран: 14 очков. Следующий — завтра в 23:30.')
    expect(tid(r, 'round-done').attributes('data-next')).toBe('at')
    expect(has(r, 'refresh-btn')).toBe(false)
  })

  it('`next_day_at: null`: the last day, even though the client would count one more', async () => {
    const board = withToday({ state: 'done', day: 3, attempt_id: 77, points: 14, expires_at: null })
    const r = await render(cupBoard({ ...board, cup: { ...board.cup, next_day_at: null } }))

    expect(tid(r, 'round-done').text()).toBe('Раунд сыгран: 14 очков. Это был последний день кубка.')
  })

  it('`next_day_at` already passed (read before midnight): the round of the new day is there, "Refresh" reads again', async () => {
    const board = withToday({ state: 'done', day: 3, attempt_id: 77, points: 14, expires_at: null })
    const r = await render(cupBoard({ ...board, cup: { ...board.cup, next_day_at: '2026-12-02T09:00:00.000Z' } }))

    expect(tid(r, 'round-done').text()).toBe('Раунд сыгран: 14 очков. Раунд нового дня уже доступен.')
    vi.mocked(api.cups.show).mockResolvedValue(cupBoard())
    await tid(r, 'refresh-btn').trigger('click')
    await flushPromises()
    expect(api.cups.show).toHaveBeenCalledTimes(2)
    expect(has(r, 'play-btn')).toBe(true)
  })

  it('no `next_day_at` (an older server): the client\'s count of the cup\'s midnight', async () => {
    const r = await render(withToday({ state: 'done', day: 3, attempt_id: 77, points: 14, expires_at: null }))

    expect('next_day_at' in cupBoard().cup).toBe(false)
    expect(tid(r, 'round-done').text()).toBe('Раунд сыгран: 14 очков. Следующий — завтра в 00:00.')
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

async function typeAndSave(r: Rendered, value: string) {
  await tid(r, 'rename-btn').trigger('click')
  const input = body().querySelector('[data-testid=rename-input]') as HTMLInputElement
  input.value = value
  input.dispatchEvent(new Event('input'))
  await flushPromises()
  ;(body().querySelector('[data-testid=rename-save]') as HTMLButtonElement).click()
  await flushPromises()
}

describe('CupView: joining by the id', () => {
  it('403 on "Join" (any refusal to a non-participant): "join by the invitation link of the group", no guess why', async () => {
    vi.mocked(api.cups.join).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))
    const r = await render(cupBoard({ me: null }))

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'join-error').text()).toBe('Вступить можно по ссылке-приглашению из группы.')
    expect(state(r)).toBe('ready')
    expect(api.cups.show).toHaveBeenCalledTimes(1)
  })

  it('uz', async () => {
    setLocale('uz')
    vi.mocked(api.cups.join).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))
    const r = await render(cupBoard({ me: null }))

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'join-error').text()).toBe("Guruhdagi taklif havolasi orqali qo'shilish mumkin.")
  })

  it('a participant who joins again gets the reason (409 paused)', async () => {
    vi.mocked(api.cups.join).mockRejectedValue(apiError('conflict', 409, 'cup_paused', { reason: 'blocked' }))
    const r = await render(cupBoard({ me: null }))

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'join-error').text()).toBe('Кубки в этой группе сейчас недоступны.')
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

  it.each([
    ['paused', apiError('conflict', 409, 'cup_paused', { reason: 'bot_removed' }), 'Кубок на паузе: бота удалили из группы.'],
    ['bot not admin', apiError('conflict', 409, 'cup_unverifiable', { reason: 'bot_not_admin' }), 'он не администратор группы'],
    ['telegram', apiError('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' }), 'Telegram сейчас не отвечает'],
    ['busy', apiError('rate_limited', 429, 'cup_check_busy'), 'Подождите несколько секунд'],
    ['closed', apiError('gone', 410, 'cup_closed'), 'Кубок уже завершён.'],
  ])('a refusal (%s): its words in the sheet, the sheet stays with the typed name', async (_name, error, text) => {
    vi.mocked(api.cups.rename).mockRejectedValue(error)
    const r = await render()

    await typeAndSave(r, 'Новое имя')

    expect(body().querySelector('[data-testid=rename-sheet]')).not.toBeNull()
    expect(body().querySelector('[data-testid=rename-error]')?.textContent).toContain(text)
    expect((body().querySelector('[data-testid=rename-input]') as HTMLInputElement).value).toBe('Новое имя')
    expect(api.cups.show).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['empty', 'Введите имя.'],
    ['too_long', 'Имя слишком длинное: не больше 32 символов.'],
    ['marks', 'В имени слишком много значков над буквами.'],
    ['link', 'Имя не должно быть похоже на ссылку или адрес сайта.'],
    ['mention', 'В имени не может быть слов с @ в начале — в чате это станет упоминанием.'],
    ['command', 'В имени не может быть слов с / в начале — в чате это станет командой.'],
    ['tag', 'В имени не может быть слов с # в начале или $ перед латинской буквой — в чате это станет тегом.'],
  ])('a name refused for `%s` says why in the sheet', async (reason, text) => {
    vi.mocked(api.cups.rename).mockRejectedValue(apiError('validation', 422, 'cup_display_name_invalid', { reason }))
    const r = await render()

    await typeAndSave(r, 'X')

    expect(body().querySelector('[data-testid=rename-error]')?.textContent).toBe(text)
    expect(body().querySelector('[data-testid=rename-sheet]')).not.toBeNull()
  })

  it('uz: a refused name and a failed check in Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.cups.rename).mockRejectedValue(apiError('validation', 422, 'cup_display_name_invalid', { reason: 'command' }))
    const r = await render()

    await typeAndSave(r, '/start')
    expect(body().querySelector('[data-testid=rename-error]')?.textContent).toBe("Ismda / bilan boshlanadigan so'z bo'lmasin — chatda bu buyruqqa aylanadi.")
  })

  it('403 (he has left the chat): the sheet closes and the screen is read again - "not available", no group', async () => {
    vi.mocked(api.cups.rename).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))
    const r = await render()
    vi.mocked(api.cups.show).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))

    await typeAndSave(r, 'Новое имя')

    expect(body().querySelector('[data-testid=rename-sheet]')).toBeNull()
    expect(api.cups.show).toHaveBeenCalledTimes(2)
    expect(state(r)).toBe('unavailable')
    expect(r.wrapper.text()).toContain('Кубок недоступен')
    expect(r.wrapper.text()).not.toContain('English Friends')
    expect(has(r, 'cup-standings')).toBe(false)
  })

  it('the counter of the sheet counts graphemes: an emoji family is one', async () => {
    const r = await render()

    await tid(r, 'rename-btn').trigger('click')
    expect(body().querySelector('[data-testid=rename-count]')?.textContent).toBe('2/32')
    const input = body().querySelector('[data-testid=rename-input]') as HTMLInputElement
    input.value = 'Me 👨‍👩‍👧‍👦'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(body().querySelector('[data-testid=rename-count]')?.textContent).toBe('4/32')
    expect(Number(input.getAttribute('maxlength'))).toBeGreaterThanOrEqual(128)
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
