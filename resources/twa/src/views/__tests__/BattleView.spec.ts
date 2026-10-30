import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, createWebHistory } from 'vue-router'
import BattleView from '../BattleView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import {
  SERVER_NOW,
  answeringRound,
  at,
  battle,
  closedBattle,
  countdownBattle,
  finishedBattle,
  invitedBattle,
  poll,
  pollOf,
  revealRound,
  roomBattle,
  roundState,
} from '@/api/__tests__/battleFixtures'
import { dictationElement, sentenceElement } from '@/api/__tests__/practiceFixtures'
import type { Battle, BattleEndReason, BattlePoll, BattleRound } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    battles: {
      list: vi.fn(),
      get: vi.fn(),
      poll: vi.fn(),
      round: vi.fn(),
      accept: vi.fn(),
      decline: vi.fn(),
      cancel: vi.fn(),
      ready: vi.fn(),
      surrender: vi.fn(),
      answer: vi.fn(),
      audioFailed: vi.fn(),
      invite: vi.fn(),
      audioBlob: vi.fn(),
    },
    practice: { audioBlob: vi.fn() },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({
  backHandler: null as (() => void) | null,
  haptic: vi.fn(),
  notification: vi.fn(),
}))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: (handler: () => void) => {
      tg.backHandler = handler
      return () => {
        tg.backHandler = null
      }
    },
    haptic: tg.haptic,
    notificationHaptic: tg.notification,
    selectionHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

// The component of a mode is the business of its own task; here a probe that answers when it is told to.
vi.mock('@/components/practice/modeComponents', async () => {
  const { defineComponent, h } = await import('vue')
  const Probe = defineComponent({
    props: { element: { type: Object, required: true }, mode: { type: String, default: 'solo' }, disabled: { type: Boolean, default: false } },
    emits: ['answer', 'skip'],
    setup: (props, { emit }) => () =>
      h('div', { 'data-testid': 'probe', 'data-element': (props.element as { id: string }).id, 'data-mode': props.mode, 'data-disabled': String(props.disabled) }, [
        h('button', { 'data-testid': 'probe-answer', onClick: () => emit('answer', { order: ['fW2', 'fT5'] }) }),
        h('button', { 'data-testid': 'probe-skip', onClick: () => emit('skip') }),
      ]),
  })
  return { MODE_COMPONENTS: {}, modeComponentFor: () => Probe }
})

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')
const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

const CLIPS = [1, 2, 3, 4, 5].map((n) => `/api/twa/practice/battles/15/audio/eD${n}`)
const clip = (): Blob => new Blob(['mp3'], { type: 'audio/mpeg' })

const answerReply = (overrides: Partial<{ round_resolved: boolean; poll: BattlePoll }> = {}) => ({
  verdict: 'correct' as const,
  points: 100,
  accepted_at: SERVER_NOW,
  round_resolved: false,
  poll: poll({ version: 24, round: roundState({ my_answered: true }) }),
  ...overrides,
})

const mounted: VueWrapper[] = []

/**
 * The server answers with its clock, which runs like the device's: every reading carries the time of the moment it
 * is read, not the time the fixture was made (a clock that stands still while the device’s runs would read as a
 * server that is falling behind, and the timers would go wrong).
 */
const stamp = <T extends { server_now: string }>(value: T): T => ({ ...value, server_now: new Date(Date.now() + serverSkewMs).toISOString() })

/** How far the clock of the server is ahead of the device's (a spec sets it before it renders). */
let serverSkewMs = 0

/** The poll that the server gives from now on. */
function servePoll(make: () => BattlePoll): void {
  vi.mocked(api.battles.poll).mockImplementation(() => Promise.resolve(stamp(make())))
}

/** The battle that `GET /battles/{id}` gives from now on (by id, when there are several). */
function serveBattle(make: (id: number) => Battle): void {
  vi.mocked(api.battles.get).mockImplementation((id) => Promise.resolve(stamp(make(id))))
}

interface Options {
  id?: number
  round?: BattleRound | null
  history?: 'memory' | 'web'
  from?: string
  /** Other battles that `GET /battles/{id}` knows (the rematch). */
  others?: Record<number, Battle>
}

async function render(initial: Battle | Error | Promise<Battle>, options: Options = {}) {
  const id = options.id ?? 15
  if (initial instanceof Error) vi.mocked(api.battles.get).mockRejectedValue(initial)
  else if (initial instanceof Promise) vi.mocked(api.battles.get).mockReturnValue(initial)
  else {
    serveBattle((requested) => options.others?.[requested] ?? initial)
    // the first reading is the fixture as it stands; the later ones carry the time of the moment they are read
    vi.mocked(api.battles.get).mockResolvedValueOnce(initial)
    servePoll(() => pollOf(initial))
  }
  vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(options.round ?? answeringRound())))

  const stub = { template: '<div />' }
  const router = createRouter({
    history: options.history === 'web' ? createWebHistory('/battle-view-test/') : createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/friends', name: 'friends', component: stub },
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
    ],
  })
  if (options.from) await router.push(options.from)
  await router.push({ name: 'battle', params: { id } })
  const wrapper = mount(BattleView, { props: { id }, global: { plugins: [router] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>

const has = (r: Rendered, testid: string) => r.wrapper.find(`[data-testid=${testid}]`).exists()
const get = (r: Rendered, testid: string) => r.wrapper.get(`[data-testid=${testid}]`)
const text = (r: Rendered, testid: string) => get(r, testid).text()
const click = async (r: Rendered, testid: string) => {
  await get(r, testid).trigger('click')
  await flushPromises()
}
/** Lets the clock of the screen run: the polls, the countdowns, the wake-ups. */
async function pass(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}
const primaries = (r: Rendered) => r.wrapper.findAll('button.btn-primary')
const route = (r: Rendered) => r.router.currentRoute.value

function setVisibility(state: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })
  document.dispatchEvent(new Event('visibilitychange'))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
  vi.setSystemTime(new Date(SERVER_NOW))
  tg.backHandler = null
  serverSkewMs = 0
  URL.createObjectURL = vi.fn(() => 'blob:clip')
  URL.revokeObjectURL = vi.fn()
  setVisibility('visible')
})
afterEach(() => {
  while (mounted.length > 0) mounted.pop()?.unmount()
  vi.useRealTimers()
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('BattleView — entering', () => {
  it('is a spinner while the battle is read, then the screen of its status; the learner is signed in first', async () => {
    const reply = pending<Battle>()
    const r = await render(reply.promise)

    expect(has(r, 'spinner')).toBe(true)
    expect(has(r, 'battle-room')).toBe(false)

    reply.resolve(roomBattle())
    await flushPromises()
    expect(has(r, 'spinner')).toBe(false)
    expect(has(r, 'battle-room')).toBe(true)
    expect(authEnsure).toHaveBeenCalled()
    expect(api.battles.get).toHaveBeenCalledWith(15)
    expect(get(r, 'battle-view').attributes('data-status')).toBe('room')
  })

  it('a battle that is not there, or is not the learner’s, is told in words and leads to the friends', async () => {
    const r = await render(apiError('not_found', 404, 'battle_not_found'))

    expect(has(r, 'closed')).toBe(true)
    expect(r.wrapper.text()).toContain('Баттл недоступен')
    expect(r.wrapper.text()).toContain('Баттл не найден.')
    expect(has(r, 'retry-btn')).toBe(false)
    expect(has(r, 'headline')).toBe(false)

    await click(r, 'friends-btn')
    expect(route(r).name).toBe('friends')
  })

  it('a lost connection offers “Retry”, which reads the battle again', async () => {
    const r = await render(offline())

    expect(has(r, 'error')).toBe(true)
    expect(r.wrapper.text()).toContain('Нет связи с сервером. Попробуйте ещё раз.')

    serveBattle(() => roomBattle())
    await click(r, 'retry-btn')
    expect(has(r, 'error')).toBe(false)
    expect(has(r, 'battle-room')).toBe(true)
  })

  it('a failed sign-in is told, and nothing is read', async () => {
    authEnsure.mockRejectedValue(new Error('opened_outside_telegram'))
    const r = await render(roomBattle())

    expect(r.wrapper.text()).toContain('Откройте приложение через Telegram.')
    expect(api.battles.get).not.toHaveBeenCalled()
  })

  it('goes to the other battle when its id changes (a rematch), without a stale screen', async () => {
    const r = await render(finishedBattle())
    serveBattle(() => invitedBattle({ id: 16, role: 'challenger' }))

    await r.wrapper.setProps({ id: 16 })
    await flushPromises()

    expect(api.battles.get).toHaveBeenLastCalledWith(16)
    expect(get(r, 'battle-view').attributes('data-status')).toBe('invited')
    expect(has(r, 'battle-result')).toBe(false)
  })

  it('has the header of an immersive screen: ✕, the name of the screen, the module and the lesson; no tab bar', async () => {
    const r = await render(roomBattle())

    expect(get(r, 'close-btn').attributes('aria-label')).toBe('Закрыть')
    expect(text(r, 'headline')).toBe('Баттл')
    expect(text(r, 'caption')).toBe('Модуль 2 · Урок 4 · Еда')
    expect(get(r, 'battle-view').attributes('style')).toContain('min-height')
    expect(r.wrapper.find('nav').exists()).toBe(false)
  })

  it('a course without a lesson shows the course', async () => {
    const r = await render(roomBattle({ lesson: null }))

    expect(text(r, 'caption')).toBe('Английский A1')
  })
})

describe('BattleView — the invitation', () => {
  it('sent by the learner: waits, shows how long it lasts, and the poll runs', async () => {
    const r = await render(invitedBattle({ role: 'challenger' }))

    expect(text(r, 'room-title')).toBe('Ждём Dilnoza…')
    expect(text(r, 'room-expires')).toBe('Приглашение действует ещё 4:00')
    expect(api.battles.poll).not.toHaveBeenCalled()

    await pass(2000)
    expect(api.battles.poll).toHaveBeenCalledWith(15)
  })

  it('“Cancel” withdraws it and leads to the friends', async () => {
    const r = await render(invitedBattle({ role: 'challenger' }))
    vi.mocked(api.battles.cancel).mockResolvedValue(closedBattle('canceled'))

    await click(r, 'cancel-btn')

    expect(api.battles.cancel).toHaveBeenCalledWith(15)
    expect(route(r).name).toBe('friends')
  })

  it('the other side accepted: the room opens by itself (the poll sees a new status)', async () => {
    const r = await render(invitedBattle({ role: 'challenger' }))
    servePoll(() => pollOf(roomBattle()))
    serveBattle(() => roomBattle())

    await pass(2000)

    expect(get(r, 'battle-view').attributes('data-status')).toBe('room')
    expect(text(r, 'room-title')).toBe('Комната готова')
  })

  it('the other side declined: the result says so, and the poll stops', async () => {
    const r = await render(invitedBattle({ role: 'challenger' }))
    servePoll(() => pollOf(closedBattle('declined')))
    serveBattle(() => closedBattle('declined'))

    await pass(2000)

    expect(text(r, 'result-title')).toBe('Матч не состоялся')
    expect(text(r, 'result-reason')).toBe('Приглашение отклонено.')
    vi.mocked(api.battles.poll).mockClear()
    await pass(10_000)
    expect(api.battles.poll).not.toHaveBeenCalled()
  })

  it('the invitation runs out: the screen asks at the very moment, and shows it', async () => {
    const r = await render(invitedBattle({ role: 'challenger', invite_expires_at: at(3) }))
    servePoll(() => pollOf(closedBattle('invite_expired')))
    serveBattle(() => closedBattle('invite_expired'))

    await pass(3200) // the regular beat is at 2 s, the wake-up at the end of the invitation

    expect(text(r, 'result-reason')).toBe('Приглашение не приняли вовремя.')
  })

  it('received: shows who calls, the rules, and “Accept” as the one primary button', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))

    expect(text(r, 'room-title')).toBe('Dilnoza зовёт вас на баттл')
    expect(primaries(r)).toHaveLength(1)
    expect(text(r, 'accept-btn')).toBe('Принять')
  })

  it('“Accept” opens the room, and the poll goes on', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ role: 'opponent' }))

    await click(r, 'accept-btn')

    expect(api.battles.accept).toHaveBeenCalledWith(15)
    expect(text(r, 'room-title')).toBe('Комната готова')
    vi.mocked(api.battles.poll).mockClear()
    await pass(2000)
    expect(api.battles.poll).toHaveBeenCalled()
  })

  it('a battle of the learner’s own is in the way: told in a toast, with a way to it', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 9 }))

    await click(r, 'accept-btn')

    expect(showErrorToast).toHaveBeenCalledWith('У вас уже есть активный баттл. Завершите его, чтобы начать новый.')
    expect(has(r, 'room-busy')).toBe(true)
    await click(r, 'open-busy')
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('9')
  })

  it('“Decline” closes it and leads to the friends', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { role: 'opponent' }))

    await click(r, 'decline-btn')

    expect(api.battles.decline).toHaveBeenCalledWith(15)
    expect(route(r).name).toBe('friends')
  })

  it('an invitation that has run out cannot be accepted: told, and the result shows why', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'invite_expired' }))
    serveBattle(() => closedBattle('invite_expired', { role: 'opponent' }))

    await click(r, 'accept-btn')

    expect(showErrorToast).toHaveBeenCalledWith('Этот баттл уже закрыт.')
    expect(text(r, 'result-reason')).toBe('Приглашение не приняли вовремя.')
  })
})

describe('BattleView — leaving before the match', () => {
  it('the invitation that came is not the learner’s to withdraw: Back just leaves it open and goes to the friends', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))

    expect(tg.backHandler).not.toBeNull()
    tg.backHandler?.()
    await flushPromises()

    expect(has(r, 'leave-confirm')).toBe(false)
    expect(api.battles.cancel).not.toHaveBeenCalled()
    expect(api.battles.decline).not.toHaveBeenCalled()
    expect(route(r).name).toBe('friends')
  })

  it('the invitation that was sent: ✕ asks “Leave the room?” (the invitation is withdrawn) and then cancels', async () => {
    const r = await render(invitedBattle({ role: 'challenger' }))
    vi.mocked(api.battles.cancel).mockResolvedValue(closedBattle('canceled'))

    await click(r, 'close-btn')
    expect(get(r, 'leave-confirm').attributes('data-variant')).toBe('room')
    expect(text(r, 'leave-title')).toBe('Выйти из комнаты?')
    expect(text(r, 'leave-text')).toBe('Приглашение будет отозвано.')
    expect(api.battles.cancel).not.toHaveBeenCalled()

    await click(r, 'leave-yes')
    expect(api.battles.cancel).toHaveBeenCalledWith(15)
    expect(route(r).name).toBe('friends')
  })

  it('the room: the system “Back” asks, “Stay” changes nothing', async () => {
    const r = await render(roomBattle())

    tg.backHandler?.()
    await flushPromises()
    expect(text(r, 'leave-title')).toBe('Выйти из комнаты?')
    expect(text(r, 'leave-text')).toBe('Матч отменится, соперник узнает об этом.')

    await click(r, 'leave-stay')
    expect(has(r, 'leave-confirm')).toBe(false)
    expect(api.battles.cancel).not.toHaveBeenCalled()
    expect(route(r).name).toBe('battle')
  })

  it('the second press of “Back” closes the question', async () => {
    const r = await render(roomBattle())

    tg.backHandler?.()
    await flushPromises()
    tg.backHandler?.()
    await flushPromises()

    expect(has(r, 'leave-confirm')).toBe(false)
  })

  it('the room: “Leave” asks the same question and then cancels', async () => {
    const r = await render(roomBattle())
    vi.mocked(api.battles.cancel).mockResolvedValue(closedBattle('canceled'))

    await click(r, 'leave-btn')
    await click(r, 'leave-yes')

    expect(api.battles.cancel).toHaveBeenCalledWith(15)
    expect(route(r).name).toBe('friends')
  })

  it('a refused cancel keeps the learner where they are, with the reason in a toast', async () => {
    const r = await render(roomBattle())
    vi.mocked(api.battles.cancel).mockRejectedValue(offline())

    await click(r, 'leave-btn')
    await click(r, 'leave-yes')

    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(has(r, 'leave-confirm')).toBe(false)
    expect(route(r).name).toBe('battle')
    expect(has(r, 'battle-room')).toBe(true)
  })

  it('the countdown asks too', async () => {
    const r = await render(countdownBattle())

    await click(r, 'close-btn')

    expect(get(r, 'leave-confirm').attributes('data-variant')).toBe('room')
  })
})

describe('BattleView — the room', () => {
  const room = (overrides: Partial<Battle> = {}) => roomBattle({ ready: { me: false, opponent: false }, ...overrides })

  it('“Ready” is sent, and then the button says that the opponent is awaited', async () => {
    const r = await render(room())
    vi.mocked(api.battles.ready).mockResolvedValue(room({ version: 3, ready: { me: true, opponent: false } }))

    await click(r, 'ready-btn')

    expect(api.battles.ready).toHaveBeenCalledWith(15)
    expect(text(r, 'ready-btn')).toBe('Готов — ждём соперника')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeDefined()
    expect(tg.haptic).toHaveBeenCalledWith('medium')
  })

  it('the poll brings the readiness of the opponent without reading the battle again', async () => {
    const r = await render(room())
    servePoll(() => poll({ status: 'room', version: 3, ready: { me: false, opponent: true }, round: null, scores: { me: 0, opponent: 0 } }))

    await pass(2000)

    expect(r.wrapper.findAll('[data-testid=player-chip]').map((chip) => chip.text())).toEqual(['не готов', 'готов'])
    expect(api.battles.get).toHaveBeenCalledTimes(1)
  })

  it('both are ready: the countdown begins (the poll sees a new status)', async () => {
    const r = await render(room({ ready: { me: true, opponent: false } }))
    servePoll(() => pollOf(countdownBattle()))
    serveBattle(() => countdownBattle())

    await pass(2000)

    expect(has(r, 'battle-countdown')).toBe(true)
    expect(has(r, 'battle-room')).toBe(false)
  })

  it('the room runs out: the result says so', async () => {
    const r = await render(room())
    servePoll(() => pollOf(closedBattle('room_expired')))
    serveBattle(() => closedBattle('room_expired'))

    await pass(2000)

    expect(text(r, 'result-reason')).toBe('Комната закрыта: матч не начался вовремя.')
  })
})

describe('BattleView — a dictation loads its recordings in the room', () => {
  const dictationRoom = () => roomBattle({ mode: 'dictation', round_seconds: 60, ready: { me: false, opponent: false }, audio_preload: CLIPS })

  it('loads the five recordings with the token; “Ready” opens when they are all in', async () => {
    const replies = CLIPS.map(() => pending<Blob>())
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => replies[CLIPS.indexOf(url)]!.promise)
    const r = await render(dictationRoom())

    expect(api.battles.audioBlob).toHaveBeenCalledTimes(5)
    expect(text(r, 'audio-progress')).toBe('Загружаем звук: 0 из 5')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeDefined()

    for (const reply of replies.slice(0, 3)) reply.resolve(clip())
    await flushPromises()
    expect(text(r, 'audio-progress')).toBe('Загружаем звук: 3 из 5')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeDefined()

    for (const reply of replies.slice(3)) reply.resolve(clip())
    await flushPromises()
    expect(text(r, 'audio-progress')).toBe('Звук загружен')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeUndefined()
  })

  it('a recording that did not come: a plain sentence and “Retry”; “Ready” waits for it', async () => {
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url === CLIPS[3] ? Promise.reject(apiError('server', 503, 'practice_audio_unavailable')) : Promise.resolve(clip())))
    const r = await render(dictationRoom())

    expect(text(r, 'audio-failed')).toContain('Не удалось загрузить звук')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeDefined()

    vi.mocked(api.battles.audioBlob).mockClear()
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    await click(r, 'audio-retry')

    expect(api.battles.audioBlob).toHaveBeenCalledTimes(1)
    expect(api.battles.audioBlob).toHaveBeenCalledWith(CLIPS[3])
    expect(get(r, 'ready-btn').attributes('disabled')).toBeUndefined()
  })

  it('the rule of the sound is shown in the room, before “Ready”', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    const r = await render(dictationRoom())

    expect(r.wrapper.findAll('[data-testid=rule-row]').at(-1)?.text()).toBe('Если звук не загрузится во время матча, раунд даст 0 очков. Если записи нет совсем, матч аннулируется.')
  })

  it('the other formats load nothing', async () => {
    const r = await render(roomBattle({ mode: 'pairs', ready: { me: false, opponent: false } }))

    expect(api.battles.audioBlob).not.toHaveBeenCalled()
    expect(has(r, 'room-audio')).toBe(false)
  })
})

describe('BattleView — the countdown', () => {
  it('counts 3 · 2 · 1 to the start by the clock of the server, then says “Go!”', async () => {
    const r = await render(countdownBattle())

    expect(text(r, 'countdown-number')).toBe('3')
    expect(text(r, 'countdown-steps')).toBe('3·2·1')
    expect(text(r, 'battle-countdown')).toContain('Раунд 1 из 5')

    await pass(1000)
    expect(text(r, 'countdown-number')).toBe('2')
    await pass(1000)
    expect(text(r, 'countdown-number')).toBe('1')
    await pass(1000)
    expect(text(r, 'countdown-number')).toBe('Старт!')
  })

  it('counts to the start of the SERVER: a device clock that is behind does not start the match late', async () => {
    serverSkewMs = 2000 // the server is 2 s ahead of the device
    const r = await render(countdownBattle({ server_now: at(2), starts_at: at(5) }))

    expect(text(r, 'countdown-number')).toBe('3')
    await pass(1000)
    expect(text(r, 'countdown-number')).toBe('2')
  })

  it('does not wait for the next beat of the poll when the start comes: it asks at the very moment, and the match begins', async () => {
    const r = await render(countdownBattle())
    // the server moves to `playing` on the first read after `starts_at`; before that it answers “countdown”
    const started = () => Date.now() >= Date.parse(at(3))
    servePoll(() => (started() ? pollOf(battle({ version: 5 })) : pollOf(countdownBattle())))
    serveBattle(() => (started() ? battle({ version: 5 }) : countdownBattle()))

    await pass(2000) // the regular beat: still the countdown
    expect(get(r, 'battle-view').attributes('data-status')).toBe('countdown')
    await pass(1200) // 3.2 s: the wake-up has been, the next regular beat (4 s) has not
    expect(get(r, 'battle-view').attributes('data-status')).toBe('playing')
    expect(text(r, 'headline')).toBe('Раунд 2 из 5')
  })

  it('has no primary button; “Back” asks', async () => {
    const r = await render(countdownBattle())

    expect(primaries(r)).toHaveLength(0)
    tg.backHandler?.()
    await flushPromises()
    expect(has(r, 'leave-confirm')).toBe(true)
  })

  it('says the number to a screen reader', async () => {
    const r = await render(countdownBattle())

    expect(text(r, 'battle-live')).toBe('Старт через 3')
    await pass(1000)
    expect(text(r, 'battle-live')).toBe('Старт через 2')
  })
})

describe('BattleView — a round', () => {
  it('shows the round, the score and the timer, and the component of the mode with `mode="battle"`', async () => {
    const r = await render(battle())

    expect(text(r, 'headline')).toBe('Раунд 2 из 5')
    expect(text(r, 'score-me')).toBe('100')
    expect(text(r, 'score-opponent')).toBe('125')
    expect(text(r, 'score-opponent-name')).toBe('Dilnoza')
    expect(text(r, 'timer-text')).toBe('0:40')
    expect(get(r, 'probe').attributes('data-element')).toBe('eSent00002')
    expect(get(r, 'probe').attributes('data-mode')).toBe('battle')
    expect(get(r, 'probe').attributes('data-disabled')).toBe('false')
    expect(api.battles.round).toHaveBeenCalledWith(15, 2)
  })

  it('has only the component to press: no primary button of its own, no tab bar', async () => {
    const r = await render(battle())

    expect(primaries(r)).toHaveLength(0)
    expect(r.wrapper.find('nav').exists()).toBe(false)
  })

  it('the timer counts down, and the score follows the poll', async () => {
    const r = await render(battle())
    servePoll(() => poll({ version: 24, scores: { me: 100, opponent: 225 } }))

    await pass(2000)

    expect(text(r, 'timer-text')).toBe('0:38')
    expect(text(r, 'score-opponent')).toBe('225')
  })

  it('sends the answer of the component and then waits for the opponent', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())

    await click(r, 'probe-answer')

    expect(api.battles.answer).toHaveBeenCalledWith(15, { request_id: expect.stringMatching(/^[0-9a-f-]{36}$/), round: 2, answer: { order: ['fW2', 'fT5'] } })
    expect(has(r, 'probe')).toBe(false)
    expect(text(r, 'battle-waiting')).toContain('Ответ принят')
    expect(text(r, 'battle-waiting')).toContain('Ждём соперника…')
    expect(text(r, 'player-chip')).toBe('отвечает…')
    expect(text(r, 'battle-live')).toBe('Ответ принят. Ждём соперника.')
    expect(tg.haptic).toHaveBeenCalledWith('medium')
  })

  it('says nothing of the verdict of the learner’s own answer until the round is over', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())

    await click(r, 'probe-answer')

    expect(has(r, 'battle-reveal')).toBe(false)
    expect(r.wrapper.text()).not.toContain('Верно')
    expect(r.wrapper.text()).not.toContain('Ошибка')
  })

  it('“I don’t know” is a final answer too: {skip: true}', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())

    await click(r, 'probe-skip')

    expect(vi.mocked(api.battles.answer).mock.calls[0]![1].answer).toEqual({ skip: true })
  })

  it('keeps the component off while the answer is on its way, and says so', async () => {
    const reply = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValue(reply.promise)
    const r = await render(battle())

    await click(r, 'probe-answer')
    expect(get(r, 'probe').attributes('data-disabled')).toBe('true')
    expect(text(r, 'sending')).toBe('Отправляем ответ…')

    reply.resolve(answerReply())
    await flushPromises()
    expect(has(r, 'sending')).toBe(false)
  })

  it('the opponent answered: the poll turns “answering…” into “answered”', async () => {
    const r = await render(battle({ round: roundState({ my_answered: true }) }), { round: null })
    expect(text(r, 'player-chip')).toBe('отвечает…')
    servePoll(() => poll({ version: 24, round: roundState({ my_answered: true, opponent_answered: true }) }))

    await pass(2000)

    expect(text(r, 'player-chip')).toBe('ответил')
  })

  it('after a reload with the answer already given there is no task to show: straight to waiting, nothing read', async () => {
    const r = await render(battle({ round: roundState({ my_answered: true }) }), { round: null })

    expect(has(r, 'battle-waiting')).toBe(true)
    expect(has(r, 'probe')).toBe(false)
    expect(api.battles.round).not.toHaveBeenCalled()
  })

  it('a lost connection: the answer is sent again by itself, with the same request_id, and goes through', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerReply())

    await get(r, 'probe-answer').trigger('click')
    await pass(500)
    await pass(1000)

    const ids = vi.mocked(api.battles.answer).mock.calls.map((call) => call[1].request_id)
    expect(ids).toHaveLength(3)
    expect(new Set(ids).size).toBe(1)
    expect(has(r, 'battle-waiting')).toBe(true)
  })

  it('after three repeats it asks to retry, and “Retry” sends the same answer', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValue(offline())

    await get(r, 'probe-answer').trigger('click')
    await pass(500)
    await pass(1000)
    await pass(2000)

    expect(has(r, 'battle-send-failed')).toBe(true)
    expect(r.wrapper.text()).toContain('Ответ не отправился: нет связи.')
    expect(has(r, 'probe')).toBe(false)
    expect(primaries(r)).toHaveLength(1)
    const first = vi.mocked(api.battles.answer).mock.calls[0]![1].request_id

    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())
    await click(r, 'retry-answer-btn')

    expect(api.battles.answer).toHaveBeenCalledWith(15, { request_id: first, round: 2, answer: { order: ['fW2', 'fT5'] } })
    expect(has(r, 'battle-send-failed')).toBe(false)
    expect(has(r, 'battle-waiting')).toBe(true)
  })

  it('an answer that came too late (round_closed) shows no verdict: a toast, and the reveal of the server', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'reveal' }))
    servePoll(() => poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3), opponent_answered: true }) }))
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(revealRound({ reveal: { ...revealRound().reveal!, me: { answer: null, verdict: 'timeout', points: 0 } } }))))

    await click(r, 'probe-answer')

    expect(showErrorToast).toHaveBeenCalledWith('Время раунда вышло: ответ не принят.')
    expect(has(r, 'battle-waiting')).toBe(false)
    expect(has(r, 'battle-reveal')).toBe(true)
    expect(get(r, 'side-me').attributes('data-verdict')).toBe('timeout')
  })

  it('at 0 the timer says “Time is up” and the screen waits for the server; the component is not taken away', async () => {
    const r = await render(battle({ round: roundState({ deadline_at: at(5) }) }), { round: answeringRound({ deadline_at: at(5) }) })

    await pass(5200)

    expect(text(r, 'timer-text')).toBe('Время вышло')
    expect(get(r, 'round-timer').attributes('data-state')).toBe('over')
    expect(get(r, 'probe').attributes('data-disabled')).toBe('false')
    // an answer that is a moment late still goes: the server decides by its grace
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())
    await click(r, 'probe-answer')
    expect(api.battles.answer).toHaveBeenCalledTimes(1)
  })

  it('asks the server just after the deadline and its grace, without waiting for the next beat', async () => {
    const r = await render(battle({ round: roundState({ deadline_at: at(3) }) }), { round: answeringRound({ deadline_at: at(3) }) })
    vi.mocked(api.battles.poll).mockClear()

    await pass(2000)
    expect(api.battles.poll).toHaveBeenCalledTimes(1) // the regular beat
    await pass(2600) // 4.6 s: the beat at 4 s has been, the wake-up (3 s + the grace of about 1.6 s) not yet
    expect(api.battles.poll).toHaveBeenCalledTimes(2)
    await pass(200) // 4.8 s
    expect(api.battles.poll).toHaveBeenCalledTimes(3)
    expect(has(r, 'probe')).toBe(true)
  })

  it('a dictation round loads its recording with the token, and the component plays it', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    const r = await render(battle({ mode: 'dictation' }), { round: answeringRound({ element: dictationElement({ audio_url: CLIPS[1] }) }) })

    expect(api.battles.audioBlob).toHaveBeenCalledWith(CLIPS[1])
    expect(has(r, 'clip-failed')).toBe(false)
  })

  it('a recording that does not come: told in words, “Retry”, and the server is told once (it checks the file)', async () => {
    vi.mocked(api.battles.audioBlob).mockRejectedValue(apiError('server', 503, 'practice_audio_unavailable'))
    vi.mocked(api.battles.audioFailed).mockResolvedValue({ verified: false, battle: battle({ mode: 'dictation', version: 24 }) })
    const r = await render(battle({ mode: 'dictation' }), { round: answeringRound({ element: dictationElement({ id: 'eDict00002', audio_url: CLIPS[1] }) }) })

    expect(text(r, 'clip-failed')).toContain('Звук не загрузился')
    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
    expect(api.battles.audioFailed).toHaveBeenCalledWith(15, { request_id: expect.any(String), element_id: 'eDict00002' })

    vi.mocked(api.battles.audioBlob).mockReset()
    vi.mocked(api.battles.audioBlob).mockRejectedValue(apiError('server', 503, 'practice_audio_unavailable'))
    await click(r, 'clip-retry')
    expect(api.battles.audioBlob).toHaveBeenCalledWith(CLIPS[1])
    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
    expect(has(r, 'probe')).toBe(true) // the learner may still answer: the round gives 0 if nothing is heard
  })

  it('the server found the recording missing: the match is voided, and the result says so', async () => {
    vi.mocked(api.battles.audioBlob).mockRejectedValue(apiError('server', 503, 'practice_audio_unavailable'))
    vi.mocked(api.battles.audioFailed).mockResolvedValue({ verified: true, battle: closedBattle('voided', { mode: 'dictation', version: 30 }) })
    const r = await render(battle({ mode: 'dictation' }), { round: answeringRound({ element: dictationElement({ audio_url: CLIPS[1] }) }) })

    expect(text(r, 'result-reason')).toBe('Матч аннулирован из-за технической проблемы. Очки не засчитаны.')
    expect(text(r, 'result-title')).toBe('Матч не состоялся')
  })

  it('reads the new task at the next round, with a component of its own', async () => {
    const r = await render(battle())
    servePoll(() => poll({ version: 30, round: roundState({ no: 3, deadline_at: at(48) }) }))
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(answeringRound({ no: 3, deadline_at: at(48), element: sentenceElement({ id: 'eSent00003' }) }))))

    await pass(2000)

    expect(text(r, 'headline')).toBe('Раунд 3 из 5')
    expect(get(r, 'probe').attributes('data-element')).toBe('eSent00003')
  })
})

describe('BattleView — the end of a round', () => {
  const reveal = (overrides: Partial<Battle> = {}) =>
    battle({ round: roundState({ phase: 'reveal', phase_ends_at: at(3), my_answered: true, opponent_answered: true }), ...overrides })

  it('shows the right answer, both answers with their verdicts, the points of the round, the explanation, and the pause', async () => {
    const r = await render(reveal(), { round: revealRound() })

    expect(has(r, 'battle-reveal')).toBe(true)
    expect(has(r, 'probe')).toBe(false)
    expect(has(r, 'round-timer')).toBe(false)
    expect(text(r, 'reveal-correct')).toBe('My mother is a teacher.')
    expect(text(r, 'side-me')).toContain('Верно')
    expect(text(r, 'side-me')).toContain('+100')
    expect(text(r, 'side-opponent')).toContain('Ошибка')
    expect(text(r, 'reveal-explanation')).toContain('Порядок слов')
    expect(text(r, 'reveal-next-text')).toBe('Следующий раунд через 3…')
    expect(text(r, 'score-me')).toBe('100')
    expect(primaries(r)).toHaveLength(0)
  })

  it('tells the verdict of the round to a screen reader and to the hand', async () => {
    const r = await render(reveal(), { round: revealRound() })

    expect(text(r, 'battle-live')).toBe('Раунд завершён. Верно.')
    expect(tg.notification).toHaveBeenCalledWith('success')
  })

  it('a mistake is a warning tap and a different tap, not a silence', async () => {
    const wrong = revealRound({ reveal: { ...revealRound().reveal!, me: { answer: null, verdict: 'wrong', points: 0 } } })
    await render(reveal(), { round: wrong })

    expect(tg.notification).toHaveBeenCalledWith('error')
  })

  it('goes on by itself: the pause counts down, then the next round comes with the poll', async () => {
    const r = await render(reveal(), { round: revealRound() })
    await pass(1000)
    expect(text(r, 'reveal-next-text')).toBe('Следующий раунд через 2…')

    servePoll(() => poll({ version: 31, round: roundState({ no: 3, deadline_at: at(50) }) }))
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(answeringRound({ no: 3, deadline_at: at(50), element: sentenceElement({ id: 'eSent00003' }) }))))
    await pass(1500)

    expect(has(r, 'battle-reveal')).toBe(false)
    expect(get(r, 'probe').attributes('data-element')).toBe('eSent00003')
  })

  it('asks the server at the very end of the pause, so that the next round does not start late', async () => {
    await render(reveal({ round: roundState({ phase: 'reveal', phase_ends_at: at(3), my_answered: true, opponent_answered: true }) }), { round: revealRound() })
    vi.mocked(api.battles.poll).mockClear()

    await pass(1900) // before the regular beat
    expect(api.battles.poll).not.toHaveBeenCalled()
    await pass(1000) // 2.9 s: the beat at 2 s has been
    expect(api.battles.poll).toHaveBeenCalledTimes(1)
    await pass(300) // 3.2 s: the wake-up at the end of the pause (the regular beats are at 2 s and 4 s)
    expect(api.battles.poll).toHaveBeenCalledTimes(2)
  })

  it('the last round speaks of the result, and the result follows', async () => {
    const last = battle({ round: roundState({ no: 5, phase: 'reveal', phase_ends_at: at(3), my_answered: true, opponent_answered: true }) })
    const r = await render(last, { round: revealRound({ no: 5 }) })
    expect(text(r, 'reveal-next-text')).toBe('Итоги через 3…')

    servePoll(() => pollOf(finishedBattle()))
    serveBattle(() => finishedBattle())
    await pass(2000)

    expect(has(r, 'battle-reveal')).toBe(false)
    expect(text(r, 'result-title')).toBe('Победа')
  })

  it('if the round is over but its reveal has not been read yet, the screen waits instead of showing nothing', async () => {
    const r = await render(reveal(), { round: revealRound({ reveal: null }) })

    expect(has(r, 'battle-reveal')).toBe(false)
    expect(has(r, 'spinner')).toBe(true)
    // the next beat reads it again
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(revealRound())))
    await pass(2000)
    expect(has(r, 'battle-reveal')).toBe(true)
  })
})

describe('BattleView — leaving in the middle of the match', () => {
  it('✕ asks “Surrender and leave?”, “Stay” changes nothing', async () => {
    const r = await render(battle())

    await click(r, 'close-btn')
    expect(get(r, 'leave-confirm').attributes('data-variant')).toBe('match')
    expect(text(r, 'leave-title')).toBe('Сдаться и выйти?')
    expect(text(r, 'leave-yes')).toBe('Сдаться')
    expect(get(r, 'leave-yes').classes()).toContain('btn-danger')
    expect(get(r, 'leave-stay').classes()).toContain('btn-secondary')

    await click(r, 'leave-stay')
    expect(has(r, 'leave-confirm')).toBe(false)
    expect(api.battles.surrender).not.toHaveBeenCalled()
    expect(has(r, 'probe')).toBe(true)
  })

  it('the system “Back” asks the same question', async () => {
    const r = await render(battle())

    tg.backHandler?.()
    await flushPromises()

    expect(text(r, 'leave-title')).toBe('Сдаться и выйти?')
  })

  it('“Surrender” gives up: the result of the defeat is on the same screen, the learner is not thrown out', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.surrender).mockResolvedValue(finishedBattle('lost', 'surrender'))

    await click(r, 'close-btn')
    await click(r, 'leave-yes')

    expect(api.battles.surrender).toHaveBeenCalledWith(15)
    expect(has(r, 'leave-confirm')).toBe(false)
    expect(route(r).name).toBe('battle')
    expect(text(r, 'result-title')).toBe('Поражение')
    expect(text(r, 'result-reason')).toBe('Вы сдались: победа засчитана сопернику.')
  })

  it('a surrender that did not go through is told, and the match goes on', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.surrender).mockRejectedValue(offline())

    await click(r, 'close-btn')
    await click(r, 'leave-yes')

    expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
    expect(has(r, 'probe')).toBe(true)
  })

  it('while the learner is deciding the match goes on: the poll still runs', async () => {
    const r = await render(battle())
    await click(r, 'close-btn')
    vi.mocked(api.battles.poll).mockClear()

    await pass(2000)

    expect(api.battles.poll).toHaveBeenCalled()
    expect(has(r, 'leave-confirm')).toBe(true)
  })

  it('the opponent surrendered while the learner was deciding: the result replaces the question’s screen', async () => {
    const r = await render(battle())
    await click(r, 'close-btn')
    servePoll(() => pollOf(finishedBattle('won', 'surrender')))
    serveBattle(() => finishedBattle('won', 'surrender'))

    await pass(2000)

    expect(text(r, 'result-title')).toBe('Победа')
    expect(text(r, 'result-reason')).toBe('Соперник сдался — победа ваша.')
  })
})

describe('BattleView — trouble that does not end the match', () => {
  it('no connection: “No connection, trying…” on top, the screen stays, and it goes when the connection is back', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.poll).mockRejectedValueOnce(offline())

    await pass(2000)
    expect(text(r, 'offline-strip')).toBe('Нет связи, пробуем…')
    expect(has(r, 'probe')).toBe(true)
    expect(text(r, 'headline')).toBe('Раунд 2 из 5')

    servePoll(() => poll({ version: 24 }))
    await pass(4000) // the pause grew to four seconds after the failure
    expect(has(r, 'offline-strip')).toBe(false)
  })

  it('the poll slows down while the connection is gone and keeps trying', async () => {
    await render(battle())
    vi.mocked(api.battles.poll).mockClear()
    vi.mocked(api.battles.poll).mockRejectedValue(offline())

    await pass(2000)
    expect(api.battles.poll).toHaveBeenCalledTimes(1)
    await pass(3900)
    expect(api.battles.poll).toHaveBeenCalledTimes(1)
    await pass(200)
    expect(api.battles.poll).toHaveBeenCalledTimes(2)
  })

  it('the opponent lost connection: the strip counts the seconds, and says what happens next', async () => {
    const r = await render(battle())
    servePoll(() => poll({ version: 24, opponent_present: false }))

    await pass(2000)
    expect(text(r, 'absent-text')).toBe('Соперник потерял связь… 0:00')
    expect(text(r, 'absent-hint')).toBe('Если он не вернётся, победа достанется вам.')

    await pass(21_000)
    expect(text(r, 'absent-text')).toBe('Соперник потерял связь… 0:21')
  })

  it('the opponent is back: the strip goes', async () => {
    const r = await render(battle())
    servePoll(() => poll({ version: 24, opponent_present: false }))
    await pass(2000)
    expect(has(r, 'absent-strip')).toBe(true)

    servePoll(() => poll({ version: 25, opponent_present: true }))
    await pass(2000)

    expect(has(r, 'absent-strip')).toBe(false)
  })

  it('in the room the hint says the room closes', async () => {
    const r = await render(roomBattle({ ready: { me: false, opponent: false } }))
    servePoll(() => poll({ status: 'room', version: 3, opponent_present: false, round: null }))

    await pass(2000)

    expect(text(r, 'absent-hint')).toBe('Если он не вернётся, комната закроется.')
  })

  it('the strips are symbols and words (not a colour alone), and neither is the red of an error', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.poll).mockRejectedValueOnce(offline())
    await pass(2000)

    const strip = get(r, 'offline-strip')
    expect(strip.find('svg').exists()).toBe(true)
    expect(strip.classes()).toContain('bg-warning-soft')
    expect(strip.attributes('role')).toBe('status')
  })

  it('no strip when all is well', async () => {
    const r = await render(battle())

    expect(has(r, 'strips')).toBe(false)
  })
})

describe('BattleView — the end', () => {
  it('a battle that is over is read once and polled no more', async () => {
    const r = await render(finishedBattle('won'))

    expect(text(r, 'result-title')).toBe('Победа')
    await pass(4000)
    expect(api.battles.poll).not.toHaveBeenCalled()
  })

  it('the score and the rounds of the result are the server’s', async () => {
    const r = await render(finishedBattle('won'))

    expect(text(r, 'score-me')).toBe('300')
    expect(text(r, 'score-opponent')).toBe('200')
    expect(r.wrapper.findAll('[data-testid=round-row]')).toHaveLength(5)
  })

  it.each([
    ['completed', 'Сыграны все раунды.'],
    ['surrender', 'Вы сдались: победа засчитана сопернику.'],
    ['absent', 'Вы пропали из матча: засчитано поражение.'],
  ] as const)('a match lost by %s says so', async (reason, words) => {
    const r = await render(finishedBattle('lost', reason))

    expect(text(r, 'result-reason')).toBe(words)
  })

  it.each([
    ['both_absent', 'Оба игрока пропали — матч закрыт без победителя.'],
    ['declined', 'Приглашение отклонено.'],
    ['invite_expired', 'Приглашение не приняли вовремя.'],
    ['room_expired', 'Комната закрыта: матч не начался вовремя.'],
    ['canceled', 'Матч отменили до начала.'],
    ['voided', 'Матч аннулирован из-за технической проблемы. Очки не засчитаны.'],
  ] as const)('a battle that fell through (%s) says so', async (reason: BattleEndReason, words) => {
    const r = await render(closedBattle(reason))

    expect(text(r, 'result-title')).toBe('Матч не состоялся')
    expect(text(r, 'result-reason')).toBe(words)
  })

  it('the learner who won hears it: a tap of success and the word to a screen reader', async () => {
    const r = await render(battle())
    servePoll(() => pollOf(finishedBattle('won')))
    serveBattle(() => finishedBattle('won'))

    await pass(2000)

    expect(text(r, 'battle-live')).toBe('Победа')
    expect(tg.notification).toHaveBeenCalledWith('success')
  })

  it('has one primary button', async () => {
    const r = await render(finishedBattle())

    expect(primaries(r)).toHaveLength(1)
  })

  it('“To the friends” leads to the friends', async () => {
    const r = await render(finishedBattle())

    await click(r, 'friends-btn')

    expect(route(r).name).toBe('friends')
  })

  it('“To the course” leads to the course of the battle', async () => {
    const r = await render(finishedBattle())

    await click(r, 'course-btn')

    expect(route(r).name).toBe('course')
    expect(route(r).params.slug).toBe('english-a1')
  })

  it('“Back” and ✕ lead to the friends, with no question', async () => {
    const r = await render(finishedBattle())

    await click(r, 'close-btn')

    expect(has(r, 'leave-confirm')).toBe(false)
    expect(route(r).name).toBe('friends')
  })

  it('“Back” leads to the course when the learner came from the practice hub', async () => {
    const r = await render(finishedBattle(), { history: 'web', from: '/practice/english-a1' })

    expect(r.router.options.history.state.back).toBe('/practice/english-a1')
    tg.backHandler?.()
    await flushPromises()

    expect(route(r).name).toBe('course')
    expect(route(r).params.slug).toBe('english-a1')
  })

  it('a battle that fell through has the same way out', async () => {
    const r = await render(closedBattle('canceled'))

    tg.backHandler?.()
    await flushPromises()

    expect(route(r).name).toBe('friends')
  })
})

describe('BattleView — the rematch', () => {
  it('“Rematch” invites the friend to the same format and place, and leads to the new battle', async () => {
    const r = await render(finishedBattle())
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16, role: 'challenger' }))

    await click(r, 'rematch-btn')

    expect(api.battles.invite).toHaveBeenCalledWith({ rematch_of: 15, mode: 'sentence', course: 'english-a1', lesson_id: 41 })
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('16')
  })

  it('a rematch that is refused is told, and the learner stays', async () => {
    const r = await render(finishedBattle())
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_friend_no_access'))

    await click(r, 'rematch-btn')

    expect(showErrorToast).toHaveBeenCalledWith('У вашего друга пока нет доступа к практике.')
    expect(route(r).params.id).toBe('15')
    expect(has(r, 'battle-result')).toBe(true)
  })

  const offered = (outcome: 'won' | 'lost' = 'lost') => finishedBattle(outcome, 'completed', { rematch: { battle_id: 16, status: 'invited' } })
  const theirs = () => invitedBattle({ id: 16, role: 'opponent' })

  it('the friend offers one: it is answered on the result screen, and “Accept” opens its room', async () => {
    const r = await render(offered(), { others: { 16: theirs() } })

    expect(api.battles.get).toHaveBeenCalledWith(16)
    expect(has(r, 'rematch-offer')).toBe(true)
    expect(text(r, 'rematch-offer')).toContain('Dilnoza предлагает реванш')
    expect(has(r, 'rematch-btn')).toBe(false)
    expect(primaries(r)).toHaveLength(1)

    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 16, role: 'opponent' }))
    await click(r, 'accept-rematch-btn')
    expect(api.battles.accept).toHaveBeenCalledWith(16)
    expect(route(r).params.id).toBe('16')
  })

  it('“Decline” takes the offer off the screen', async () => {
    const r = await render(offered('won'), { others: { 16: theirs() } })
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { id: 16, role: 'opponent' }))

    await click(r, 'decline-rematch-btn')

    expect(api.battles.decline).toHaveBeenCalledWith(16)
    expect(has(r, 'rematch-offer')).toBe(false)
    expect(has(r, 'rematch-btn')).toBe(true)
  })

  it('an offer that has run out cannot be accepted: told, and the offer is gone', async () => {
    const r = await render(offered(), { others: { 16: theirs() } })
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'invite_expired' }))
    serveBattle((id) => (id === 15 ? finishedBattle('lost', 'completed', { version: 41, rematch: { battle_id: 16, status: 'closed' } }) : closedBattle('invite_expired', { id: 16, role: 'opponent' })))

    await click(r, 'accept-rematch-btn')

    expect(showErrorToast).toHaveBeenCalledWith('Этот баттл уже закрыт.')
    expect(route(r).params.id).toBe('15')
    expect(has(r, 'rematch-offer')).toBe(false)
    expect(has(r, 'rematch-btn')).toBe(true)
  })

  it('an offer of the learner’s own (made on another device) is a way back into it', async () => {
    const r = await render(finishedBattle('won', 'completed', { rematch: { battle_id: 16, status: 'invited' } }), {
      others: { 16: invitedBattle({ id: 16, role: 'challenger' }) },
    })

    expect(has(r, 'rematch-own')).toBe(true)
    expect(has(r, 'rematch-btn')).toBe(false)
    await click(r, 'open-rematch-btn')
    expect(route(r).params.id).toBe('16')
  })

  it('an offer that comes after the end appears by itself: the result screen asks again every five seconds', async () => {
    const r = await render(finishedBattle())
    expect(has(r, 'rematch-offer')).toBe(false)
    serveBattle((id) => (id === 15 ? finishedBattle('won', 'completed', { version: 41, rematch: { battle_id: 16, status: 'invited' } }) : theirs()))

    await pass(4900)
    expect(has(r, 'rematch-offer')).toBe(false)
    await pass(200)

    expect(has(r, 'rematch-offer')).toBe(true)
    expect(text(r, 'rematch-offer')).toContain('Dilnoza предлагает реванш')
  })

  it('an offer that is taken back disappears by itself too', async () => {
    const r = await render(offered(), { others: { 16: theirs() } })
    expect(has(r, 'rematch-offer')).toBe(true)
    serveBattle((id) => (id === 15 ? finishedBattle('lost', 'completed', { version: 42, rematch: { battle_id: 16, status: 'closed' } }) : closedBattle('canceled', { id: 16, role: 'opponent' })))

    await pass(5100)

    expect(has(r, 'rematch-offer')).toBe(false)
    expect(has(r, 'rematch-btn')).toBe(true)
  })

  it('the result screen stops asking after a couple of minutes, and when the learner leaves it', async () => {
    const r = await render(finishedBattle())
    vi.mocked(api.battles.get).mockClear()

    await pass(24 * 5000 + 1000)
    expect(vi.mocked(api.battles.get).mock.calls.length).toBe(24)
    vi.mocked(api.battles.get).mockClear()
    await pass(30_000)
    expect(api.battles.get).not.toHaveBeenCalled()

    r.wrapper.unmount()
  })
})

describe('BattleView — the screen as a whole', () => {
  it('stops everything when the learner leaves: no more beats, nothing is kept', async () => {
    const r = await render(battle())
    await pass(2000)
    r.wrapper.unmount()
    mounted.length = 0
    vi.mocked(api.battles.poll).mockClear()
    vi.mocked(api.battles.get).mockClear()

    await pass(20_000)

    expect(api.battles.poll).not.toHaveBeenCalled()
    expect(api.battles.get).not.toHaveBeenCalled()
  })

  it('does not poll in the background, and reads at once on return', async () => {
    await render(battle())
    vi.mocked(api.battles.poll).mockClear()

    setVisibility('hidden')
    await pass(10_000)
    expect(api.battles.poll).not.toHaveBeenCalled()

    setVisibility('visible')
    await flushPromises()
    expect(api.battles.poll).toHaveBeenCalledTimes(1)
  })

  it('a reply that comes after the learner has left changes nothing and moves nobody', async () => {
    const reply = pending<Battle>()
    const r = await render(reply.promise)
    const push = vi.spyOn(r.router, 'push')
    const replace = vi.spyOn(r.router, 'replace')
    r.wrapper.unmount()
    mounted.length = 0
    reply.resolve(roomBattle())
    await pass(10_000)

    expect(push).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
    expect(api.battles.poll).not.toHaveBeenCalled()
  })

  it('has exactly the buttons it should in every state: one primary at most', async () => {
    const states: [string, Battle, BattleRound | null][] = [
      ['invited, sent', invitedBattle({ role: 'challenger' }), null],
      ['invited, received', invitedBattle({ role: 'opponent' }), null],
      ['room', roomBattle(), null],
      ['countdown', countdownBattle(), null],
      ['answering', battle(), answeringRound()],
      ['waiting', battle({ round: roundState({ my_answered: true }) }), null],
      ['reveal', battle({ round: roundState({ phase: 'reveal', phase_ends_at: at(3), my_answered: true }) }), revealRound()],
      ['finished', finishedBattle(), null],
      ['closed', closedBattle('canceled'), null],
    ]

    for (const [name, state, round] of states) {
      const r = await render(state, { round })
      expect(primaries(r).length, name).toBeLessThanOrEqual(1)
      r.wrapper.unmount()
      mounted.length = 0
    }
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render(roomBattle({ ready: { me: false, opponent: false } }))

    expect(text(r, 'room-title')).toBe('Xona tayyor')
    expect(text(r, 'ready-btn')).toBe('Tayyorman')
  })
})
