import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import BattleView from '../BattleView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { SERVER_NOW, answeringRound, at, battle, poll, pollOf, roundState } from '@/api/__tests__/battleFixtures'
import { pairsElement } from '@/api/__tests__/practiceFixtures'
import type { Battle, BattlePoll } from '@/api/types'

/*
 * A battle of PAIRS with the real board (the other specs of the screen draw a probe in its place): what the board does with the screen
 * around it - the table that survives a refused answer, the sending that does not show an empty board, the last pair that the board puts
 * together, the round that is said once. docs/16 §11.3: "pairs - a set and "Check"", one final answer.
 */

vi.mock('@/api', () => ({
  api: {
    battles: { list: vi.fn(), get: vi.fn(), poll: vi.fn(), round: vi.fn(), accept: vi.fn(), decline: vi.fn(), cancel: vi.fn(), ready: vi.fn(), surrender: vi.fn(), answer: vi.fn(), audioFailed: vi.fn(), invite: vi.fn(), audioBlob: vi.fn() },
    practice: { audioBlob: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: () => Promise.resolve() }) }))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: () => () => undefined,
    haptic: () => undefined,
    notificationHaptic: () => undefined,
    selectionHaptic: () => undefined,
  }),
}))

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')
const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string) => new ApiClientError(kind, status, code, 'English message')

function pending<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((yes) => {
    resolve = yes
  })
  return { promise, resolve }
}

/** The pairs of the table, as the board shows them: mother-мама, father-папа, sister-сестра, brother-брат. */
const TABLE = { lM1: 'rU2', lF2: 'rW4', lS3: 'rT1', lB4: 'rV3' }

const stamp = <T extends { server_now: string }>(value: T): T => ({ ...value, server_now: new Date(Date.now()).toISOString() })

const mounted: VueWrapper[] = []

async function render(initial: Battle = pairsBattle()) {
  vi.mocked(api.battles.get).mockImplementation(() => Promise.resolve(stamp(initial)))
  vi.mocked(api.battles.poll).mockImplementation(() => Promise.resolve(stamp(pollOf(initial))))
  vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(answeringRound({ element: pairsElement({ id: 'ePair00002', round: 2, rounds: 5 }) }))))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/friends', name: 'friends', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
    ],
  })
  await router.push({ name: 'battle', params: { id: 15 } })
  const wrapper = mount(BattleView, { props: { id: 15 }, global: { plugins: [router] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const pairsBattle = () => battle({ mode: 'pairs', round_seconds: 45 })

const has = (w: VueWrapper, testid: string) => w.find(`[data-testid=${testid}]`).exists()
const get = (w: VueWrapper, testid: string) => w.get(`[data-testid=${testid}]`)
const card = (w: VueWrapper, id: string) => get(w, `${id.startsWith('l') ? 'left' : 'right'}-${id}`)
const tap = async (w: VueWrapper, id: string) => {
  await card(w, id).trigger('click')
}
const match = async (w: VueWrapper, left: string, right: string) => {
  await tap(w, left)
  await tap(w, right)
}
async function pass(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

/** Three pairs by hand; the board puts the fourth together. */
async function makeTable(w: VueWrapper): Promise<void> {
  await match(w, 'lM1', 'rU2')
  await match(w, 'lF2', 'rW4')
  await match(w, 'lS3', 'rT1')
}

const answerReply = (overrides: { poll?: BattlePoll } = {}) => ({
  verdict: 'correct' as const,
  points: 100,
  accepted_at: SERVER_NOW,
  round_resolved: false,
  poll: poll({ version: 24, round: roundState({ my_answered: true }) }),
  ...overrides,
})

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
  vi.setSystemTime(new Date(SERVER_NOW))
  URL.createObjectURL = vi.fn(() => 'blob:clip')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  while (mounted.length > 0) mounted.pop()?.unmount()
  vi.useRealTimers()
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('BattleView with the real board of the pairs', () => {
  it('says the round once - in the header - and the board does not repeat it; the last pair is put together and the table can be sent', async () => {
    const w = await render()

    expect(get(w, 'headline').text()).toBe('Раунд 2 из 5')
    expect(has(w, 'pairs-round')).toBe(false)
    expect(get(w, 'pairs-board').attributes('data-mode')).toBe('battle')
    expect(get(w, 'pairs-check').attributes('disabled')).toBeDefined()

    await makeTable(w)

    expect(get(w, 'pairs-count').text()).toBe('Соединено 4 из 4')
    expect(get(w, 'pairs-status').text()).toBe('Последняя пара подобрана сама. Проверьте всё и нажмите «Проверить».')
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())
    await get(w, 'pairs-check').trigger('click')
    await flushPromises()

    expect(api.battles.answer).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.battles.answer).mock.calls[0]?.[1]).toMatchObject({ round: 2, answer: { pairs: TABLE } })
    expect(has(w, 'battle-waiting')).toBe(true) // the answer is in: the opponent's turn
  })

  it('an answer the server refuses outright gives the table back, whole: the learner sends it again, the second time as a new answer', async () => {
    const w = await render()
    await makeTable(w)
    vi.mocked(api.battles.answer).mockRejectedValueOnce(apiError('conflict', 409, 'battle_wrong_state'))

    await get(w, 'pairs-check').trigger('click')
    await flushPromises()

    // the observer of the board: the screen freed it, the table is still there and "Check" works again
    expect(showErrorToast).toHaveBeenCalledWith('Сейчас это действие недоступно. Экран обновится.')
    expect(has(w, 'pairs-board')).toBe(true)
    expect(get(w, 'pairs-count').text()).toBe('Соединено 4 из 4')
    expect(w.findAll('[data-testid=pair-number]')).toHaveLength(8)
    expect(get(w, 'pairs-check').attributes('disabled')).toBeUndefined()

    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())
    await get(w, 'pairs-check').trigger('click')
    await flushPromises()

    const sent = vi.mocked(api.battles.answer).mock.calls
    expect(sent).toHaveLength(2)
    expect(sent[1]?.[1].answer).toEqual({ pairs: TABLE })
    expect(sent[1]?.[1].request_id).not.toBe(sent[0]?.[1].request_id) // the first was refused for good: a new answer
    expect(has(w, 'battle-waiting')).toBe(true)
  })

  it('a table that was not delivered is not shown as an empty board while it goes again: “Sending…” stands alone, and it ends in “waiting”', async () => {
    const w = await render()
    await makeTable(w)
    vi.mocked(api.battles.answer).mockRejectedValue(offline())

    await get(w, 'pairs-check').trigger('click')
    await flushPromises()
    expect(has(w, 'battle-resending')).toBe(true)
    expect(has(w, 'pairs-board')).toBe(false)

    await pass(500)
    await pass(1000)
    await pass(2000)
    expect(has(w, 'battle-send-failed')).toBe(true)
    expect(has(w, 'pairs-board')).toBe(false)

    const again = pending<ReturnType<typeof answerReply>>()
    vi.mocked(api.battles.answer).mockReset()
    vi.mocked(api.battles.answer).mockReturnValue(again.promise)
    await get(w, 'retry-answer-btn').trigger('click')
    await flushPromises()

    expect(has(w, 'battle-resending')).toBe(true)
    expect(has(w, 'pairs-board')).toBe(false) // no new, empty board beside the note
    again.resolve(answerReply())
    await flushPromises()

    expect(has(w, 'battle-waiting')).toBe(true)
    // it is the table the learner made that went, the same one every time, with one request_id
    expect(vi.mocked(api.battles.answer).mock.calls[0]?.[1].answer).toEqual({ pairs: TABLE })
  })

  it('the next round starts with an empty board: nothing of the table of this one is left', async () => {
    const w = await render()
    await makeTable(w)
    vi.mocked(api.battles.answer).mockResolvedValue(answerReply())
    await get(w, 'pairs-check').trigger('click')
    await flushPromises()

    // round 3 begins (the learner was waiting)
    vi.mocked(api.battles.poll).mockImplementation(() => Promise.resolve(stamp(poll({ version: 30, round: roundState({ no: 3, deadline_at: at(90) }) }))))
    vi.mocked(api.battles.round).mockImplementation(() => Promise.resolve(stamp(answeringRound({ no: 3, deadline_at: at(90), element: pairsElement({ id: 'ePair00003', round: 3, rounds: 5 }) }))))
    await pass(2000)

    expect(get(w, 'headline').text()).toBe('Раунд 3 из 5')
    expect(get(w, 'pairs-count').text()).toBe('Соединено 0 из 4')
    expect(w.findAll('[data-testid=pair-number]')).toHaveLength(0)
    expect(get(w, 'pairs-check').attributes('disabled')).toBeDefined()
  })
})
