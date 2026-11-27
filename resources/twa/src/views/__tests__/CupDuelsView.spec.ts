import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupDuelsView from '../CupDuelsView.vue'
import { api } from '@/api'
import { useBackButton } from '@/composables/useBackButton'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupDuelsStore } from '@/stores/cupDuels'
import { useTodayStore } from '@/stores/today'
import { cupBoard, cupDuel, duelAvailability, duelList } from '@/api/__tests__/cupFixtures'
import type { CupDuel, CupDuelListResponse, TodayResponse } from '@/api/types'

/** The list of the duels of a cup (docs/04 A.23d `GET /cups/{id}/duels`, one request): the four groups and their actions. */

vi.mock('@/api', () => ({
  api: {
    cups: {
      show: vi.fn(),
      duels: { list: vi.fn(), show: vi.fn(), challenge: vi.fn(), accept: vi.fn(), decline: vi.fn(), cancel: vi.fn() },
    },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn(), notificationHaptic: vi.fn() }) }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' }, limitHit: { type: Object, default: null } },
      setup: (props) => () => h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }),
    }),
  }
})

enableAutoUnmount(afterEach)

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const aziza = { participant_id: 1, display_name: 'Aziza', pro_emoji: '🦄' }
const bobur = { participant_id: 3, display_name: 'Bobur', pro_emoji: null }
const me = { participant_id: 2, display_name: 'Me', pro_emoji: null }

const incoming = (over: Partial<CupDuel> = {}) => cupDuel({ id: 51, role: 'opponent', challenger: aziza, opponent: me, ...over })
const openToAll = (over: Partial<CupDuel> = {}) => cupDuel({ id: 52, role: null, public: true, challenger: bobur, opponent: null, rated_offered: false, ...over })
const mine = (over: Partial<CupDuel> = {}) => cupDuel({ id: 53, role: 'challenger', challenger: me, opponent: null, public: true, ...over })

const full = (): CupDuelListResponse =>
  duelList({
    incoming: [incoming()],
    open: [openToAll()],
    mine: mine(),
    today: [
      cupDuel({ id: 54, status: 'done', rated: true, role: 'opponent', challenger: bobur, opponent: me, points: { challenger: 2, opponent: 4 }, winner_participant_id: 2 }),
      cupDuel({ id: 55, status: 'declined', end_reason: 'declined', role: 'challenger', challenger: me, opponent: aziza }),
    ],
    availability: duelAvailability({ can_challenge: false, open_duel_id: 53 }),
  })

async function render(list: CupDuelListResponse | Error = full()) {
  if (list instanceof Error) vi.mocked(api.cups.duels.list).mockRejectedValue(list)
  else vi.mocked(api.cups.duels.list).mockResolvedValue(list)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/duels', name: 'cup-duels', component: stub },
      { path: '/cups/:id(\\d+)/duel', name: 'cup-duel-new', component: stub },
      { path: '/cups/:id(\\d+)/duels/:duelId(\\d+)', name: 'cup-duel', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
    ],
  })
  await router.push('/cups/5/duels')
  const wrapper = mount(CupDuelsView, { props: { id: 5 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const state = (r: Rendered) => tid(r, 'cup-duels-view').attributes('data-state')
const route = (r: Rendered) => r.router.currentRoute.value.fullPath
async function click(r: Rendered, selector: string) {
  await r.wrapper.get(selector).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  setLocale('ru')
  document.body.innerHTML = ''
  useTodayStore().data = { timezone: 'Asia/Tashkent' } as TodayResponse
})

describe('CupDuelsView: the groups', () => {
  it('one request: challenges to me, open ones, mine, my duels of today; the names with the emoji of Pro', async () => {
    const r = await render()

    expect(api.cups.duels.list).toHaveBeenCalledTimes(1)
    expect(api.cups.duels.list).toHaveBeenCalledWith(5)
    expect(state(r)).toBe('ready')

    const toMe = tid(r, 'incoming-row')
    expect(toMe.text()).toContain('Aziza')
    expect(toMe.get('[data-testid=pro-emoji]').text()).toBe('🦄')
    expect(toMe.get('[data-testid=duel-kind]').text()).toBe('Зачётная')
    expect(toMe.text()).toContain('до 15:10')
    expect(toMe.find('[data-testid=accept-btn]').exists()).toBe(true)
    expect(toMe.find('[data-testid=decline-btn]').exists()).toBe(true)

    const open = tid(r, 'open-row')
    expect(open.text()).toContain('Bobur')
    expect(open.get('[data-testid=duel-kind]').text()).toBe('Товарищеская')
    expect(open.find('[data-testid=decline-btn]').exists()).toBe(false)

    expect(tid(r, 'mine-row').text()).toContain('Любой участник группы')
    expect(tid(r, 'mine-row').text()).toContain('Ждём соперника')
    expect(tid(r, 'mine-open').attributes('href')).toBe('/cups/5/duels/53')

    const today = r.wrapper.findAll('[data-testid=today-row]')
    expect(today).toHaveLength(2)
    // My points first.
    expect(today[0]?.get('[data-testid=today-score]').text()).toBe('4 : 2')
    expect(today[0]?.text()).toContain('Bobur')
    expect(today[0]?.get('[data-testid=today-outcome]').text()).toBe('Очки за верные ответы пошли в зачёт дня.')
    expect(today[1]?.get('[data-testid=today-outcome]').text()).toBe('Соперник отклонил вызов.')
    expect(today[1]?.attributes('href')).toBe('/cups/5/duels/55')

    // My open challenge stands for "Challenge".
    expect(has(r, 'challenge-btn')).toBe(false)
  })

  it('nothing yet: "Challenge" and how many challenges are left today (from the server)', async () => {
    const r = await render(duelList({ availability: duelAvailability({ challenges_left: 3 }) }))

    expect(tid(r, 'duels-empty').text()).toBe('Пока вызовов нет.')
    expect(tid(r, 'challenge-btn').attributes('href')).toBe('/cups/5/duel')
    expect(tid(r, 'challenges-left').text()).toBe('Сегодня можно отправить ещё 3 вызова')
  })

  it('draws no "To the cup" of its own: back to the cup is the system "Back" of Telegram (kit TELEGRAM.md)', async () => {
    const r = await render()

    expect(r.wrapper.find('[data-testid=to-cup]').exists()).toBe(false)
    expect(vi.mocked(useBackButton)).toHaveBeenCalledWith({ name: 'cup', params: { id: 5 } })
  })

  it('no challenge today: why (the state of the cup from the table of the cup screen)', async () => {
    useCupBoardStore().board = cupBoard({ cup: { ...cupBoard().cup, paused: 'blocked' } })
    const r = await render(duelList({ availability: duelAvailability({ can_challenge: false }) }))

    expect(has(r, 'challenge-btn')).toBe(false)
    expect(tid(r, 'cannot-challenge').text()).toBe('Кубок на паузе — вызывать на дуэль сейчас нельзя.')
  })
})

describe('CupDuelsView: the actions', () => {
  it('"Accept" a challenge to me: with its kind, straight to the match', async () => {
    vi.mocked(api.cups.duels.accept).mockResolvedValue(incoming({ status: 'accepted', rated: true, battle_id: 77 }))
    const r = await render()

    await click(r, '[data-testid=incoming-row] [data-testid=accept-btn]')

    expect(api.cups.duels.accept).toHaveBeenCalledWith(5, 51, true)
    expect(route(r)).toBe('/battles/77')
  })

  it('"Accept" an open one: a double tap is one request', async () => {
    let resolve!: (duel: CupDuel) => void
    vi.mocked(api.cups.duels.accept).mockReturnValue(new Promise((yes) => (resolve = yes)))
    const r = await render()

    const button = r.wrapper.get('[data-testid=open-row] [data-testid=accept-btn]')
    await button.trigger('click')
    await button.trigger('click')
    expect(button.attributes('aria-busy')).toBe('true')
    resolve(openToAll({ status: 'accepted', rated: false, battle_id: 78 }))
    await flushPromises()

    expect(api.cups.duels.accept).toHaveBeenCalledTimes(1)
    expect(api.cups.duels.accept).toHaveBeenCalledWith(5, 52, false)
    expect(route(r)).toBe('/battles/78')
  })

  it('409 cup_duel_rating_changed: "the conditions changed", the list read again', async () => {
    vi.mocked(api.cups.duels.accept).mockRejectedValue(apiError('conflict', 409, 'cup_duel_rating_changed', { rated: false }))
    const r = await render()
    // As the server answers: the offer of the challenger is unchanged, the kind for me is friendly now.
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList({ incoming: [incoming({ rated_offered: true, rated_for_me: false })] }))

    await click(r, '[data-testid=incoming-row] [data-testid=accept-btn]')

    expect(tid(r, 'list-notice').text()).toContain('теперь это товарищеский матч')
    expect(api.cups.duels.list).toHaveBeenCalledTimes(2)
    expect(tid(r, 'incoming-row').get('[data-testid=duel-kind]').text()).toBe('Товарищеская')
    expect(route(r)).toBe('/cups/5/duels')

    vi.mocked(api.cups.duels.accept).mockResolvedValue(incoming({ status: 'accepted', rated: false, battle_id: 79 }))
    await click(r, '[data-testid=incoming-row] [data-testid=accept-btn]')
    expect(api.cups.duels.accept).toHaveBeenLastCalledWith(5, 51, false)
  })

  it('an open rated challenge, I have played my rated duel today: the row is friendly and "Accept" sends it at once (rated_for_me)', async () => {
    vi.mocked(api.cups.duels.accept).mockResolvedValue(openToAll({ status: 'accepted', rated: false, battle_id: 80 }))
    const r = await render(duelList({ open: [openToAll({ rated_offered: true, rated_for_me: false })] }))

    expect(tid(r, 'open-row').get('[data-testid=duel-kind]').text()).toBe('Товарищеская')
    await click(r, '[data-testid=open-row] [data-testid=accept-btn]')

    expect(api.cups.duels.accept).toHaveBeenCalledTimes(1)
    expect(api.cups.duels.accept).toHaveBeenLastCalledWith(5, 52, false)
  })

  it('an older server (no rated_for_me): the kind of the 409 is kept for the row read again', async () => {
    vi.mocked(api.cups.duels.accept).mockRejectedValueOnce(apiError('conflict', 409, 'cup_duel_rating_changed', { rated: false }))
    const old = incoming({ rated_offered: true, rated_for_me: undefined })
    const r = await render(duelList({ incoming: [old] }))
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList({ incoming: [old] }))

    await click(r, '[data-testid=incoming-row] [data-testid=accept-btn]')
    expect(tid(r, 'incoming-row').get('[data-testid=duel-kind]').text()).toBe('Товарищеская')

    vi.mocked(api.cups.duels.accept).mockResolvedValue(incoming({ status: 'accepted', rated: false, battle_id: 81 }))
    await click(r, '[data-testid=incoming-row] [data-testid=accept-btn]')
    expect(api.cups.duels.accept).toHaveBeenLastCalledWith(5, 51, false)
  })

  it('403 practice_limit_reached on accepting a friendly one: the paywall', async () => {
    vi.mocked(api.cups.duels.accept).mockRejectedValue(
      apiError('forbidden', 403, 'practice_limit_reached', { kind: 'battles', limit: 1, used: 1, resets_at: '2026-12-02T19:00:00.000Z' }),
    )
    const r = await render()

    await click(r, '[data-testid=open-row] [data-testid=accept-btn]')

    expect(tid(r, 'paywall').attributes('data-reason')).toBe('practice_limit')
  })

  it('the paywall of the screen of a new challenge does not come back on the list ("Back"); one of the list still shows', async () => {
    useCupDuelsStore().limitHit = { kind: 'battles', limit: 1, used: 1, resets_at: '2026-12-02T19:00:00.000Z' }
    vi.mocked(api.cups.duels.accept).mockRejectedValue(
      apiError('forbidden', 403, 'practice_limit_reached', { kind: 'battles', limit: 1, used: 1, resets_at: '2026-12-02T19:00:00.000Z' }),
    )
    const r = await render()
    expect(has(r, 'paywall')).toBe(false)

    await click(r, '[data-testid=open-row] [data-testid=accept-btn]')

    expect(has(r, 'paywall')).toBe(true)
  })

  it('"Decline": the list is read again', async () => {
    vi.mocked(api.cups.duels.decline).mockResolvedValue(incoming({ status: 'declined', end_reason: 'declined' }))
    const r = await render()
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList({ today: [incoming({ status: 'declined', end_reason: 'declined' })] }))

    await click(r, '[data-testid=incoming-row] [data-testid=decline-btn]')

    expect(api.cups.duels.decline).toHaveBeenCalledWith(5, 51)
    expect(has(r, 'incoming-row')).toBe(false)
    expect(tid(r, 'today-outcome').text()).toBe('Вы отклонили вызов.')
  })

  it('"Cancel" my challenge: "Challenge" comes back', async () => {
    vi.mocked(api.cups.duels.cancel).mockResolvedValue(mine({ status: 'canceled', end_reason: 'challenger' }))
    const r = await render()
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList())

    await click(r, '[data-testid=cancel-btn]')

    expect(api.cups.duels.cancel).toHaveBeenCalledWith(5, 53)
    expect(has(r, 'mine')).toBe(false)
    expect(has(r, 'challenge-btn')).toBe(true)
  })

  it('a challenge closed meanwhile: its words, the list read again', async () => {
    vi.mocked(api.cups.duels.decline).mockRejectedValue(apiError('conflict', 409, 'cup_duel_closed', { status: 'expired', reason: 'expired' }))
    const r = await render()

    await click(r, '[data-testid=incoming-row] [data-testid=decline-btn]')

    expect(tid(r, 'list-error').text()).toBe('Вызов уже закрыт.')
    expect(api.cups.duels.list).toHaveBeenCalledTimes(2)
  })
})

describe('CupDuelsView: refusals', () => {
  it('409 cup_duel_disabled: no duels, and not asked again in the session', async () => {
    const r = await render(apiError('conflict', 409, 'cup_duel_disabled'))

    expect(state(r)).toBe('disabled')
    expect(useCupDuelsStore().off).toBe(true)
    r.wrapper.unmount()

    const again = await render()
    expect(state(again)).toBe('disabled')
    expect(api.cups.duels.list).toHaveBeenCalledTimes(1)
  })

  it('403 cup_not_member: "The cup is not available"', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_not_member'))

    expect(state(r)).toBe('unavailable')
  })

  it('a lost connection: "Retry"', async () => {
    const r = await render(apiError('network', 0, 'network'))

    expect(state(r)).toBe('error')
    vi.mocked(api.cups.duels.list).mockResolvedValue(duelList())
    await click(r, '[data-testid=retry-btn]')
    expect(state(r)).toBe('ready')
  })
})

describe('CupDuelsView: uz', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()

    expect(r.wrapper.text()).toContain('Menga chaqiruvlar')
    expect(tid(r, 'incoming-row').get('[data-testid=accept-btn]').text()).toBe('Qabul qilish')
    expect(tid(r, 'incoming-row').text()).toContain('15:10 gacha')
  })
})
