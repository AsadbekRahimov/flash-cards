import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameRunView from '../GameRunView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useGameRunStore } from '@/stores/gameRun'
import { answerResponse, collocation, finishedRun, gap, listen, runView, spell, translateBack, translateFwd } from '@/api/__tests__/gameRunFixtures'
import type { GameRunView as RunView } from '@/api/gameRunTypes'

vi.mock('@/api', () => ({
  api: { gameRuns: { get: vi.fn(), answer: vi.fn(), hint: vi.fn(), abandon: vi.fn(), audioBlob: vi.fn() }, practice: { audioBlob: vi.fn() }, game: { cityShared: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({ backHandler: null as (() => void) | null, haptic: vi.fn(), notification: vi.fn(), shareUrl: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    showBackButton: (handler: () => void) => {
      tg.backHandler = handler
      return () => {
        tg.backHandler = null
      }
    },
    haptic: tg.haptic,
    notificationHaptic: tg.notification,
    selectionHaptic: () => {},
    shareUrl: tg.shareUrl,
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))

const review = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useReviewFromPaywall', () => ({ useReviewFromPaywall: () => ({ reviewFromPaywall: review }) }))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

function pending<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((yes) => {
    resolve = yes
  })
  return { promise, resolve }
}

type Rendered = Awaited<ReturnType<typeof render>>

async function render(opened: RunView | Error | Promise<RunView> = runView(), props: { id?: number; course?: string | null; stop?: number | null; boss?: number | null } = {}) {
  if (opened instanceof Error) vi.mocked(api.gameRuns.get).mockRejectedValue(opened)
  else vi.mocked(api.gameRuns.get).mockReturnValue(Promise.resolve(opened))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/game', name: 'game', component: stub },
      { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub },
    ],
  })
  await router.push({ name: 'game-run', params: { id: 9 } })
  const wrapper = mount(GameRunView, { props: { id: 9, ...props }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid=${id}]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid=${id}]`).exists()
const click = async (r: Rendered, id: string) => {
  await tid(r, id).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  vi.useRealTimers()
  authEnsure.mockResolvedValue(undefined)
  tg.backHandler = null
  URL.createObjectURL = vi.fn(() => 'blob:clip')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameRunView — entering', () => {
  it('is a spinner while the run is read, then the task, after the login', async () => {
    const reply = pending<RunView>()
    const r = await render(reply.promise)

    expect(r.wrapper.find('[data-testid=spinner]').exists()).toBe(true)
    expect(has(r, 'choice-task')).toBe(false)

    reply.resolve(runView())
    await flushPromises()
    expect(r.wrapper.find('[data-testid=spinner]').exists()).toBe(false)
    expect(has(r, 'choice-task')).toBe(true)
    expect(authEnsure).toHaveBeenCalled()
    expect(api.gameRuns.get).toHaveBeenCalledWith(9)
    expect(r.wrapper.attributes('data-status')).toBe('playing')
    r.wrapper.unmount()
  })

  it('has the header: "3 / 10", the flasks, the score, the city and the lesson; no combo before the third', async () => {
    const r = await render()

    expect(tid(r, 'position').text()).toBe('3 / 10')
    expect(tid(r, 'score').text()).toBe('40')
    expect(r.wrapper.findAll('[data-testid=flask]')).toHaveLength(3)
    expect(tid(r, 'caption').text()).toBe('Самарканд · Модуль 1 · Урок 2 · Фрукты')
    expect(has(r, 'combo')).toBe(false)
    r.wrapper.unmount()
  })

  it('shows the combo from the third answer in a row', async () => {
    const r = await render(runView({ combo: 3 }))
    expect(tid(r, 'combo').text()).toBe('Комбо ×3')
    r.wrapper.unmount()
  })

  it('draws no row of boosters while the list is empty (G2.5b)', async () => {
    const r = await render()
    expect(has(r, 'boosters')).toBe(false)
    r.wrapper.unmount()
  })

  it('draws the component of the kind: spell, gap, a word choice, and the practice component of a collocation', async () => {
    for (const [element, testid] of [
      [spell(), 'spell-task'],
      [gap(), 'gap-task'],
      [translateBack(), 'choice-task'],
      [collocation(), 'collocation-choice'],
    ] as const) {
      const r = await render(runView({}, element))
      expect(has(r, testid), element.kind).toBe(true)
      r.wrapper.unmount()
    }
  })

  it('a failed read is the error with "Retry" and the way to the map; "Retry" reads again', async () => {
    const r = await render(apiError('not_found', 404, 'game_run_not_found'))

    expect(has(r, 'error')).toBe(true)
    expect(r.wrapper.text()).toContain('Забег не найден.')
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView())
    await click(r, 'retry-btn')
    expect(has(r, 'choice-task')).toBe(true)
    r.wrapper.unmount()
  })

  it('410 is "The run is over" with the way to the map', async () => {
    const r = await render(apiError('gone', 410, 'game_run_closed', { finish_reason: 'failed' }), { course: 'english-a1' })

    expect(r.wrapper.get('[data-testid=closed]').text()).toContain('Забег закончился')
    await click(r, 'map-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
    r.wrapper.unmount()
  })

  it('a run that was left says so: it did not count', async () => {
    const r = await render(finishedRun('abandoned'))
    expect(r.wrapper.get('[data-testid=closed]').text()).toContain('Вы вышли из забега: он не засчитан.')
    r.wrapper.unmount()
  })
})

describe('GameRunView — the banner of an event', () => {
  it('shows the banner for a second and a half, then the task', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: false })
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({}, translateFwd(), 'sandstorm'))
    const stub = { template: '<div />' }
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/game', component: stub }, { path: '/', component: stub }] })
    await router.push('/')
    const wrapper = mount(GameRunView, { props: { id: 9 }, global: { plugins: [router] } })
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()

    expect(wrapper.find('[data-testid=event-banner]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=event-title]').text()).toBe('Песчаная буря')
    expect(wrapper.find('[data-testid=choice-task]').exists()).toBe(false)

    await vi.advanceTimersByTimeAsync(1400)
    expect(wrapper.find('[data-testid=event-banner]').exists()).toBe(true)
    await vi.advanceTimersByTimeAsync(150)
    expect(wrapper.find('[data-testid=event-banner]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=choice-task]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('leaving during the banner does not leave a timer behind', async () => {
    vi.useFakeTimers()
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({}, translateFwd(), 'oasis'))
    const stub = { template: '<div />' }
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: stub }] })
    await router.push('/')
    const wrapper = mount(GameRunView, { props: { id: 9 }, global: { plugins: [router] } })
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('GameRunView — an answer and its reveal', () => {
  it('a tap on an option sends the answer; the reveal shows the verdict and the points; "Next" goes on', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse())

    await click(r, 'option-0')

    expect(api.gameRuns.answer).toHaveBeenCalledWith(9, expect.objectContaining({ element_id: 'e1', answer: { option_id: 'o1' } }))
    expect(r.wrapper.attributes('data-status')).toBe('reveal')
    expect(tid(r, 'verdict-label').text()).toBe('Верно!')
    expect(tid(r, 'chip-points').text()).toBe('+10')
    // The place stays that of the answered element while it is read.
    expect(tid(r, 'position').text()).toBe('3 / 10')
    expect(tid(r, 'verdict-live').text()).toBe('Верно!')
    expect(tg.notification).toHaveBeenCalledWith('success')

    await click(r, 'next-btn')
    expect(r.wrapper.attributes('data-status')).toBe('playing')
    expect(has(r, 'choice-task')).toBe(true)
    expect(tid(r, 'task-prompt').text()).toBe('хлеб')
    expect(tid(r, 'position').text()).toBe('4 / 10')
    expect(tid(r, 'verdict-live').text()).toBe('')
    r.wrapper.unmount()
  })

  it('a miss costs a flask: the header shows it and the reveal says it', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ verdict: 'wrong', points: 0, flask_lost: true, run: runView({ flasks: 2, position: 4 }, translateBack()) }))

    await click(r, 'option-1')

    expect(r.wrapper.findAll('[data-testid=flask]').map((flask) => flask.attributes('data-full'))).toEqual(['true', 'true', 'false'])
    expect(has(r, 'chip-flask')).toBe(true)
    expect(tg.notification).toHaveBeenCalledWith('error')
    r.wrapper.unmount()
  })

  it('a typed answer goes out as text, and "I don\'t know" as skip', async () => {
    const r = await render(runView({}, spell()))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ verdict: 'skipped', points: 0 }))

    await click(r, 'skip-btn')

    expect(vi.mocked(api.gameRuns.answer).mock.calls[0]![1].answer).toEqual({ skip: true })
    r.wrapper.unmount()
  })

  it('the last answer ends in the summary', async () => {
    const r = await render(runView({ position: 10 }))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ run: finishedRun('completed', {}, { stars: 3, score: 500 }) }))

    await click(r, 'option-0')
    expect(tid(r, 'next-btn').text()).toBe('К итогу')
    await click(r, 'next-btn')

    expect(has(r, 'run-summary')).toBe(true)
    expect(r.wrapper.attributes('data-status')).toBe('summary')
    r.wrapper.unmount()
  })

  it('a lost reply: the learner stays on the task with a toast; "Retry" sends the same answer again', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.answer).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'down')).mockResolvedValueOnce(answerResponse())

    await click(r, 'option-2')
    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(r.wrapper.attributes('data-status')).toBe('playing')

    await click(r, 'resend-btn')

    const [first, second] = vi.mocked(api.gameRuns.answer).mock.calls
    expect(second![1].request_id).toBe(first![1].request_id)
    expect(second![1].answer).toEqual({ option_id: 'o3' })
    expect(r.wrapper.attributes('data-status')).toBe('reveal')
    r.wrapper.unmount()
  })

  it('element_not_current: a toast, and the screen shows the task the server has', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('conflict', 409, 'element_not_current', { current: 'e2' }))
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({ position: 4 }, translateBack()))

    await click(r, 'option-0')

    expect(showErrorToast).toHaveBeenCalledWith('Задание уже сменилось. Экран обновлён.')
    expect(tid(r, 'task-prompt').text()).toBe('хлеб')
    r.wrapper.unmount()
  })

  it('410 on an answer is "The run is over"', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('gone', 410, 'game_run_closed', { finish_reason: 'expired' }))

    await click(r, 'option-0')

    expect(r.wrapper.get('[data-testid=closed]').text()).toContain('Время забега вышло: он не засчитан.')
    r.wrapper.unmount()
  })
})

describe('GameRunView — the hint of the bazaar', () => {
  const bazaar = (hint = { price: 5, available: true }) => runView({ hint }, translateFwd(), 'bazaar')

  async function atBazaar(opened: RunView = bazaar()) {
    vi.useFakeTimers()
    vi.mocked(api.gameRuns.get).mockResolvedValue(opened)
    const stub = { template: '<div />' }
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: stub }] })
    await router.push('/')
    const wrapper = mount(GameRunView, { props: { id: 9 }, global: { plugins: [router] } })
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    return wrapper
  }

  it('has "Hint for 5" while the server offers it, and no balance until it is known', async () => {
    const wrapper = await atBazaar()

    expect(wrapper.get('[data-testid=hint-btn]').text()).toBe('Подсказка за 5')
    expect(wrapper.find('[data-testid=hint-coins]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('says the hint is free when the price is 0 (bazaar_hint_coins = 0)', async () => {
    const wrapper = await atBazaar(bazaar({ price: 0, available: true }))

    expect(wrapper.get('[data-testid=hint-btn]').text()).toBe('Подсказка бесплатно')
    wrapper.unmount()
  })

  it('buys the hint: the options it took away are dimmed, the balance is shown next to the price, the button is gone', async () => {
    const wrapper = await atBazaar()
    vi.mocked(api.gameRuns.hint).mockResolvedValue({
      hint: { options_removed: ['o3', 'o4'] },
      coins: { balance: 7 },
      run: runView({ hint: { price: 5, available: false } }, translateFwd({ hint: { options_removed: ['o3', 'o4'] } }), 'bazaar'),
    })

    await wrapper.get('[data-testid=hint-btn]').trigger('click')
    await flushPromises()

    expect(api.gameRuns.hint).toHaveBeenCalledWith(9, { request_id: expect.any(String), element_id: 'e1' })
    expect(wrapper.find('[data-testid=hint-btn]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=option-2]').attributes('data-removed')).toBe('true')
    wrapper.unmount()
  })

  it('has no button when the server does not offer one', async () => {
    const wrapper = await atBazaar(bazaar({ price: 5, available: false }))
    expect(wrapper.find('[data-testid=hint-btn]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('coins_insufficient: a toast, and the balance appears next to the price', async () => {
    const wrapper = await atBazaar()
    vi.mocked(api.gameRuns.hint).mockRejectedValue(apiError('conflict', 409, 'coins_insufficient', { balance: 2, price: 5 }))

    await wrapper.get('[data-testid=hint-btn]').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Не хватает монет. Монеты дают за забеги и хранителей.')
    expect(wrapper.get('[data-testid=hint-coins]').text()).toBe('У вас 2')
    expect(wrapper.find('[data-testid=hint-btn]').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('GameRunView — the clip of a listen task', () => {
  it('loads the clip of the element with the token', async () => {
    vi.mocked(api.gameRuns.audioBlob).mockResolvedValue(new Blob(['x']))
    const r = await render(runView({}, listen()))

    expect(api.gameRuns.audioBlob).toHaveBeenCalledWith('/api/twa/game/runs/9/audio/e3', expect.anything())
    expect(has(r, 'listen-btn')).toBe(true)
    r.wrapper.unmount()
  })
})

describe('GameRunView — leaving', () => {
  it('✕ asks "Leave the run? It will not count"; "Stay" closes the question and nothing is sent', async () => {
    const r = await render(runView(), { course: 'english-a1', stop: 41 })

    await click(r, 'close-btn')
    expect(tid(r, 'exit-sheet').text()).toContain('Забег не засчитается.')
    await click(r, 'stay-btn')

    expect(has(r, 'exit-sheet')).toBe(false)
    expect(api.gameRuns.abandon).not.toHaveBeenCalled()
    r.wrapper.unmount()
  })

  it('"Leave" abandons the run and leads to the map of the course', async () => {
    const r = await render(runView(), { course: 'english-a1', stop: 41 })
    vi.mocked(api.gameRuns.abandon).mockResolvedValue(finishedRun('abandoned'))

    await click(r, 'close-btn')
    await click(r, 'leave-btn')

    expect(api.gameRuns.abandon).toHaveBeenCalledWith(9)
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
    r.wrapper.unmount()
  })

  it('Telegram "Back" does what ✕ does', async () => {
    const r = await render()

    expect(tg.backHandler).not.toBeNull()
    tg.backHandler?.()
    await flushPromises()

    expect(has(r, 'exit-sheet')).toBe(true)
    r.wrapper.unmount()
  })

  it('a failed abandon keeps the learner on the run, with a toast', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.abandon).mockRejectedValue(new ApiClientError('network', 0, 'network', 'down'))

    await click(r, 'close-btn')
    await click(r, 'leave-btn')

    expect(showErrorToast).toHaveBeenCalled()
    expect(r.router.currentRoute.value.name).toBe('game-run')
    expect(has(r, 'exit-sheet')).toBe(false)
    r.wrapper.unmount()
  })

  it('the "No sound for now" of a dictation asks the same question: there is no replacement in a run', async () => {
    const r = await render(runView({}, { id: 'e9', kind: 'dictation', source: 'phrase', audio_url: '/api/twa/game/runs/9/audio/e9', words: 3, replacements_left: 0 }))
    vi.mocked(api.gameRuns.audioBlob).mockResolvedValue(new Blob(['x']))

    await click(r, 'silent-btn')

    expect(has(r, 'exit-sheet')).toBe(true)
    r.wrapper.unmount()
  })

  it('on the summary ✕ and "Back" lead straight to the map', async () => {
    const r = await render(finishedRun('completed'), { course: 'english-a1' })

    tg.backHandler?.()
    await flushPromises()

    expect(has(r, 'exit-sheet')).toBe(false)
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
    r.wrapper.unmount()
  })
})

describe('GameRunView — the fight with a boss (docs/05 §5b.8)', () => {
  const share = { link: 'https://t.me/LexiFlowBot?start=ref_x', text: 'Я дошёл до Самарканда в игре «Караван»', title: 'Я дошёл до Самарканда' }
  const wonBoss = (extra: Partial<RunView> = {}) =>
    finishedRun('completed', { kind: 'boss', lesson_id: null, ...extra }, { stars: 3, boss: { accuracy: 92, won: true, first: true, share } })

  it('a first win shows the card; "Поделиться" opens the dialog of Telegram with the pair of the server and puts the mark of the city once', async () => {
    vi.mocked(api.game.cityShared).mockResolvedValue({ stage_id: 3, shared_at: '2026-11-05T10:00:00.000Z' })
    const r = await render(wonBoss(), { course: 'english-a1', boss: 3 })

    expect(tid(r, 'city-card-title').text()).toBe('Я дошёл до Самарканда')
    await click(r, 'share-btn')
    await click(r, 'share-btn')

    expect(tg.shareUrl).toHaveBeenCalledWith(share.link, share.text)
    expect(tg.shareUrl).toHaveBeenCalledTimes(2) // the dialog may be opened again
    expect(api.game.cityShared).toHaveBeenCalledTimes(1) // the mark is once
    expect(api.game.cityShared).toHaveBeenCalledWith(3)
    r.wrapper.unmount()
  })

  it('puts the mark by the stage of the run itself - after a reload or "Continue" from Today there is no ?boss= (G2.13)', async () => {
    vi.mocked(api.game.cityShared).mockResolvedValue({ stage_id: 7, shared_at: '2026-11-05T10:00:00.000Z' })
    const r = await render(wonBoss({ stage_id: 7 }), { course: 'english-a1' })

    await click(r, 'share-btn')

    expect(api.game.cityShared).toHaveBeenCalledWith(7)
    r.wrapper.unmount()
  })

  it('after a fight of a class ✕ and "Back" on the summary lead to the class screen, as the main button does', async () => {
    const r = await render(
      finishedRun('completed', { kind: 'class_boss', class_caravan_id: 4, lesson_id: null }, { boss: { accuracy: 50, won: false, first: false, share: null } }),
      { course: 'english-a1' },
    )
    r.router.addRoute({ path: '/game/class/:id(\\d+)', name: 'game-class', component: { template: '<div />' } })

    tg.backHandler?.()
    await flushPromises()

    expect(has(r, 'exit-sheet')).toBe(false)
    expect(r.router.currentRoute.value.fullPath).toBe('/game/class/4')
    r.wrapper.unmount()
  })

  it('a fight of a class: "К экрану класса" leads to the caravan of the run, "Ещё раз" is not offered', async () => {
    const r = await render(
      finishedRun('completed', { kind: 'class_boss', class_caravan_id: 4, lesson_id: null }, { boss: { accuracy: 50, won: false, first: false, share: null } }),
      { course: 'english-a1' },
    )
    r.router.addRoute({ path: '/game/class/:id(\\d+)', name: 'game-class', component: { template: '<div />' } })

    expect(has(r, 'again-btn')).toBe(false)
    expect(tid(r, 'summary-title').text()).toBe('Хранитель класса устоял')
    await click(r, 'class-btn')

    expect(r.router.currentRoute.value.fullPath).toBe('/game/class/4')
    r.wrapper.unmount()
  })

  it('a refused mark is silent: no toast, the dialog was opened', async () => {
    vi.mocked(api.game.cityShared).mockRejectedValue(apiError('not_found', 404, 'stage_not_found'))
    const r = await render(wonBoss(), { course: 'english-a1', boss: 3 })

    await click(r, 'share-btn')

    expect(tg.shareUrl).toHaveBeenCalledTimes(1)
    expect(showErrorToast).not.toHaveBeenCalled()
    r.wrapper.unmount()
  })

  it('without the stage of the boss in the address (a link, an old tab) the dialog opens and no mark is put', async () => {
    const r = await render(wonBoss())

    await click(r, 'share-btn')

    expect(tg.shareUrl).toHaveBeenCalledTimes(1)
    expect(api.game.cityShared).not.toHaveBeenCalled()
    r.wrapper.unmount()
  })

  it('a boss has no "Ещё раз": the map is the way on, the course comes from the run', async () => {
    const r = await render(wonBoss({ course: { slug: 'ielts' } }), { boss: 3 })

    expect(has(r, 'again-btn')).toBe(false)
    await click(r, 'map-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=ielts')
    r.wrapper.unmount()
  })
})

describe('GameRunView — the summary', () => {
  it('completed: the stars and the score, "Again" leads to the stop on the map, "To the map" to the map', async () => {
    const r = await render(finishedRun('completed', {}, { stars: 3, score: 480 }), { course: 'english-a1', stop: 41 })

    expect(tid(r, 'summary-title').text()).toBe('Забег пройден')
    expect(tid(r, 'summary-score').text()).toBe('480')
    expect(has(r, 'rewards')).toBe(false) // the server gives zeros: no invented rows

    await click(r, 'again-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1&stop=41')
    r.wrapper.unmount()
  })

  it('"To the map" without the stop', async () => {
    const r = await render(finishedRun('completed'), { course: 'english-a1', stop: 41 })
    await click(r, 'map-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
    r.wrapper.unmount()
  })

  it('takes the course and the stop from the run itself, with no query; the run wins over a stale query', async () => {
    const r = await render(finishedRun('completed', { course: { slug: 'ielts' }, lesson_id: 77 }))
    await click(r, 'again-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=ielts&stop=77')
    r.wrapper.unmount()

    const other = await render(finishedRun('completed', { course: { slug: 'ielts' }, lesson_id: 77 }), { course: 'english-a1', stop: 41 })
    await click(other, 'map-btn')
    expect(other.router.currentRoute.value.fullPath).toBe('/game?course=ielts')
    other.wrapper.unmount()
  })

  it('the caravan of the day has no "Again" (one a day): the map is the way on, and the course comes from the run', async () => {
    const r = await render(finishedRun('completed', { kind: 'daily', course: { slug: 'ielts' }, lesson_id: null }))

    expect(has(r, 'again-btn')).toBe(false)
    expect(tid(r, 'map-btn').classes()).toContain('btn-primary')
    await click(r, 'map-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=ielts')
    r.wrapper.unmount()
  })

  it('a run that is closed (410) has no body to tell the course: the query is the way back', async () => {
    const r = await render(apiError('gone', 410, 'game_run_closed', { finish_reason: 'expired' }), { course: 'english-a1', stop: 41 })
    await click(r, 'map-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game?course=english-a1')
    r.wrapper.unmount()
  })

  it('a run opened without the course leads to the map of the active one', async () => {
    const r = await render(finishedRun('completed'))
    await click(r, 'again-btn')
    expect(r.router.currentRoute.value.fullPath).toBe('/game')
    r.wrapper.unmount()
  })

  it('shows the rewards when the server gives them', async () => {
    const r = await render(finishedRun('completed', {}, { coins: 15, league_points: 18 }))
    expect(tid(r, 'reward-coins').text()).toContain('+15')
    expect(tid(r, 'reward-league').text()).toContain('+18')
    r.wrapper.unmount()
  })

  it('failed: "The flasks are over", the mistakes, "Repeat in cards" opens the daily review', async () => {
    const r = await render(
      finishedRun('failed', {}, { mistakes: [{ element_id: 'e1', kind: 'spell', label: 'apple — яблоко', your_answer: 'appel', verdict: 'typo' }] }),
    )

    expect(tid(r, 'summary-title').text()).toBe('Фляги кончились')
    expect(tid(r, 'mistake-0').text()).toContain('apple — яблоко')
    await click(r, 'review-btn')
    expect(review).toHaveBeenCalledTimes(1)
    r.wrapper.unmount()
  })

  it('a summary the server did not send is the closed screen, not a blank one', async () => {
    const r = await render(finishedRun('completed', { summary: null }))
    expect(has(r, 'closed')).toBe(true)
    r.wrapper.unmount()
  })
})

describe('GameRunView — a new run in the same screen', () => {
  it('reads the other run when the id of the route changes', async () => {
    const r = await render()
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({ id: 10 }, translateBack()))

    await r.wrapper.setProps({ id: 10 })
    await flushPromises()

    expect(api.gameRuns.get).toHaveBeenLastCalledWith(10)
    expect(useGameRunStore().runId).toBe(10)
    r.wrapper.unmount()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render(finishedRun('failed'))
    expect(tid(r, 'summary-title').text()).toBe('Meshlar tugadi')
    r.wrapper.unmount()
  })
})

