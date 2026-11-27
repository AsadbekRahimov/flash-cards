import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupDuelView from '../CupDuelView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { DUEL_POLL_MS } from '@/composables/cupDuelInfo'
import { useCupDuelsStore } from '@/stores/cupDuels'
import { cupDuel, cupInvite } from '@/api/__tests__/cupFixtures'
import type { CupDuel } from '@/api/types'

/**
 * A duel of a cup (docs/33 §5.7, docs/04 A.23d): the states, the poll of the waiting challenger (3 s, one at a time, only while the screen
 * is open and in front, slower after a 429), the way to the match of both players, `expect_rated`, the link of the chat, 403 / 404.
 */

vi.mock('@/api', () => ({
  api: {
    cups: {
      invite: vi.fn(),
      show: vi.fn(),
      duels: { list: vi.fn(), show: vi.fn(), challenge: vi.fn(), accept: vi.fn(), decline: vi.fn(), cancel: vi.fn() },
    },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn(), notificationHaptic: vi.fn() }) }))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' }, limitHit: { type: Object, default: null } },
      emits: ['close', 'review'],
      setup: (props) => () => h('div', { 'data-testid': 'paywall', 'data-reason': props.reason, 'data-limit': String(props.limitHit?.limit ?? '') }),
    }),
  }
})

enableAutoUnmount(afterEach)

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

/** A challenge to me from Aziza. */
const toMe = (over: Partial<CupDuel> = {}): CupDuel =>
  cupDuel({
    role: 'opponent',
    challenger: { participant_id: 1, display_name: 'Aziza', pro_emoji: '🦄' },
    opponent: { participant_id: 2, display_name: 'Me', pro_emoji: null },
    ...over,
  })

const stub = { template: '<div />' }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/duels', name: 'cup-duels', component: stub },
      { path: '/cups/:id(\\d+)/duels/:duelId(\\d+)', name: 'cup-duel', component: stub },
      { path: '/cups/c/:code', name: 'cup-invite', component: stub },
      { path: '/cups/c/:code/duels/:duel', name: 'cup-duel-link', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
    ],
  })
}

async function render(duel: CupDuel | Error = cupDuel(), props: { id: number | null; duelId: number | null; code?: string | null } = { id: 5, duelId: 41 }) {
  if (duel instanceof Error) vi.mocked(api.cups.duels.show).mockRejectedValue(duel)
  else vi.mocked(api.cups.duels.show).mockResolvedValue(duel)
  const router = makeRouter()
  await router.push(props.code ? `/cups/c/${props.code}/duels/${props.duelId ?? 'x'}` : `/cups/${props.id}/duels/${props.duelId}`)
  const wrapper = mount(CupDuelView, { props, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const state = (r: Rendered) => tid(r, 'cup-duel-view').attributes('data-state')
const shown = (r: Rendered) => tid(r, 'cup-duel-view').attributes('data-status')
const route = (r: Rendered) => r.router.currentRoute.value.fullPath
async function click(r: Rendered, id: string) {
  await tid(r, id).trigger('click')
  await flushPromises()
}
async function advance(ms: number) {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

function setVisibility(value: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => value })
  document.dispatchEvent(new Event('visibilitychange'))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  setLocale('ru')
  document.body.innerHTML = ''
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
  vi.setSystemTime(new Date('2026-12-02T10:00:00.000Z'))
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('CupDuelView: open', () => {
  it('my challenge: waiting, the time left by the server, the kind, "Cancel"; a screen reader hears the state', async () => {
    // The device clock runs 2 minutes ahead of the server: the timer still shows the server's 10 minutes.
    vi.setSystemTime(new Date('2026-12-02T10:02:00.000Z'))
    const r = await render()

    expect(api.cups.duels.show).toHaveBeenCalledWith(5, 41)
    expect(state(r)).toBe('ready')
    expect(tid(r, 'duel-title').text()).toBe('Ждём соперника')
    expect(tid(r, 'duel-timer').text()).toContain('10:00')
    expect(tid(r, 'duel-kind').text()).toBe('Зачётная')
    expect(tid(r, 'duel-kind-hint').text()).toContain('Одна зачётная дуэль в день')
    expect(tid(r, 'opponent').text()).toContain('Aziza')
    expect(tid(r, 'opponent').get('[data-testid=pro-emoji]').text()).toBe('🦄')
    expect(has(r, 'cancel-btn')).toBe(true)
    expect(has(r, 'accept-btn')).toBe(false)
    expect(has(r, 'decline-btn')).toBe(false)
    const live = tid(r, 'duel-live')
    expect(live.attributes('aria-live')).toBe('polite')
    expect(live.text()).toBe('Ждём соперника')
  })

  it('a challenge to me: who, the kind, "Accept" and "Decline"', async () => {
    const r = await render(toMe({ rated_offered: false }))

    expect(tid(r, 'duel-title').text()).toBe('Aziza вызывает вас на дуэль')
    expect(tid(r, 'duel-kind').text()).toBe('Товарищеская')
    expect(tid(r, 'duel-kind-hint').text()).toContain('Без очков кубка')
    expect(has(r, 'accept-btn')).toBe(true)
    expect(has(r, 'decline-btn')).toBe(true)
    expect(has(r, 'cancel-btn')).toBe(false)
  })

  it('an open challenge to anybody: "Accept" only', async () => {
    const r = await render(toMe({ role: null, public: true, opponent: null }))

    expect(tid(r, 'duel-title').text()).toBe('Aziza вызывает всех в группе')
    expect(tid(r, 'opponent').text()).toContain('Любой участник группы')
    expect(has(r, 'accept-btn')).toBe(true)
    expect(has(r, 'decline-btn')).toBe(false)
  })

  it('the time of the challenge is up on the server\'s clock: told as expired, no buttons', async () => {
    const r = await render(cupDuel({ expires_at: '2026-12-02T09:59:00.000Z' }))

    expect(shown(r)).toBe('expired')
    expect(tid(r, 'duel-outcome').text()).toBe('Никто не принял вызов вовремя.')
    expect(has(r, 'cancel-btn')).toBe(false)
  })
})

describe('CupDuelView: over', () => {
  it('played, rated: the points of each, mine in the total of the day, the winner', async () => {
    const r = await render(
      cupDuel({ status: 'done', rated: true, points: { challenger: 4, opponent: 3 }, winner_participant_id: 2, battle_id: 77, accepted_day: 3 }),
    )

    expect(tid(r, 'duel-title').text()).toBe('Дуэль сыграна')
    expect(tid(r, 'duel-score').text()).toBe('4 : 3')
    expect(tid(r, 'challenger').attributes('data-winner')).toBe('true')
    expect(tid(r, 'duel-outcome').text()).toBe('Очки за верные ответы пошли в зачёт дня.')
    expect(tid(r, 'duel-my-points').text()).toBe('Вам в зачёт дня: 4 очка')
    // I won (the challenger): the result of the kit - the mascot with the cup and the stamp of my points, one on the screen.
    expect(tid(r, 'mascot-art').attributes('data-pose')).toBe('trophy')
    expect(r.wrapper.findAll('[data-testid=stamp]')).toHaveLength(1)
    expect(tid(r, 'stamp').attributes('aria-label')).toBe('4 очка')
    // A duel over is not asked about again, and does not lead to its match.
    await advance(10_000)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(1)
    expect(route(r)).toBe('/cups/5/duels/41')
  })

  it('played, friendly: no points of the cup', async () => {
    const r = await render(toMe({ status: 'done', rated: false, points: { challenger: 2, opponent: 5 }, winner_participant_id: 2 }))

    expect(tid(r, 'duel-outcome').text()).toBe('Товарищеский матч: без очков кубка.')
    expect(has(r, 'duel-my-points')).toBe(false)
    // I won (Aziza challenged me): the stamp has my points, a friendly duel too.
    expect(tid(r, 'stamp').attributes('aria-label')).toBe('5 очков')
  })

  it('played and lost: told quietly - no mascot, no stamp', async () => {
    const r = await render(toMe({ status: 'done', rated: true, points: { challenger: 5, opponent: 2 }, winner_participant_id: 1 }))

    expect(tid(r, 'duel-score').text()).toBe('5 : 2')
    expect(has(r, 'mascot-art')).toBe(false)
    expect(has(r, 'stamp')).toBe(false)
  })

  it('my challenge waits for an answer: the mascot greets on the hero, no stamp', async () => {
    const r = await render(cupDuel())

    expect(tid(r, 'mascot-art').attributes('data-pose')).toBe('hello')
    expect(has(r, 'stamp')).toBe(false)
    expect(tid(r, 'duel-title').classes()).toContain('t-display-m')
  })

  it.each([
    ['done after the cup closed', { status: 'done', rated: true, end_reason: 'cup_closed', points: { challenger: 4, opponent: 3 } }, 'Кубок уже завершился — очки не начислены.'],
    ['void: nobody came in time (late)', { status: 'void', end_reason: 'no_answers', rated: true }, 'Матч не состоялся: в комнату не зашли вовремя, ответов не было. Можно вызвать снова.'],
    ['declined', { status: 'declined', end_reason: 'declined' }, 'Соперник отклонил вызов.'],
    ['canceled by me', { status: 'canceled', end_reason: 'challenger' }, 'Вы отменили вызов.'],
    ['canceled: a player left', { status: 'canceled', end_reason: 'left' }, 'Вызов отменён: игрок вышел из кубка.'],
    ['expired', { status: 'expired', end_reason: 'expired' }, 'Никто не принял вызов вовремя.'],
    ['expired with the day of the cup', { status: 'expired', end_reason: 'cup_over' }, 'День кубка закончился — вызов закрыт.'],
    ['canceled: the cup closed', { status: 'canceled', end_reason: 'cup_closed' }, 'Кубок завершился — вызов закрыт.'],
  ] as const)('%s', async (_name, over, text) => {
    const r = await render(cupDuel(over as Partial<CupDuel>))

    expect(tid(r, 'duel-outcome').text()).toBe(text)
    expect(has(r, 'duel-actions')).toBe(false)
    if (over.status === 'done') expect(has(r, 'duel-my-points')).toBe(false)
    else expect(tid(r, 'duel-title').text()).toBe('Дуэль не состоялась')
  })
})

describe('CupDuelView: the poll of an open duel', () => {
  it('every 3 s while open; accepted - the challenger goes to the match at once', async () => {
    const r = await render()
    expect(api.cups.duels.show).toHaveBeenCalledTimes(1)

    await advance(DUEL_POLL_MS)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
    await advance(DUEL_POLL_MS)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(3)

    vi.mocked(api.cups.duels.show).mockResolvedValue(cupDuel({ status: 'accepted', rated: true, battle_id: 77, accepted_day: 3 }))
    await advance(DUEL_POLL_MS)

    expect(route(r)).toBe('/battles/77')
  })

  it('one request at a time: a slow reply is not doubled', async () => {
    await render()
    vi.mocked(api.cups.duels.show).mockReturnValue(new Promise(() => {}))

    await advance(DUEL_POLL_MS * 4)

    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
  })

  it('a hidden tab stops it; back in front - a reading at once', async () => {
    await render()

    setVisibility('hidden')
    await advance(DUEL_POLL_MS * 3)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(1)

    setVisibility('visible')
    await flushPromises()
    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
  })

  it('leaving the screen stops it', async () => {
    const r = await render()

    r.wrapper.unmount()
    await advance(DUEL_POLL_MS * 3)

    expect(api.cups.duels.show).toHaveBeenCalledTimes(1)
  })

  it('429 slows it down; the first success brings the beat back', async () => {
    const r = await render()
    vi.mocked(api.cups.duels.show).mockRejectedValueOnce(apiError('rate_limited', 429, 'too_many_requests'))

    await advance(DUEL_POLL_MS)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
    // The duel stays on screen.
    expect(tid(r, 'duel-title').text()).toBe('Ждём соперника')

    await advance(DUEL_POLL_MS)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
    await advance(DUEL_POLL_MS)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(3)

    await advance(DUEL_POLL_MS)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(4)
  })

  it('stops once the challenge is over (declined), and tells it', async () => {
    const r = await render()
    vi.mocked(api.cups.duels.show).mockResolvedValue(cupDuel({ status: 'declined', end_reason: 'declined' }))

    await advance(DUEL_POLL_MS)
    expect(tid(r, 'duel-outcome').text()).toBe('Соперник отклонил вызов.')
    await advance(DUEL_POLL_MS * 3)

    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
  })

  it('stops a while after the time of the challenge is up', async () => {
    await render(cupDuel({ expires_at: '2026-12-02T10:00:06.000Z' }))

    await advance(90_000)
    const calls = vi.mocked(api.cups.duels.show).mock.calls.length
    await advance(30_000)

    expect(vi.mocked(api.cups.duels.show).mock.calls.length).toBe(calls)
  })
})

describe('CupDuelView: accept', () => {
  it('the one who accepts goes to the match at once, with the kind shown (`expect_rated`)', async () => {
    vi.mocked(api.cups.duels.accept).mockResolvedValue(toMe({ status: 'accepted', rated: true, battle_id: 77, accepted_day: 3 }))
    const r = await render(toMe())

    await click(r, 'accept-btn')

    expect(api.cups.duels.accept).toHaveBeenCalledWith(5, 41, true)
    expect(route(r)).toBe('/battles/77')
  })

  it('a double tap is one request; the button waits (aria-busy)', async () => {
    let resolve!: (duel: CupDuel) => void
    vi.mocked(api.cups.duels.accept).mockReturnValue(new Promise((yes) => (resolve = yes)))
    const r = await render(toMe())

    await tid(r, 'accept-btn').trigger('click')
    await tid(r, 'accept-btn').trigger('click')
    expect(tid(r, 'accept-btn').attributes('aria-busy')).toBe('true')
    expect(tid(r, 'accept-btn').attributes('disabled')).toBeDefined()
    resolve(toMe({ status: 'accepted', rated: true, battle_id: 77 }))
    await flushPromises()

    expect(api.cups.duels.accept).toHaveBeenCalledTimes(1)
    expect(route(r)).toBe('/battles/77')
  })

  /**
   * A fake of the server's rule (docs/33 §5.7): the acceptance compares `expect_rated` with the kind decided by the slots of today and
   * refuses a difference with 409 `{rated}`; GET answers the duel as the server has it - `rated_offered` is what the challenger offered
   * and never changes, `rated_for_me` (absent from an older server) is the kind for this viewer.
   */
  function fakeServer(over: { offered: boolean; forMe: boolean; newServer: boolean }) {
    const duel = (extra: Partial<CupDuel> = {}) =>
      toMe(over.newServer ? { rated_offered: over.offered, rated_for_me: over.forMe, ...extra } : { rated_offered: over.offered, rated_for_me: undefined, ...extra })
    vi.mocked(api.cups.duels.show).mockImplementation(async () => duel())
    vi.mocked(api.cups.duels.accept).mockImplementation(async (_cup: number, _id: number, expectRated: boolean) => {
      if (expectRated !== over.forMe) throw apiError('conflict', 409, 'cup_duel_rating_changed', { rated: over.forMe })
      return duel({ status: 'accepted', rated: over.forMe, battle_id: 78 })
    })
    return duel
  }

  it('an open rated challenge, I have played my rated duel today: shown friendly and accepted at the first "Accept" (rated_for_me)', async () => {
    const duel = fakeServer({ offered: true, forMe: false, newServer: true })
    const r = await render(duel())

    expect(tid(r, 'duel-kind').text()).toBe('Товарищеская')
    await click(r, 'accept-btn')

    expect(api.cups.duels.accept).toHaveBeenCalledTimes(1)
    expect(api.cups.duels.accept).toHaveBeenLastCalledWith(5, 41, false)
    expect(route(r)).toBe('/battles/78')
  })

  it('409 cup_duel_rating_changed (the kind changed after the read): "the conditions changed", the new kind, the next "Accept" sends it', async () => {
    // Read while my slot was free; meanwhile I played my rated duel elsewhere - the server's kind for me is friendly now.
    const duel = fakeServer({ offered: true, forMe: false, newServer: true })
    const r = await render(duel({ rated_for_me: true }))
    expect(tid(r, 'duel-kind').text()).toBe('Зачётная')
    // From now on the server reads the duel as it is: friendly for me (a fresh `rated_for_me` is what the screen shows).
    vi.mocked(api.cups.duels.show).mockImplementation(async () => duel())

    await click(r, 'accept-btn')

    expect(tid(r, 'duel-notice').text()).toBe('Условия вызова изменились: теперь это товарищеский матч без очков кубка. Проверьте и примите ещё раз.')
    expect(tid(r, 'duel-kind').text()).toBe('Товарищеская')
    expect(api.cups.duels.show).toHaveBeenCalledTimes(2)
    expect(route(r)).toBe('/cups/5/duels/41')

    await click(r, 'accept-btn')
    expect(api.cups.duels.accept).toHaveBeenCalledTimes(2)
    expect(api.cups.duels.accept).toHaveBeenLastCalledWith(5, 41, false)
    expect(route(r)).toBe('/battles/78')
  })

  it('an older server (no rated_for_me): the kind of the 409 is kept through the reads and the poll, so the next "Accept" is not refused again', async () => {
    const duel = fakeServer({ offered: true, forMe: false, newServer: false })
    const r = await render(duel())
    expect(tid(r, 'duel-kind').text()).toBe('Зачётная')

    await click(r, 'accept-btn')
    expect(tid(r, 'duel-kind').text()).toBe('Товарищеская')
    // The poll brings back the duel as the old server has it - `rated_offered: true` - and the kind stays the one of the 409.
    await advance(DUEL_POLL_MS * 2)
    expect(vi.mocked(api.cups.duels.show).mock.calls.length).toBeGreaterThan(2)
    expect(tid(r, 'duel-kind').text()).toBe('Товарищеская')

    await click(r, 'accept-btn')
    expect(api.cups.duels.accept).toHaveBeenCalledTimes(2)
    expect(api.cups.duels.accept).toHaveBeenLastCalledWith(5, 41, false)
    expect(route(r)).toBe('/battles/78')
  })

  it('409 cup_duel_closed: read again, the screen tells how it ended', async () => {
    vi.mocked(api.cups.duels.accept).mockRejectedValue(apiError('conflict', 409, 'cup_duel_closed', { status: 'canceled', reason: 'challenger' }))
    const r = await render(toMe())
    vi.mocked(api.cups.duels.show).mockResolvedValue(toMe({ status: 'canceled', end_reason: 'challenger' }))

    await click(r, 'accept-btn')

    expect(tid(r, 'duel-error').text()).toBe('Вызов уже закрыт.')
    expect(tid(r, 'duel-outcome').text()).toBe('Вызов отменён.')
  })

  it('409 battle_busy: my match is in the way, a link to it', async () => {
    vi.mocked(api.cups.duels.accept).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 12 }))
    const r = await render(toMe())

    await click(r, 'accept-btn')
    expect(tid(r, 'duel-error').text()).toContain('У вас уже идёт матч')
    await click(r, 'open-busy')

    expect(route(r)).toBe('/battles/12')
  })

  it('403 practice_limit_reached: the paywall of the practice', async () => {
    vi.mocked(api.cups.duels.accept).mockRejectedValue(
      apiError('forbidden', 403, 'practice_limit_reached', { kind: 'battles', limit: 1, used: 1, resets_at: '2026-12-02T19:00:00.000Z' }),
    )
    const r = await render(toMe({ rated_offered: false }))

    await click(r, 'accept-btn')

    expect(tid(r, 'paywall').attributes('data-reason')).toBe('practice_limit')
    expect(tid(r, 'paywall').attributes('data-limit')).toBe('1')
  })

  it('opened when accepted already (the message of the bot): straight to the match', async () => {
    const r = await render(cupDuel({ status: 'accepted', rated: true, battle_id: 77 }))

    expect(route(r)).toBe('/battles/77')
  })
})

describe('CupDuelView: decline and cancel', () => {
  it('"Decline": the challenge is declined', async () => {
    vi.mocked(api.cups.duels.decline).mockResolvedValue(toMe({ status: 'declined', end_reason: 'declined' }))
    const r = await render(toMe())

    await click(r, 'decline-btn')

    expect(api.cups.duels.decline).toHaveBeenCalledWith(5, 41)
    expect(tid(r, 'duel-outcome').text()).toBe('Вы отклонили вызов.')
  })

  it('"Cancel": my challenge is withdrawn, the poll stops', async () => {
    vi.mocked(api.cups.duels.cancel).mockResolvedValue(cupDuel({ status: 'canceled', end_reason: 'challenger' }))
    const r = await render()

    await click(r, 'cancel-btn')
    expect(api.cups.duels.cancel).toHaveBeenCalledWith(5, 41)
    expect(tid(r, 'duel-outcome').text()).toBe('Вы отменили вызов.')

    await advance(DUEL_POLL_MS * 3)
    expect(api.cups.duels.show).toHaveBeenCalledTimes(1)
  })
})

describe('CupDuelView: refusals', () => {
  it('404 cup_duel_not_found: back to the cup with a word', async () => {
    const r = await render(apiError('not_found', 404, 'cup_duel_not_found'))

    expect(showErrorToast).toHaveBeenCalledWith('Вызов не найден.')
    expect(route(r)).toBe('/cups/5')
  })

  it('403 cup_not_member: "The cup is not available"', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_not_member'))

    expect(state(r)).toBe('unavailable')
    expect(tid(r, 'unavailable').text()).toContain('Кубок недоступен')
  })

  it('403 cup_unavailable: the cups are off', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_unavailable'))

    expect(state(r)).toBe('off')
  })

  it('409 cup_duel_disabled: no duels, remembered for the session (not asked again)', async () => {
    const r = await render(apiError('conflict', 409, 'cup_duel_disabled'))

    expect(state(r)).toBe('disabled')
    expect(tid(r, 'duels-off').text()).toContain('Дуэли кубка сейчас недоступны.')
    expect(useCupDuelsStore().off).toBe(true)

    r.wrapper.unmount()
    const again = await render()
    expect(state(again)).toBe('disabled')
    expect(api.cups.duels.show).toHaveBeenCalledTimes(1)
  })

  it('a lost connection on opening: "Retry"', async () => {
    const r = await render(apiError('network', 0, 'network'))

    expect(state(r)).toBe('error')
    vi.mocked(api.cups.duels.show).mockResolvedValue(cupDuel())
    await click(r, 'retry-btn')
    expect(state(r)).toBe('ready')
  })
})

describe('CupDuelView: the link of the chat', () => {
  const code = 'AbCdEfGhIjKlMnOpQrSt_-'

  it('the cup by its code, then the duel', async () => {
    vi.mocked(api.cups.invite).mockResolvedValue(cupInvite({ id: 5, me: { joined: true, participant_id: 2, status: 'active', display_name: 'Me', left: false } }))
    const r = await render(toMe({ role: null, public: true, opponent: null }), { id: null, duelId: 41, code })

    expect(api.cups.invite).toHaveBeenCalledWith(code)
    expect(api.cups.duels.show).toHaveBeenCalledWith(5, 41)
    expect(has(r, 'accept-btn')).toBe(true)
  })

  it('a member of the chat who has not joined: the cup is not available to him yet, the way to the invitation', async () => {
    vi.mocked(api.cups.invite).mockResolvedValue(cupInvite())
    const r = await render(cupDuel(), { id: null, duelId: 41, code })

    expect(api.cups.duels.show).not.toHaveBeenCalled()
    expect(state(r)).toBe('unavailable')
    expect(tid(r, 'unavailable').text()).toContain('Дуэли — для участников кубка')
    expect(tid(r, 'to-invite').attributes('href')).toBe(`/cups/c/${code}`)
  })

  it('a stranger\'s code (403): "The cup is not available"', async () => {
    vi.mocked(api.cups.invite).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))
    const r = await render(cupDuel(), { id: null, duelId: 41, code })

    expect(state(r)).toBe('unavailable')
    expect(api.cups.duels.show).not.toHaveBeenCalled()
  })

  it('a duel id that is not a number: missing - back to the cup', async () => {
    vi.mocked(api.cups.invite).mockResolvedValue(cupInvite({ id: 5, me: { joined: true, participant_id: 2, status: 'active', display_name: 'Me', left: false } }))
    const r = await render(cupDuel(), { id: null, duelId: null, code })

    expect(api.cups.duels.show).not.toHaveBeenCalled()
    expect(route(r)).toBe('/cups/5')
  })
})

describe('CupDuelView: uz', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render(toMe())

    expect(tid(r, 'duel-title').text()).toBe('Aziza sizni duelga chaqirmoqda')
    expect(tid(r, 'duel-kind').text()).toBe('Hisobli')
    expect(tid(r, 'accept-btn').text()).toBe('Qabul qilish')
    expect(tid(r, 'decline-btn').text()).toBe('Rad etish')
  })
})
