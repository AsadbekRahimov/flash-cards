import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, createWebHistory } from 'vue-router'
import BattleView from '../BattleView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { RESULT_CHECKS_MAX } from '@/stores/battle'
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

// The sheet has its own spec; here only that it opens, for which reason, and that it closes.
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

/** What the probe finds in the screen: the source of the clips it provides (the component of a dictation asks it for its "Retry"). */
const probeClip = vi.hoisted(() => ({ last: null as Blob | null, provided: false }))

// The component of a mode is the business of its own task; here a probe that answers when it is told to.
vi.mock('@/components/practice/modeComponents', async () => {
  const { defineComponent, h, inject } = await import('vue')
  const { PRACTICE_CLIP_SOURCE } = await import('@/composables/usePracticeAudio')
  const Probe = defineComponent({
    props: { element: { type: Object, required: true }, mode: { type: String, default: 'solo' }, disabled: { type: Boolean, default: false } },
    emits: ['answer', 'skip'],
    setup: (props, { emit }) => {
      const source = inject(PRACTICE_CLIP_SOURCE, null)
      probeClip.provided = source !== null
      return () =>
        h('div', { 'data-testid': 'probe', 'data-element': (props.element as { id: string }).id, 'data-mode': props.mode, 'data-disabled': String(props.disabled) }, [
          h('button', { 'data-testid': 'probe-answer', onClick: () => emit('answer', { order: ['fW2', 'fT5'] }) }),
          h('button', { 'data-testid': 'probe-skip', onClick: () => emit('skip') }),
          h('button', {
            'data-testid': 'probe-clip',
            onClick: () => {
              void source?.((props.element as { audio_url?: string }).audio_url ?? '').then((blob) => (probeClip.last = blob))
            },
          }),
        ])
    },
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

    expect(text(r, 'room-title')).toBe('Ждём ответа: Dilnoza')
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
    await pass(4000)
    expect(api.battles.poll).not.toHaveBeenCalled() // the beat of the match has stopped (the slow reading for a rematch comes every five seconds)
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
    await pass(2500) // 4.5 s: the beat at 4 s has been, the wake-up (3 s + the grace of the server, 1.5 s) not yet
    expect(api.battles.poll).toHaveBeenCalledTimes(2)
    await pass(200) // 4.7 s
    expect(api.battles.poll).toHaveBeenCalledTimes(3)
    expect(has(r, 'probe')).toBe(true)
  })

  it('takes the grace of the deadline from the battle (deadline_grace_ms), not from a guess of its own', async () => {
    const r = await render(battle({ deadline_grace_ms: 3000, round: roundState({ deadline_at: at(3) }) }), { round: answeringRound({ deadline_at: at(3) }) })
    vi.mocked(api.battles.poll).mockClear()

    await pass(2000)
    await pass(2000) // 4 s: the two beats; with a grace of 1.5 s the wake-up would have come at 4.58 s already
    await pass(1000) // 5 s: the beat at 6 s has not been
    expect(api.battles.poll).toHaveBeenCalledTimes(2)
    await pass(1100) // 6.1 s: the wake-up at 3 + 3 s + the slack, and the beat at 6 s
    expect(vi.mocked(api.battles.poll).mock.calls.length).toBeGreaterThanOrEqual(3)
    expect(has(r, 'probe')).toBe(true)
  })

  it('a wake-up at the deadline that finds a poll on its way is not lost: another poll follows that one at once', async () => {
    const r = await render(battle({ round: roundState({ deadline_at: at(3) }) }), { round: answeringRound({ deadline_at: at(3) }) })
    vi.mocked(api.battles.poll).mockClear()
    await pass(2500) // the beat at 2 s is done; the one at 4 s is not asked yet
    const slow = pending<BattlePoll>()
    vi.mocked(api.battles.poll).mockReturnValueOnce(slow.promise)

    await pass(1500) // 4 s: the beat starts and hangs; the wake-up at 4.58 s will find it on its way
    expect(api.battles.poll).toHaveBeenCalledTimes(2)
    await pass(1000) // 5 s: the wake-up has come
    expect(api.battles.poll).toHaveBeenCalledTimes(2) // it did not start a second poll beside the one on its way
    slow.resolve(stamp(poll({ version: 23 })))
    await flushPromises()

    expect(api.battles.poll).toHaveBeenCalledTimes(3) // ... and asked again as soon as that one was done
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
    vi.mocked(api.battles.audioFailed).mockResolvedValue({ verified: true, battle: closedBattle('voided', { mode: 'dictation', version: 30, starts_at: at(-60) }) })
    const r = await render(battle({ mode: 'dictation' }), { round: answeringRound({ element: dictationElement({ audio_url: CLIPS[1] }) }) })

    // a dictation voided after the start: the match was on, and was broken off; the recording is the reason
    expect(text(r, 'result-reason')).toBe('Матч отменён: запись недоступна. Очки не засчитаны.')
    expect(text(r, 'result-title')).toBe('Матч прерван')
  })

  it('a recording that was loaded and then fails to PLAY is a fault of the device: the file is there, and the server is not told', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    const r = await render(battle({ mode: 'dictation' }), { round: answeringRound({ element: dictationElement({ id: 'eDict00002', audio_url: CLIPS[1] }) }) })
    expect(api.battles.audioBlob).toHaveBeenCalledWith(CLIPS[1])

    // the next play of the clip does not work: the slot of the clip turns to failed
    const { usePracticeStore } = await import('@/stores/practice')
    usePracticeStore().audio.state = 'failed'
    await flushPromises()

    expect(has(r, 'clip-failed')).toBe(true) // the learner may ask for the clip again
    expect(api.battles.audioFailed).not.toHaveBeenCalled()
  })

  it('the round gives its source of clips to the component of the mode: the “Retry” of the dictation plays what the room loaded, like the “Retry” of the screen', async () => {
    const fromRoom = new Blob(['room'], { type: 'audio/mpeg' })
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => Promise.resolve(url === CLIPS[0] ? fromRoom : clip()))
    probeClip.last = null
    const r = await render(roomBattle({ mode: 'dictation', audio_preload: CLIPS }))
    await flushPromises()
    expect(api.battles.audioBlob).toHaveBeenCalledTimes(5) // the room loaded the five clips

    // the match begins; round 1 is the first clip
    vi.mocked(api.battles.audioBlob).mockClear()
    const playing = battle({ mode: 'dictation', version: 6, round: roundState({ no: 1, deadline_at: at(45) }) })
    servePoll(() => pollOf(playing))
    serveBattle(() => playing)
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(answeringRound({ no: 1, element: dictationElement({ id: 'eDict00001', audio_url: CLIPS[0] }) }))))
    await pass(2000)
    expect(probeClip.provided).toBe(true)
    expect(api.battles.audioBlob).not.toHaveBeenCalled() // the screen took the clip from the room, not from the network

    await click(r, 'probe-clip') // what the component asks for on its own "Retry"
    expect(probeClip.last).toBe(fromRoom)
    expect(api.battles.audioBlob).not.toHaveBeenCalled()
  })

  it('and goes to the network for a clip the room does not have', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    probeClip.last = null
    const r = await render(battle({ mode: 'dictation' }), { round: answeringRound({ element: dictationElement({ audio_url: CLIPS[1] }) }) })
    vi.mocked(api.battles.audioBlob).mockClear()

    await click(r, 'probe-clip')

    expect(api.battles.audioBlob).toHaveBeenCalledWith(CLIPS[1])
    expect(probeClip.last).toBeInstanceOf(Blob)
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

  it('the opponent lost connection: the strip says what is left of the wait of the server, counts it down, and says what happens next', async () => {
    const r = await render(battle())
    // the server saw the opponent last at 10:00:00 and waits `presence_grace_seconds` (30 s) for them
    servePoll(() => poll({ version: 24, opponent_present: false, opponent_absent_since: at(-5) }))

    await pass(2000) // 10:00:07
    expect(text(r, 'absent-text')).toBe('Соперник потерял связь.')
    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 23 с, победа ваша.')

    await pass(5000)
    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 18 с, победа ваша.')
  })

  it('takes the wait of the server from the battle, not from a number of its own', async () => {
    const r = await render(battle({ presence_grace_seconds: 45 }))
    servePoll(() => poll({ version: 24, opponent_present: false, opponent_absent_since: at(-5) }))

    await pass(2000)

    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 38 с, победа ваша.')
  })

  it('after a reload the number is the same, not a count from 0:00: the first reading already says since when', async () => {
    // the opponent was last seen 7 s before the reading (10:00:05): 23 s of the wait are left
    const r = await render(battle({ opponent_present: false, opponent_absent_since: at(-7) }))

    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 23 с, победа ваша.')
    await pass(4000)
    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 19 с, победа ваша.')
  })

  it('never goes below 0: past the wait the strip says 0 until the server ends the match', async () => {
    const r = await render(battle())
    servePoll(() => poll({ version: 24, opponent_present: false, opponent_absent_since: at(-30) }))

    await pass(2000)
    await pass(10_000)

    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 0 с, победа ваша.')
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

  it('in the room, and in the countdown, the hint says the match is canceled (not that the learner wins)', async () => {
    const r = await render(roomBattle({ ready: { me: false, opponent: false } }))
    servePoll(() => poll({ status: 'room', version: 3, opponent_present: false, opponent_absent_since: at(-5), round: null }))

    await pass(2000)

    expect(text(r, 'absent-hint')).toBe('Если не вернётся за 23 с, матч отменится.')
    expect(text(r, 'absent-text')).toBe('Соперник потерял связь.')
  })

  it('the connection and the server are told apart: “No connection” for the network, “The server does not answer” for a 5xx and a 429', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.poll).mockRejectedValueOnce(apiError('server', 503, 'x'))

    await pass(2000)
    expect(text(r, 'offline-strip')).toBe('Сервер не отвечает, пробуем ещё раз…')

    vi.mocked(api.battles.poll).mockRejectedValueOnce(apiError('rate_limited', 429, 'x'))
    await pass(4000)
    expect(text(r, 'offline-strip')).toBe('Сервер не отвечает, пробуем ещё раз…')

    vi.mocked(api.battles.poll).mockRejectedValueOnce(offline())
    await pass(8000)
    expect(text(r, 'offline-strip')).toBe('Нет связи, пробуем…')
  })

  it('a poll that hangs up to its limit is a lost connection: “No connection”', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.poll).mockRejectedValueOnce(new ApiClientError('network', 0, 'timeout', 'The request timed out'))

    await pass(2000)

    expect(text(r, 'offline-strip')).toBe('Нет связи, пробуем…')
  })

  it('says the trouble of the server in Uzbek too', async () => {
    setLocale('uz')
    const r = await render(battle())
    vi.mocked(api.battles.poll).mockRejectedValueOnce(apiError('server', 503, 'x'))

    await pass(2000)

    expect(text(r, 'offline-strip')).toBe('Server javob bermayapti, qayta urinilmoqda…')
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
  it('a battle that is over is read once; the beat of the match stops, only the slow reading for a rematch goes on (every five seconds)', async () => {
    const r = await render(finishedBattle('won'))

    expect(text(r, 'result-title')).toBe('Победа')
    await pass(4000)
    expect(api.battles.poll).not.toHaveBeenCalled() // no beat every two seconds
    await pass(1500)
    expect(api.battles.poll).toHaveBeenCalledTimes(1) // the reading for a rematch, at five seconds
    expect(api.battles.get).toHaveBeenCalledTimes(1) // the battle itself is not read again
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

  it('a match that began and was broken off (both players gone) is “interrupted”, not “did not take place”, with its reason - and a screen reader hears the same', async () => {
    const r = await render(closedBattle('both_absent', { starts_at: at(-60) }))

    expect(text(r, 'result-title')).toBe('Матч прерван')
    expect(text(r, 'result-reason')).toBe('Оба игрока пропали — матч закрыт без победителя.')
    expect(text(r, 'battle-live')).toBe('Матч прерван')
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
  const OFFER = { battle_id: 16, status: 'invited', by_me: false } as const

  it('“Rematch” invites the friend to the same format and place, and leads to the new battle', async () => {
    const r = await render(finishedBattle('lost'))
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 16 }))
    serveBattle((id) => (id === 16 ? invitedBattle({ id: 16 }) : finishedBattle('lost')))

    await click(r, 'rematch-btn')

    expect(api.battles.invite).toHaveBeenCalledWith({ rematch_of: 15, mode: 'sentence', course: 'english-a1', lesson_id: 41, friendship_id: 11 })
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('16')
  })

  it('a rematch that is refused is told, and the learner stays', async () => {
    const r = await render(finishedBattle())
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_disabled'))

    await click(r, 'rematch-btn')

    expect(showErrorToast).toHaveBeenCalledWith('Баттлы сейчас выключены.')
    expect(has(r, 'battle-result')).toBe(true)
  })

  it('the friend offered a rematch at the same moment: the screen leads into THEIR invitation, where the learner accepts it - no toast', async () => {
    const r = await render(finishedBattle('lost'), { others: { 16: invitedBattle({ id: 16, role: 'opponent' }) } })
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 16 }))

    await click(r, 'rematch-btn')

    expect(api.battles.get).toHaveBeenCalledWith(16)
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('16')
    expect(showErrorToast).not.toHaveBeenCalled()
    // and there it is the invitation that came: “Accept”
    await r.wrapper.setProps({ id: 16 })
    await flushPromises()
    expect(has(r, 'accept-btn')).toBe(true)
    expect(text(r, 'room-title')).toContain('Dilnoza')
  })

  it('a battle of the learner’s own is in the way of the rematch: the toast, and the way to it (the old message stands)', async () => {
    const r = await render(finishedBattle('lost'), { others: { 9: invitedBattle({ id: 9, role: 'challenger' }) } })
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 9 }))

    await click(r, 'rematch-btn')

    expect(showErrorToast).toHaveBeenCalledWith('У вас уже есть активный баттл. Завершите его, чтобы начать новый.')
    expect(has(r, 'battle-result')).toBe(true)
    expect(route(r).params.id).toBe('15')
  })

  it('the friend offers one (by_me is false): it is answered on the result screen, and “Accept” opens its room', async () => {
    const r = await render(finishedBattle('lost', 'completed', { rematch: OFFER }))
    vi.mocked(api.battles.accept).mockResolvedValue(roomBattle({ id: 16, role: 'opponent' }))
    serveBattle((id) => (id === 16 ? roomBattle({ id: 16, role: 'opponent' }) : finishedBattle('lost')))

    expect(text(r, 'rematch-offer')).toContain('Dilnoza предлагает реванш')
    expect(api.battles.get).toHaveBeenCalledTimes(1) // who offered it came with the battle: the friend's battle was not read
    await click(r, 'accept-rematch-btn')

    expect(api.battles.accept).toHaveBeenCalledWith(16)
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('16')
  })

  it('“Decline” takes the offer off the screen', async () => {
    const r = await render(finishedBattle('lost', 'completed', { rematch: OFFER }))
    vi.mocked(api.battles.decline).mockResolvedValue(closedBattle('declined', { id: 16, role: 'opponent' }))

    await click(r, 'decline-rematch-btn')

    expect(api.battles.decline).toHaveBeenCalledWith(16)
    expect(has(r, 'rematch-offer')).toBe(false)
    expect(has(r, 'rematch-btn')).toBe(true)
  })

  it('an offer that has run out cannot be accepted: told, and the offer is gone', async () => {
    const r = await render(finishedBattle('lost', 'completed', { rematch: OFFER }))
    vi.mocked(api.battles.accept).mockRejectedValue(apiError('conflict', 409, 'battle_closed', { end_reason: 'invite_expired' }))
    servePoll(() => pollOf(finishedBattle('lost'), { rematch: { ...OFFER, status: 'closed' } }))

    await click(r, 'accept-rematch-btn')

    expect(showErrorToast).toHaveBeenCalledWith('Этот баттл уже закрыт.')
    expect(has(r, 'rematch-offer')).toBe(false)
    expect(route(r).params.id).toBe('15')
  })

  it('an offer of the learner’s own (by_me: made on another device) is a way back into it', async () => {
    const r = await render(finishedBattle('won', 'completed', { rematch: { ...OFFER, by_me: true } }))

    expect(has(r, 'rematch-offer')).toBe(false)
    expect(has(r, 'rematch-btn')).toBe(false)
    expect(text(r, 'rematch-own')).toContain('Вы предложили реванш')
    await click(r, 'open-rematch-btn')
    expect(route(r).params.id).toBe('16')
  })

  it('a rematch the learner took on another device is a way back into it, too', async () => {
    const r = await render(finishedBattle('won', 'completed', { rematch: { battle_id: 16, status: 'room', by_me: false } }))

    expect(text(r, 'rematch-own')).toContain('Реванш уже идёт')
    expect(has(r, 'rematch-btn')).toBe(false)
    await click(r, 'open-rematch-btn')
    expect(route(r).params.id).toBe('16')
  })

  it('an offer that comes after the end appears by itself: the result screen asks again every five seconds - with the poll, not with the battle', async () => {
    const r = await render(finishedBattle())
    expect(has(r, 'rematch-offer')).toBe(false)
    servePoll(() => pollOf(finishedBattle(), { rematch: OFFER }))

    await pass(5000)

    expect(api.battles.poll).toHaveBeenCalledWith(15)
    expect(api.battles.get).toHaveBeenCalledTimes(1) // never again GET /battles/{id}
    expect(text(r, 'rematch-offer')).toContain('Dilnoza предлагает реванш')
    expect(primaries(r)).toHaveLength(1)
  })

  it('an offer that is taken back disappears by itself too', async () => {
    const r = await render(finishedBattle('won', 'completed', { rematch: OFFER }))
    expect(has(r, 'rematch-offer')).toBe(true)
    servePoll(() => pollOf(finishedBattle(), { rematch: { ...OFFER, status: 'closed' } }))

    await pass(5000)

    expect(has(r, 'rematch-offer')).toBe(false)
    expect(has(r, 'rematch-btn')).toBe(true)
  })

  it('the result screen stops asking after a couple of minutes, and when the learner leaves it', async () => {
    const r = await render(finishedBattle())
    servePoll(() => pollOf(finishedBattle()))

    await pass(5000 * 24 + 1000)
    expect(vi.mocked(api.battles.poll).mock.calls.length).toBe(RESULT_CHECKS_MAX)
    await pass(30_000)
    expect(vi.mocked(api.battles.poll).mock.calls.length).toBe(RESULT_CHECKS_MAX)

    vi.mocked(api.battles.poll).mockClear()
    r.wrapper.unmount()
    mounted.length = 0
    await pass(30_000)
    expect(api.battles.poll).not.toHaveBeenCalled()
  })
})

describe('BattleView — an answer that was not delivered belongs to its round (review Б1, Б2)', () => {
  /** The connection is gone: the answer of round 2 goes four times and the screen asks to retry. */
  async function undeliveredInRound2() {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValue(offline())
    vi.mocked(api.battles.poll).mockRejectedValue(offline())

    await get(r, 'probe-answer').trigger('click')
    await pass(500)
    await pass(1000)
    await pass(2000)
    expect(has(r, 'battle-send-failed')).toBe(true)
    return r
  }

  const round3 = (overrides: Partial<BattlePoll> = {}) => poll({ version: 30, round: roundState({ no: 3, deadline_at: at(90) }), scores: { me: 100, opponent: 225 }, ...overrides })
  const serveRound3 = () => {
    servePoll(() => round3())
    vi.mocked(api.battles.round).mockImplementation(() =>
      Promise.resolve(stamp(answeringRound({ no: 3, deadline_at: at(90), element: sentenceElement({ id: 'eSent00003' }) }))),
    )
  }

  it('V1: an answer of round 2 that never got through does not take round 3 from the learner - the task is there, nothing is told, and it can be answered', async () => {
    const r = await undeliveredInRound2()

    // the connection is back after the deadline: the server is in round 3 (round 2 got a time-out)
    serveRound3()
    await pass(10_000)

    expect(text(r, 'headline')).toBe('Раунд 3 из 5')
    expect(has(r, 'battle-send-failed')).toBe(false)
    expect(has(r, 'retry-answer-btn')).toBe(false)
    expect(get(r, 'probe').attributes('data-element')).toBe('eSent00003')
    expect(get(r, 'probe').attributes('data-disabled')).toBe('false')
    expect(showErrorToast).not.toHaveBeenCalled()

    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply({ poll: poll({ version: 31, round: roundState({ no: 3, deadline_at: at(90), my_answered: true }) }) }))
    await get(r, 'probe-answer').trigger('click')
    await flushPromises()
    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.battles.answer).mock.calls[0]?.[1].round).toBe(3)
    expect(has(r, 'battle-waiting')).toBe(true)
  })

  it('the same when the lost answer WAS counted (only its reply was lost): “waiting” first, then round 3 with its task', async () => {
    const r = await undeliveredInRound2()
    servePoll(() => poll({ version: 24, round: roundState({ my_answered: true }) }))

    await pass(10_000)
    expect(has(r, 'battle-waiting')).toBe(true)
    expect(has(r, 'battle-send-failed')).toBe(false)

    serveRound3()
    await pass(2000)

    expect(text(r, 'headline')).toBe('Раунд 3 из 5')
    expect(has(r, 'battle-send-failed')).toBe(false)
    expect(has(r, 'battle-waiting')).toBe(false)
    expect(get(r, 'probe').attributes('data-element')).toBe('eSent00003')
    expect(showErrorToast).not.toHaveBeenCalled()
  })

  it('Б1b: the round moves on while the repeat of the old answer is still on its way - round 3 is not locked, no note of sending, no toast when the old one is refused', async () => {
    const r = await render(battle({ round: roundState({ deadline_at: at(1) }) }))
    const late = pending<never>()
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockReturnValueOnce(late.promise)
    await get(r, 'probe-answer').trigger('click')
    await flushPromises()

    serveRound3()
    await pass(2000)

    expect(text(r, 'headline')).toBe('Раунд 3 из 5')
    expect(has(r, 'sending')).toBe(false)
    expect(has(r, 'battle-resending')).toBe(false)
    expect(get(r, 'probe').attributes('data-element')).toBe('eSent00003')
    expect(get(r, 'probe').attributes('data-disabled')).toBe('false')

    late.reject(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'answering' }))
    await flushPromises()
    expect(showErrorToast).not.toHaveBeenCalled()
    expect(get(r, 'probe').attributes('data-disabled')).toBe('false')
  })

  it('Б2: the repeat of an answer that WAS counted meets “time is up” after the round was resolved - no “not accepted” beside “Correct”: the review tells the truth', async () => {
    const r = await render(battle({ round: roundState({ opponent_answered: true }) }))
    // the first try: the server counts it (and, being the second answer, resolves the round), the reply is lost; the repeat meets round_closed
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'reveal' }))
    servePoll(() => poll({ version: 25, round: roundState({ phase: 'reveal', phase_ends_at: at(4), my_answered: true, opponent_answered: true }), scores: { me: 200, opponent: 125 } }))
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(revealRound())))

    await get(r, 'probe-answer').trigger('click')
    await pass(500)

    const ids = vi.mocked(api.battles.answer).mock.calls.map((call) => call[1].request_id)
    expect(ids).toHaveLength(2)
    expect(new Set(ids).size).toBe(1)
    expect(showErrorToast).not.toHaveBeenCalled()
    expect(has(r, 'battle-reveal')).toBe(true)
    expect(get(r, 'side-me').attributes('data-verdict')).toBe('correct')
  })

  it('Б2: the same after the last round - no “This battle is closed” above the result', async () => {
    const r = await render(battle({ round: roundState({ no: 5, opponent_answered: true }) }), { round: answeringRound({ no: 5 }) })
    vi.mocked(api.battles.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(offline()).mockRejectedValueOnce(apiError('conflict', 409, 'battle_closed', { end_reason: 'completed' }))
    servePoll(() => pollOf(finishedBattle('won')))
    serveBattle(() => finishedBattle('won'))

    await get(r, 'probe-answer').trigger('click')
    await pass(500)
    await pass(1000)

    expect(showErrorToast).not.toHaveBeenCalled()
    expect(text(r, 'result-title')).toBe('Победа')
  })

  it('a FIRST try that is late is told: nothing was sent before, so the answer was not taken', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('conflict', 409, 'round_closed', { round: 2, phase: 'reveal' }))
    servePoll(() => poll({ version: 26, round: roundState({ phase: 'reveal', phase_ends_at: at(3) }) }))
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(revealRound())))

    await click(r, 'probe-answer')

    expect(showErrorToast).toHaveBeenCalledWith('Время раунда вышло: ответ не принят.')
  })

  it('the answer that goes again is not drawn beside an empty task: “Sending…” stands alone, and “Retry” does not bring back an empty board', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValue(offline())

    await get(r, 'probe-answer').trigger('click')
    await flushPromises()
    // the first try got no reply: from now on it is a repeat
    expect(has(r, 'battle-resending')).toBe(true)
    expect(text(r, 'resending-note')).toBe('Отправляем ответ…')
    expect(has(r, 'probe')).toBe(false)

    await pass(500)
    await pass(1000)
    await pass(2000)
    expect(has(r, 'battle-send-failed')).toBe(true)
    expect(has(r, 'battle-resending')).toBe(false)

    const again = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockReturnValue(again.promise)
    await click(r, 'retry-answer-btn')

    expect(has(r, 'battle-resending')).toBe(true)
    expect(has(r, 'probe')).toBe(false)
    expect(has(r, 'battle-send-failed')).toBe(false)
    again.resolve(answerReply())
    await flushPromises()
    expect(has(r, 'battle-waiting')).toBe(true)
  })

  it('the first try keeps the (locked) component in view, as before', async () => {
    const r = await render(battle())
    const reply = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReturnValue(reply.promise)

    await get(r, 'probe-answer').trigger('click')

    expect(has(r, 'probe')).toBe(true)
    expect(get(r, 'probe').attributes('data-disabled')).toBe('true')
    expect(has(r, 'sending')).toBe(true)
    expect(has(r, 'battle-resending')).toBe(false)
    reply.resolve(answerReply())
    await flushPromises()
  })

  it('says what failed: the connection, or the server - “not delivered” is not “no connection” when the server answers 503', async () => {
    const r = await render(battle())
    vi.mocked(api.battles.answer).mockRejectedValue(apiError('server', 503, 'x'))

    await get(r, 'probe-answer').trigger('click')
    await pass(500)
    await pass(1000)
    await pass(2000)

    expect(text(r, 'battle-send-failed')).toContain('Ответ не отправился: сервер не отвечает. Повторите, пока идёт время раунда.')
    expect(text(r, 'battle-send-failed')).not.toContain('нет связи')
  })

  it('and for the connection the old words stand, in Uzbek too', async () => {
    setLocale('uz')
    const r = await undeliveredInRound2()

    expect(text(r, 'battle-send-failed')).toContain("Javob yuborilmadi: aloqa yo'q.")
  })
})

describe('BattleView — the question about leaving follows the battle (review Ж3)', () => {
  it('V2: “Surrender and leave?” goes when the match ends meanwhile: the result is not covered by a question that is no longer so', async () => {
    const r = await render(battle())
    await click(r, 'close-btn')
    expect(has(r, 'leave-confirm')).toBe(true)

    servePoll(() => pollOf(finishedBattle('won', 'completed')))
    serveBattle(() => finishedBattle('won', 'completed'))
    await pass(2000)

    expect(text(r, 'result-title')).toBe('Победа')
    expect(has(r, 'leave-confirm')).toBe(false)
    expect(api.battles.surrender).not.toHaveBeenCalled()
  })

  it('the same for a battle that ends without a result (closed)', async () => {
    const r = await render(battle())
    await click(r, 'close-btn')

    servePoll(() => pollOf(closedBattle('both_absent', { version: 40, starts_at: at(-60) })))
    serveBattle(() => closedBattle('both_absent', { version: 40, starts_at: at(-60) }))
    await pass(2000)

    expect(text(r, 'result-title')).toBe('Матч прерван')
    expect(has(r, 'leave-confirm')).toBe(false)
  })

  it('V3: “Leave the room?” goes when the match begins: confirming it would be refused, and the learner would stay in the match', async () => {
    const r = await render(roomBattle({ ready: { me: true, opponent: false } }), { round: answeringRound({ no: 1, deadline_at: at(45) }) })
    await click(r, 'close-btn')
    expect(text(r, 'leave-text')).toBe('Матч отменится, соперник узнает об этом.')

    // the countdown is still a room that can be left: the question stays
    const countdown = countdownBattle({ version: 5 })
    servePoll(() => pollOf(countdown))
    serveBattle(() => countdown)
    await pass(2000)
    expect(get(r, 'battle-view').attributes('data-status')).toBe('countdown')
    expect(has(r, 'leave-confirm')).toBe(true)

    // the match begins: it is not
    const playing = battle({ version: 6, round: roundState({ no: 1, deadline_at: at(45) }), scores: { me: 0, opponent: 0 } })
    servePoll(() => pollOf(playing))
    serveBattle(() => playing)
    await pass(2000)

    expect(get(r, 'battle-view').attributes('data-status')).toBe('playing')
    expect(has(r, 'leave-confirm')).toBe(false)
    expect(api.battles.cancel).not.toHaveBeenCalled()
    expect(showErrorToast).not.toHaveBeenCalled()
    // asking again now asks for the thing that is true: a surrender
    await click(r, 'close-btn')
    expect(get(r, 'leave-confirm').attributes('data-variant')).toBe('match')
    expect(text(r, 'leave-title')).toBe('Сдаться и выйти?')
  })

  it('an invitation that is accepted while the learner is asked: the question stays, now about the room', async () => {
    const r = await render(invitedBattle({ role: 'challenger' }))
    await click(r, 'close-btn')
    expect(text(r, 'leave-text')).toBe('Приглашение будет отозвано.')

    servePoll(() => pollOf(roomBattle()))
    serveBattle(() => roomBattle())
    await pass(2000)

    expect(get(r, 'battle-view').attributes('data-status')).toBe('room')
    expect(has(r, 'leave-confirm')).toBe(true)
    expect(text(r, 'leave-text')).toBe('Матч отменится, соперник узнает об этом.')
  })

  it('the question of a match stays while the match goes on', async () => {
    const r = await render(battle())
    await click(r, 'close-btn')

    await pass(6000)

    expect(has(r, 'leave-confirm')).toBe(true)
    expect(get(r, 'leave-confirm').attributes('data-variant')).toBe('match')
  })
})

describe('BattleView — a recording the server does not have (review Ж5)', () => {
  const ELEMENTS = ['eD1', 'eD2', 'eD3', 'eD4', 'eD5']

  it('a clip of the room that the server answers 503 for is reported (audio-failed): the server voids the match, and the result says why', async () => {
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url.endsWith('eD3') ? Promise.reject(apiError('server', 503, 'practice_audio_unavailable')) : Promise.resolve(clip())))
    vi.mocked(api.battles.audioFailed).mockResolvedValue({ verified: true, battle: closedBattle('voided', { mode: 'dictation', version: 5 }) })

    const r = await render(roomBattle({ mode: 'dictation', audio_preload: CLIPS }))
    await flushPromises()

    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
    expect(api.battles.audioFailed).toHaveBeenCalledWith(15, { request_id: expect.any(String), element_id: ELEMENTS[2] })
    expect(get(r, 'battle-view').attributes('data-status')).toBe('closed')
    expect(text(r, 'result-title')).toBe('Матч не состоялся') // the room: the match did not begin
    expect(text(r, 'result-reason')).toBe('Матч отменён: запись недоступна. Очки не засчитаны.')
    expect(primaries(r)).toHaveLength(1)
  })

  it('the file is there after all (verified: false): the clip is asked for again, once, and the room opens “Ready”', async () => {
    let missing = true
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url.endsWith('eD3') && missing ? Promise.reject(apiError('server', 503, 'practice_audio_unavailable')) : Promise.resolve(clip())))
    vi.mocked(api.battles.audioFailed).mockImplementation(() => {
      missing = false // a hiccup of the server, it is over
      return Promise.resolve({ verified: false, battle: roomBattle({ mode: 'dictation', version: 3, audio_preload: CLIPS }) })
    })

    const r = await render(roomBattle({ mode: 'dictation', audio_preload: CLIPS }))
    await flushPromises()

    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.battles.audioBlob).mock.calls.filter(([url]) => url.endsWith('eD3'))).toHaveLength(2)
    expect(get(r, 'room-audio').attributes('data-state')).toBe('ready')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeUndefined()
    expect(get(r, 'battle-view').attributes('data-status')).toBe('room')
  })

  it('a clip that is still answered 503 after that is not reported again and again: the learner has “Retry”, and the room has its own end', async () => {
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url.endsWith('eD3') ? Promise.reject(apiError('server', 503, 'practice_audio_unavailable')) : Promise.resolve(clip())))
    vi.mocked(api.battles.audioFailed).mockResolvedValue({ verified: false, battle: roomBattle({ mode: 'dictation', version: 3, audio_preload: CLIPS }) })
    const r = await render(roomBattle({ mode: 'dictation', audio_preload: CLIPS }))
    await flushPromises()
    const asked = () => vi.mocked(api.battles.audioBlob).mock.calls.filter(([url]) => url.endsWith('eD3')).length

    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
    expect(asked()).toBe(2) // the first, and the one retry after “the file is there”
    expect(get(r, 'room-audio').attributes('data-state')).toBe('failed')
    expect(get(r, 'ready-btn').attributes('disabled')).toBeDefined()

    await click(r, 'audio-retry')
    await flushPromises()

    expect(asked()).toBe(3)
    expect(api.battles.audioFailed).toHaveBeenCalledTimes(1)
  })

  it('a clip that fails for any other reason (no connection) is not reported: the server has no file to check', async () => {
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url.endsWith('eD3') ? Promise.reject(offline()) : Promise.resolve(clip())))

    const r = await render(roomBattle({ mode: 'dictation', audio_preload: CLIPS }))
    await flushPromises()

    expect(get(r, 'room-audio').attributes('data-state')).toBe('failed')
    expect(api.battles.audioFailed).not.toHaveBeenCalled()
  })

  it('a reload in the countdown loads the clips too: the server lists them there as well', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())

    await render(countdownBattle({ mode: 'dictation', audio_preload: CLIPS }))
    await flushPromises()

    expect(api.battles.audioBlob).toHaveBeenCalledTimes(5)
    for (const url of CLIPS) expect(api.battles.audioBlob).toHaveBeenCalledWith(url)
  })

  it('a match that is on has no list to load (the server sends null), and a battle of another format never had one', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())

    await render(battle({ mode: 'sentence' }))
    await render(roomBattle({ mode: 'pairs' }))
    await flushPromises()

    expect(api.battles.audioBlob).not.toHaveBeenCalled()
  })
})

describe('BattleView — behind Pro (docs/16 §3.2)', () => {
  const PRO = () => apiError('forbidden', 403, 'practice_pro_required')

  it('“Accept” without Pro opens the paywall of the practice - no toast - and the invitation stays on screen', async () => {
    const r = await render(invitedBattle({ role: 'opponent' }))
    vi.mocked(api.battles.accept).mockRejectedValue(PRO())

    await click(r, 'accept-btn')

    expect(get(r, 'paywall').attributes('data-reason')).toBe('practice')
    expect(showErrorToast).not.toHaveBeenCalled()
    expect(has(r, 'battle-room')).toBe(true)
    await click(r, 'paywall-close')
    expect(has(r, 'paywall')).toBe(false)
    expect(has(r, 'accept-btn')).toBe(true)
  })

  it('a battle that cannot be opened without Pro opens the paywall over the closed state; closing it leaves the way to the friends', async () => {
    const r = await render(PRO())

    expect(get(r, 'paywall').attributes('data-reason')).toBe('practice')
    expect(has(r, 'closed')).toBe(true) // under the sheet: not a toast, and not the only thing the learner is given
    await click(r, 'paywall-close')
    expect(has(r, 'paywall')).toBe(false)
    await click(r, 'friends-btn')
    expect(route(r).name).toBe('friends')
  })

  it('a refusal that is not about Pro is not a paywall', async () => {
    const r = await render(apiError('forbidden', 403, 'practice_unavailable'))

    expect(has(r, 'paywall')).toBe(false)
    expect(r.wrapper.text()).toContain('Практика сейчас недоступна.')
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
