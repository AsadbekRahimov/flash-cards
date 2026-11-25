import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupRoundView from '../CupRoundView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupsStore } from '@/stores/cups'
import { cupBoard, cupRound, cupTask, finishedRound } from '@/api/__tests__/cupFixtures'
import type { CupAnswerResponse, CupBoardResponse, CupRound, CupToday } from '@/api/types'

/** The round of the day of a cup (docs/33 §5.2, §7.1): start, resume, answer, the repeat of a lost reply, expiry, summary, 404. */

vi.mock('@/api', () => ({
  api: {
    cups: { show: vi.fn(), startRound: vi.fn(), round: vi.fn(), answer: vi.fn(), list: vi.fn() },
    gameRuns: { audioBlob: vi.fn() },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const back = vi.hoisted(() => ({ target: null as unknown }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: (target: unknown) => (back.target = target) }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ haptic: vi.fn(), notificationHaptic: vi.fn(), selectionHaptic: vi.fn() }),
}))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const boardWith = (today: CupToday): CupBoardResponse => {
  const board = cupBoard()
  return cupBoard({ me: { ...board.me!, today } })
}

const NOT_STARTED: CupToday = { state: 'not_started', day: 3, attempt_id: null, points: null, expires_at: null }
const ACTIVE: CupToday = { state: 'active', day: 3, attempt_id: 77, points: 0, expires_at: '2026-12-02T10:10:00.000Z' }
const DONE: CupToday = { state: 'done', day: 3, attempt_id: 77, points: 14, expires_at: '2026-12-02T10:10:00.000Z' }

const answered = (over: Partial<CupAnswerResponse> = {}): CupAnswerResponse => ({
  verdict: 'correct',
  points: 2,
  reveal: { correct_id: 'o1', correct_text: 'яблоко' },
  finished: false,
  total_points: 2,
  replayed: false,
  round: cupRound({ answered: 1, correct: 1, points: 2, position: 2, current: cupTask({ position: 2, id: 'e2', kind: 'spell', prompt: 'груша', answer_length: 4, options: undefined }) }),
  ...over,
})

async function render(board: CupBoardResponse | Error = boardWith(NOT_STARTED)) {
  if (board instanceof Error) vi.mocked(api.cups.show).mockRejectedValue(board)
  else vi.mocked(api.cups.show).mockResolvedValue(board)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/round', name: 'cup-round', component: stub },
    ],
  })
  await router.push('/cups/5/round')
  const wrapper = mount(CupRoundView, { props: { id: 5 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const status = (r: Rendered) => tid(r, 'cup-round-view').attributes('data-status')
async function click(r: Rendered, id: string) {
  await tid(r, id).trigger('click')
  await flushPromises()
}

async function started(round: CupRound = cupRound()) {
  vi.mocked(api.cups.startRound).mockResolvedValue(round)
  const r = await render()
  await click(r, 'start-btn')
  return r
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  vi.mocked(api.gameRuns.audioBlob).mockResolvedValue(new Blob())
  back.target = null
  setLocale('ru')
  document.body.innerHTML = ''
})

afterEach(() => {
  vi.useRealTimers()
})

describe('CupRoundView: start', () => {
  it('asks first: one attempt, 10 tasks, 10 minutes, and who the learner plays for', async () => {
    const r = await render()

    expect(status(r)).toBe('confirm')
    expect(tid(r, 'caption').text()).toBe('Играю за: English Friends · День 3')
    expect(tid(r, 'confirm-text').text()).toContain('Одна попытка: 10 заданий, 10 минут')
    expect(api.cups.startRound).not.toHaveBeenCalled()
  })

  it('"Start" opens the attempt with its first task; a double tap is one request', async () => {
    let resolve!: (round: CupRound) => void
    vi.mocked(api.cups.startRound).mockReturnValue(new Promise((yes) => (resolve = yes)))
    const r = await render()

    await tid(r, 'start-btn').trigger('click')
    await tid(r, 'start-btn').trigger('click')
    expect(tid(r, 'start-btn').attributes('aria-busy')).toBe('true')
    resolve(cupRound())
    await flushPromises()

    expect(api.cups.startRound).toHaveBeenCalledTimes(1)
    expect(api.cups.startRound).toHaveBeenCalledWith(5)
    expect(status(r)).toBe('playing')
    expect(tid(r, 'task-prompt').text()).toBe('apple')
    expect(tid(r, 'caption').text()).toBe('Играю за: English Friends · День 3')
    expect(tid(r, 'position').text()).toBe('1 / 10')
  })

  it('the table the cup screen has just read is not read again', async () => {
    const boards = useCupBoardStore()
    boards.cupId = 5
    boards.board = boardWith(NOT_STARTED)
    boards.loadedAt = Date.now()
    const r = await render()

    expect(api.cups.show).not.toHaveBeenCalled()
    expect(status(r)).toBe('confirm')
  })

  it('Telegram down at the start: a toast, the learner may start again', async () => {
    vi.mocked(api.cups.startRound).mockRejectedValueOnce(apiError('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' })).mockResolvedValueOnce(cupRound())
    const r = await render()

    await click(r, 'start-btn')
    expect(showErrorToast).toHaveBeenCalledWith(expect.stringContaining('Telegram сейчас не отвечает'))
    expect(status(r)).toBe('confirm')

    await click(r, 'start-btn')
    expect(status(r)).toBe('playing')
  })

  it('a start the cup refuses (not started) is a state with the way to the cup', async () => {
    vi.mocked(api.cups.startRound).mockRejectedValue(apiError('conflict', 409, 'cup_not_started'))
    const r = await render()

    await click(r, 'start-btn')

    expect(status(r)).toBe('unavailable')
    expect(r.wrapper.text()).toContain('Кубок ещё не начался')
    await click(r, 'to-cup')
    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')
  })

  it('played in another tab meanwhile (409 cup_round_done): its summary', async () => {
    vi.mocked(api.cups.startRound).mockRejectedValue(apiError('conflict', 409, 'cup_round_done', { points: 14 }))
    vi.mocked(api.cups.round).mockResolvedValue(finishedRound())
    const r = await render()
    vi.mocked(api.cups.show).mockResolvedValue(boardWith(DONE))

    await click(r, 'start-btn')

    expect(api.cups.round).toHaveBeenCalledWith(77)
    expect(status(r)).toBe('summary')
  })

  it('not a participant: no round', async () => {
    const r = await render(cupBoard({ me: null }))

    expect(status(r)).toBe('unavailable')
    expect(r.wrapper.text()).toContain('только участники кубка')
  })

  it('a stranger\'s cup: "unavailable", no retry', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_not_member'))

    expect(status(r)).toBe('unavailable')
    expect(r.wrapper.text()).toContain('Кубок открывается по ссылке')
  })
})

describe('CupRoundView: resume', () => {
  it('an attempt that is on (another tab, a reload) goes on with the same tasks, no confirmation', async () => {
    vi.mocked(api.cups.round).mockResolvedValue(cupRound({ answered: 3, points: 4, position: 4, current: cupTask({ position: 4, id: 'e4' }) }))
    const r = await render(boardWith(ACTIVE))

    expect(api.cups.round).toHaveBeenCalledWith(77)
    expect(api.cups.startRound).not.toHaveBeenCalled()
    expect(status(r)).toBe('playing')
    expect(tid(r, 'position').text()).toBe('4 / 10')
    expect(tid(r, 'score').text()).toBe('4')
  })

  it('an attempt over opens on its summary', async () => {
    vi.mocked(api.cups.round).mockResolvedValue(finishedRound())
    const r = await render(boardWith(DONE))

    expect(status(r)).toBe('summary')
  })

  it('404 (a stranger\'s or a missing attempt) leads to the cup', async () => {
    vi.mocked(api.cups.round).mockRejectedValue(apiError('not_found', 404, 'cup_round_not_found'))
    const r = await render(boardWith(ACTIVE))
    await flushPromises()

    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')
  })
})

describe('CupRoundView: answers', () => {
  it('sends the option with a request_id; the verdict and the right answer come after the answer', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockResolvedValue(answered())

    expect(r.wrapper.text()).not.toContain('Правильный ответ')
    await click(r, 'option-0')

    const [attemptId, body] = vi.mocked(api.cups.answer).mock.calls[0]!
    expect(attemptId).toBe(77)
    expect(body.element_id).toBe('e1')
    expect(body.answer).toEqual({ option_id: 'o1' })
    expect(body.request_id).toMatch(/^[0-9a-f-]{36}$/)
    expect(status(r)).toBe('reveal')
    expect(tid(r, 'verdict-label').attributes('data-verdict')).toBe('correct')
    expect(tid(r, 'chip-points').text()).toContain('2')
    expect(tid(r, 'right-answer').text()).toBe('яблоко')

    await click(r, 'next-btn')
    expect(status(r)).toBe('playing')
    expect(has(r, 'spell-task')).toBe(true)
    expect(tid(r, 'position').text()).toBe('2 / 10')
  })

  it('a double tap on an option is one request', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockReturnValue(new Promise(() => {}))

    await tid(r, 'option-0').trigger('click')
    await tid(r, 'option-1').trigger('click')
    await flushPromises()

    expect(api.cups.answer).toHaveBeenCalledTimes(1)
  })

  it('a lost reply: "Retry" sends the SAME request_id and the same answer', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'down')).mockResolvedValueOnce(answered())

    await click(r, 'option-2')
    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(status(r)).toBe('playing')
    await click(r, 'resend-btn')

    const [first, second] = vi.mocked(api.cups.answer).mock.calls
    expect(second![1].request_id).toBe(first![1].request_id)
    expect(second![1].answer).toEqual({ option_id: 'o3' })
    expect(status(r)).toBe('reveal')
  })

  it('the next task gets a new request_id', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockResolvedValue(answered())
    await click(r, 'option-0')
    await click(r, 'next-btn')

    await tid(r, 'answer-input').setValue('pear')
    await click(r, 'check-btn')

    const [first, second] = vi.mocked(api.cups.answer).mock.calls
    expect(second![1].element_id).toBe('e2')
    expect(second![1].answer).toEqual({ text: 'pear' })
    expect(second![1].request_id).not.toBe(first![1].request_id)
  })

  it('a typo is 0 points: no chip of points, the verdict says "typo"', async () => {
    const spell = cupRound({ current: cupTask({ id: 'e1', kind: 'spell', prompt: 'груша', answer_length: 4, options: undefined }) })
    const r = await started(spell)
    vi.mocked(api.cups.answer).mockResolvedValue(answered({ verdict: 'typo', points: 0, reveal: { correct_text: 'pear' } }))

    await tid(r, 'answer-input').setValue('paer')
    await click(r, 'check-btn')

    expect(tid(r, 'verdict-label').attributes('data-verdict')).toBe('typo')
    expect(has(r, 'chip-points')).toBe(false)
  })

  it('`listen` plays the clip of the attempt (`audio_url`)', async () => {
    const r = await started(cupRound({ current: cupTask({ kind: 'listen', prompt: undefined, audio: true, audio_url: '/api/twa/cup-rounds/77/audio/e1' }) }))

    expect(has(r, 'listen-btn')).toBe(true)
    expect(api.gameRuns.audioBlob).toHaveBeenCalledWith('/api/twa/cup-rounds/77/audio/e1', expect.anything())
  })

  it('409 cup_round_expired: the attempt is read again and the summary shows it', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockRejectedValue(apiError('conflict', 409, 'cup_round_expired', { points: 4 }))
    vi.mocked(api.cups.round).mockResolvedValue(finishedRound({ finish_reason: 'expired', points: 4, correct: 2 }))

    await click(r, 'option-0')

    expect(status(r)).toBe('summary')
    expect(tid(r, 'summary-title').text()).toBe('Время вышло')
    expect(tid(r, 'summary-points').text()).toContain('4')
    expect(tid(r, 'summary-expired').text()).toContain('сохранены')
  })

  it('409 answer_already_accepted (another tab): the verdict counted, "sent earlier"', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'wrong' }))
    vi.mocked(api.cups.round).mockResolvedValue(cupRound({ position: 2, current: cupTask({ position: 2, id: 'e2' }) }))

    await click(r, 'option-0')

    expect(status(r)).toBe('reveal')
    expect(tid(r, 'verdict-label').attributes('data-verdict')).toBe('wrong')
    expect(has(r, 'earlier-note')).toBe(true)
  })

  it('410 cup_closed: the cup is over', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockRejectedValue(apiError('gone', 410, 'cup_closed'))

    await click(r, 'option-0')

    expect(status(r)).toBe('closed')
  })
})

describe('CupRoundView: the time of the server', () => {
  it('counts down to `expires_at` by `server_now`, and when it runs out reads the attempt (the server ends it)', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    // The device clock runs 5 minutes ahead of the server: the timer still shows the server's 10 minutes.
    vi.setSystemTime(new Date('2026-12-02T10:05:00.000Z'))
    const r = await started()

    expect(tid(r, 'timer').text()).toBe('10:00')
    expect(tid(r, 'timer').attributes('role')).toBe('timer')
    vi.mocked(api.cups.round).mockResolvedValue(finishedRound({ finish_reason: 'expired', points: 0, correct: 0 }))

    vi.advanceTimersByTime(9 * 60 * 1000)
    await flushPromises()
    expect(tid(r, 'timer').text()).toBe('1:00')
    expect(api.cups.round).not.toHaveBeenCalled()

    vi.advanceTimersByTime(63 * 1000)
    await flushPromises()

    expect(api.cups.round).toHaveBeenCalledTimes(1)
    expect(status(r)).toBe('summary')
    expect(tid(r, 'summary-title').text()).toBe('Время вышло')
  })
})

describe('CupRoundView: the summary', () => {
  it('the points of the day, the right answers, the place after the round', async () => {
    vi.mocked(api.cups.round).mockResolvedValue(finishedRound())
    const r = await render(boardWith(DONE))
    await flushPromises()

    expect(tid(r, 'summary-title').text()).toBe('Раунд сыгран')
    expect(tid(r, 'summary-points').text()).toBe('14 / 20')
    expect(tid(r, 'summary-correct').text()).toBe('Верно: 7 из 10')
    // The table is read again for the place after this round.
    expect(api.cups.show).toHaveBeenCalledTimes(2)
    expect(tid(r, 'summary-place').text()).toBe('Ваше место: 2')
    const rows = r.wrapper.findAll('[data-testid=review-row]')
    expect(rows.map((row) => row.get('[data-testid=review-answer]').text())).toEqual(['яблоко', 'apple', 'tea'])
    expect(rows.map((row) => row.get('[data-testid=review-verdict]').attributes('data-verdict'))).toEqual(['correct', 'typo', 'none'])
    expect(rows[2]?.text()).toContain('Без ответа')

    await click(r, 'to-cup')
    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')
  })

  it('the last answer finishes the round: "To the summary", then the summary; Today learns the day is played', async () => {
    const r = await started()
    vi.mocked(api.cups.answer).mockResolvedValue(answered({ finished: true, round: finishedRound() }))

    await click(r, 'option-0')
    expect(tid(r, 'next-btn').text()).toContain('итог')
    await click(r, 'next-btn')

    expect(status(r)).toBe('summary')
    expect(useCupsStore().played[5]).toBe(3)
  })
})

describe('CupRoundView: leaving', () => {
  it('"Back" during the round asks first (the time does not stop); "Leave" opens the cup', async () => {
    const r = await started()

    ;(back.target as () => void)()
    await flushPromises()
    expect(document.body.querySelector('[data-testid=exit-sheet]')?.textContent).toContain('Время не остановится')
    ;(document.body.querySelector('[data-testid=leave-btn]') as HTMLButtonElement).click()
    await flushPromises()

    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')
  })
})

describe('CupRoundView: uz', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()

    expect(tid(r, 'start-btn').text()).toBe('Raundni boshlash')
    expect(tid(r, 'caption').text()).toBe("O'ynayapman: English Friends · 3-kun")
  })
})
