import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupDuelNewView from '../CupDuelNewView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { useCupBoardStore } from '@/stores/cupBoard'
import { useCupDuelsStore } from '@/stores/cupDuels'
import { cupBoard, cupDuel, duelAvailability } from '@/api/__tests__/cupFixtures'
import type { CupBoardResponse, CupDuel, CupDuelAvailability, CupDuelChallengeRequest } from '@/api/types'

/**
 * A challenge to a duel (docs/33 §7.1, docs/04 A.23d `POST /cups/{id}/duels`): whom (a participant still in the group, never me, or
 * anybody in the group), the kind the server will offer, the confirmation, one `request_id` per attempt, the paywall of the limit.
 */

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn() },
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
      setup: (props) => () => h('div', { 'data-testid': 'paywall', 'data-reason': props.reason, 'data-limit': String(props.limitHit?.limit ?? '') }),
    }),
  }
})

enableAutoUnmount(afterEach)

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const withDuel = (duel: CupDuelAvailability | null | undefined = duelAvailability()): CupBoardResponse => {
  const board = cupBoard()
  return cupBoard({ me: { ...board.me!, duel } })
}

async function render(board: CupBoardResponse = withDuel()) {
  vi.mocked(api.cups.show).mockResolvedValue(board)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/duel', name: 'cup-duel-new', component: stub },
      { path: '/cups/:id(\\d+)/duels/:duelId(\\d+)', name: 'cup-duel', component: stub },
    ],
  })
  await router.push('/cups/5/duel')
  const wrapper = mount(CupDuelNewView, { props: { id: 5 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const state = (r: Rendered) => tid(r, 'cup-duel-new-view').attributes('data-state')
const route = (r: Rendered) => r.router.currentRoute.value.fullPath
/** The sheet is teleported to the body. */
const sheet = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
async function click(r: Rendered, id: string) {
  await tid(r, id).trigger('click')
  await flushPromises()
}
async function press(id: string) {
  sheet(id)?.click()
  await flushPromises()
}
const sent = (call = 0) => vi.mocked(api.cups.duels.challenge).mock.calls[call]?.[1] as CupDuelChallengeRequest

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  vi.mocked(api.me.get).mockReturnValue(new Promise(() => {}))
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('CupDuelNewView: whom', () => {
  it('the participants still in the group, never me, and "anybody in the group"', async () => {
    const r = await render()

    expect(state(r)).toBe('ready')
    expect(has(r, 'opponent-public')).toBe(true)
    expect(has(r, 'opponent-1')).toBe(true)
    expect(has(r, 'opponent-3')).toBe(true)
    // Me, and Dilnoza who left the group.
    expect(has(r, 'opponent-2')).toBe(false)
    expect(has(r, 'opponent-4')).toBe(false)
    expect(tid(r, 'opponent-1').get('[data-testid=pro-emoji]').text()).toBe('🦄')
    expect(tid(r, 'next-btn').attributes('disabled')).toBeDefined()
  })

  it('the table the cup screen has just read is not read again', async () => {
    useCupBoardStore().board = withDuel()
    useCupBoardStore().cupId = 5
    useCupBoardStore().loadedAt = Date.now()
    await render()

    expect(api.cups.show).not.toHaveBeenCalled()
  })
})

describe('CupDuelNewView: the kind', () => {
  it('rated while my rated duel of the day is not played; a personal one may turn friendly', async () => {
    const r = await render()
    await click(r, 'opponent-1')

    expect(tid(r, 'duel-kind').text()).toBe('Зачётная')
    expect(tid(r, 'duel-kind-hint').text()).toContain('Очко кубка за каждый свой верный ответ')
    expect(tid(r, 'rated-note').text()).toContain('товарищеский матч')
  })

  it('friendly once it is played: no points, the free battles of the day (from the server)', async () => {
    useBillingStore().setLimits({ sessions: null, battles: { limit: 2, used: 1, remaining: 1, resets_at: '2099-01-01T00:00:00.000Z' } })
    const r = await render(withDuel(duelAvailability({ rated_available: false })))

    expect(tid(r, 'duel-kind').text()).toBe('Товарищеская')
    expect(tid(r, 'duel-kind-hint').text()).toContain('бесплатных игр дня')
    expect(tid(r, 'battle-limit').text()).toBe('Баттлов сегодня: осталось 1 из 2')
    expect(has(r, 'rated-note')).toBe(false)
  })
})

describe('CupDuelNewView: the challenge', () => {
  it('personal: the confirmation, then one request with a request_id; the screen of the challenge', async () => {
    vi.mocked(api.cups.duels.challenge).mockResolvedValue(cupDuel({ id: 41 }))
    const r = await render()

    await click(r, 'opponent-1')
    await click(r, 'next-btn')
    expect(sheet('confirm-title')?.textContent).toBe('Вызвать Aziza?')
    expect(sheet('confirm-text')?.textContent).toContain('личные сообщения')
    expect(api.cups.duels.challenge).not.toHaveBeenCalled()
    await press('send-btn')

    expect(api.cups.duels.challenge).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.cups.duels.challenge).mock.calls[0]?.[0]).toBe(5)
    expect(sent()).toEqual({ opponent_participant_id: 1, public: false, request_id: expect.stringMatching(UUID) })
    expect(route(r)).toBe('/cups/5/duels/41')
  })

  it('anybody in the group: public, no opponent', async () => {
    vi.mocked(api.cups.duels.challenge).mockResolvedValue(cupDuel({ id: 42, public: true, opponent: null }))
    const r = await render()

    await click(r, 'opponent-public')
    await click(r, 'next-btn')
    expect(sheet('confirm-title')?.textContent).toBe('Вызвать всех в группе?')
    expect(sheet('confirm-text')?.textContent).toContain('чате группы')
    await press('send-btn')

    expect(sent()).toEqual({ public: true, request_id: expect.stringMatching(UUID) })
    expect(route(r)).toBe('/cups/5/duels/42')
  })

  it('a double tap is one request; the button waits (aria-busy)', async () => {
    let resolve!: (duel: CupDuel) => void
    vi.mocked(api.cups.duels.challenge).mockReturnValue(new Promise((yes) => (resolve = yes)))
    const r = await render()

    await click(r, 'opponent-3')
    await click(r, 'next-btn')
    sheet('send-btn')?.click()
    sheet('send-btn')?.click()
    await flushPromises()
    expect(sheet('send-btn')?.getAttribute('aria-busy')).toBe('true')
    resolve(cupDuel({ id: 43 }))
    await flushPromises()

    expect(api.cups.duels.challenge).toHaveBeenCalledTimes(1)
    expect(route(r)).toBe('/cups/5/duels/43')
  })

  it('a lost reply: "Retry" sends the SAME request_id; a new choice is a new attempt', async () => {
    vi.mocked(api.cups.duels.challenge).mockRejectedValueOnce(apiError('network', 0, 'network'))
    vi.mocked(api.cups.duels.challenge).mockRejectedValueOnce(apiError('server', 503, 'server'))
    const r = await render()

    await click(r, 'opponent-1')
    await click(r, 'next-btn')
    await press('send-btn')
    expect(sheet('send-error')?.textContent).toContain('Нет связи')
    expect(sheet('send-btn')?.textContent?.trim()).toBe('Повторить')
    await press('send-btn')

    expect(sent(1).request_id).toBe(sent(0).request_id)

    vi.mocked(api.cups.duels.challenge).mockResolvedValueOnce(cupDuel({ id: 44 }))
    await press('confirm-cancel')
    await click(r, 'opponent-3')
    await click(r, 'next-btn')
    await press('send-btn')
    expect(sent(2).request_id).not.toBe(sent(0).request_id)
    expect(sent(2).opponent_participant_id).toBe(3)
  })

  it('a refusal for sure: its words; the next tap is a new attempt', async () => {
    vi.mocked(api.cups.duels.challenge).mockRejectedValueOnce(apiError('conflict', 409, 'cup_duel_limit', { scope: 'pair', limit: 3 }))
    vi.mocked(api.cups.duels.challenge).mockRejectedValueOnce(apiError('conflict', 409, 'cup_duel_limit', { scope: 'public_chat', limit: 10 }))
    const r = await render()

    await click(r, 'opponent-1')
    await click(r, 'next-btn')
    await press('send-btn')
    expect(sheet('send-error')?.textContent).toBe('Одному участнику — не больше 3 вызовов в день.')
    await press('send-btn')
    expect(sent(1).request_id).not.toBe(sent(0).request_id)
    expect(sheet('send-error')?.textContent).toBe('В группе сегодня уже 10 вызовов всем — больше нельзя. Вызовите участника лично.')
    expect(route(r)).toBe('/cups/5/duel')
  })

  it('403 practice_limit_reached (a friendly match on Free): the paywall of the practice', async () => {
    vi.mocked(api.cups.duels.challenge).mockRejectedValue(
      apiError('forbidden', 403, 'practice_limit_reached', { kind: 'battles', limit: 1, used: 1, resets_at: '2026-12-02T19:00:00.000Z' }),
    )
    const r = await render(withDuel(duelAvailability({ rated_available: false })))

    await click(r, 'opponent-1')
    await click(r, 'next-btn')
    await press('send-btn')

    expect(tid(r, 'paywall').attributes('data-reason')).toBe('practice_limit')
    expect(tid(r, 'paywall').attributes('data-limit')).toBe('1')
    expect(sheet('confirm-sheet')).toBeNull()
  })

  it('409 cup_duel_already_open: the way to my open challenge', async () => {
    vi.mocked(api.cups.duels.challenge).mockRejectedValue(apiError('conflict', 409, 'cup_duel_already_open', { duel_id: 39 }))
    const r = await render()

    await click(r, 'opponent-1')
    await click(r, 'next-btn')
    await press('send-btn')
    await press('open-existing')

    expect(route(r)).toBe('/cups/5/duels/39')
  })

  it('409 cup_duel_disabled: no duels, remembered for the session', async () => {
    vi.mocked(api.cups.duels.challenge).mockRejectedValue(apiError('conflict', 409, 'cup_duel_disabled'))
    const r = await render()

    await click(r, 'opponent-1')
    await click(r, 'next-btn')
    await press('send-btn')

    expect(state(r)).toBe('disabled')
    expect(useCupDuelsStore().off).toBe(true)
  })
})

describe('CupDuelNewView: no challenge today', () => {
  it('my open challenge is shown instead of a new one', async () => {
    const r = await render(withDuel(duelAvailability({ can_challenge: false, open_duel_id: 39 })))

    expect(has(r, 'opponents')).toBe(false)
    await click(r, 'open-mine')
    expect(route(r)).toBe('/cups/5/duels/39')
  })

  it('the challenges of the day are spent: why', async () => {
    const r = await render(withDuel(duelAvailability({ can_challenge: false, challenges_left: 0 })))

    expect(has(r, 'opponents')).toBe(false)
    expect(tid(r, 'cannot-challenge').text()).toBe('Вызовы на сегодня закончились.')
  })

  it('`me.duel` null: the duels are off', async () => {
    const r = await render(withDuel(null))

    expect(state(r)).toBe('disabled')
  })

  it('not a participant: no challenge', async () => {
    const r = await render(cupBoard({ me: null }))

    expect(state(r)).toBe('not_participant')
  })

  it('403 cup_not_member on reading the table: "The cup is not available"', async () => {
    vi.mocked(api.cups.show).mockRejectedValue(apiError('forbidden', 403, 'cup_not_member'))
    const stub = { template: '<div />' }
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', name: 'cup', component: stub }] })
    const wrapper = mount(CupDuelNewView, { props: { id: 5 }, global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.get('[data-testid=cup-duel-new-view]').attributes('data-state')).toBe('unavailable')
  })
})

describe('CupDuelNewView: uz', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()

    expect(tid(r, 'opponent-public').text()).toContain('Guruhdagi hammani')
    await click(r, 'opponent-1')
    expect(tid(r, 'duel-kind').text()).toBe('Hisobli')
    await click(r, 'next-btn')
    expect(sheet('confirm-title')?.textContent).toBe('Azizani chaqirasizmi?')
  })
})
