import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeSessionView from '../PracticeSessionView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { answerResponse, pairsElement, session } from '@/api/__tests__/practiceFixtures'
import type { PairsRoundElement, PracticeAnswerResponse, PracticeSession } from '@/api/types'

/*
 * The real board (PairsBoard) under the real screen and the real store: what the board learns of a match from the answer that the
 * store hands over - a pair in `matched` was right, one more in `mismatches` was wrong - and the way the screen keeps it company
 * (one haptic for a wrong match, none twice, the end of a round, a lost reply, a re-read after 409). Only the API is a double.
 */

vi.mock('@/api', () => ({
  api: { practice: { get: vi.fn(), open: vi.fn(), answer: vi.fn(), replace: vi.fn(), audioBlob: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({ haptic: vi.fn(), notification: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    showBackButton: () => () => {},
    haptic: tg.haptic,
    notificationHaptic: tg.notification,
    selectionHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

// mother-мама (lM1-rU2), father-папа (lF2-rW4), sister-сестра (lS3-rT1), brother-брат (lB4-rV3)
const MOTHER: [string, string] = ['lM1', 'rU2']
const FATHER: [string, string] = ['lF2', 'rW4']
const SISTER: [string, string] = ['lS3', 'rT1']
const BROTHER: [string, string] = ['lB4', 'rV3']

const board = (overrides: Partial<PairsRoundElement> = {}) => pairsElement({ round: 1, rounds: 3, ...overrides })
const playing = (element: PairsRoundElement, progress = { done: 0, total: 3 }): PracticeSession => session({ mode: 'pairs', progress, current: element })

/** The answer of the server to one match of a round. */
const reply = (verdict: 'correct' | 'wrong', pair: [string, string], next: PracticeSession, roundDone = false): PracticeAnswerResponse =>
  answerResponse({ verdict, first_attempt: true, reveal: null, pair: { left_id: pair[0], right_id: pair[1] }, round_done: roundDone, session: next })

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')

type Rendered = Awaited<ReturnType<typeof render>>

async function render(opened: PracticeSession) {
  vi.mocked(api.practice.get).mockResolvedValue(opened)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
    ],
  })
  await router.push({ name: 'practice-session', params: { id: 77 } })
  const wrapper = mount(PracticeSessionView, { props: { id: 77 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

const card = (r: Rendered, id: string) => r.wrapper.get(`[data-testid=${id.startsWith('l') ? 'left' : 'right'}-${id}]`)
const state = (r: Rendered, id: string) => card(r, id).attributes('data-state')

/** Taps the word and then its translation, and lets the answer come back. */
async function match(r: Rendered, [left, right]: [string, string]): Promise<void> {
  await card(r, left).trigger('click')
  await card(r, right).trigger('click')
  await flushPromises()
}

const later = async (ms: number) => {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.mocked(api.practice.answer).mockReset()
  vi.mocked(api.practice.get).mockReset()
  vi.mocked(showErrorToast).mockClear()
  tg.haptic.mockClear()
  tg.notification.mockClear()
})

afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

describe('PracticeSessionView with the real board of the pairs', () => {
  it('plays a round: a right pair goes green and leaves, a wrong one is red with ONE error haptic, the last one ends the round with its pairs', async () => {
    const answer = vi.mocked(api.practice.answer)
    answer
      .mockResolvedValueOnce(reply('correct', MOTHER, playing(board({ matched: [MOTHER] }))))
      .mockResolvedValueOnce(reply('wrong', [FATHER[0], SISTER[1]], playing(board({ matched: [MOTHER], mismatches: 1 }))))
      .mockResolvedValueOnce(reply('correct', FATHER, playing(board({ matched: [MOTHER, FATHER], mismatches: 1 }))))
      .mockResolvedValueOnce(reply('correct', SISTER, playing(board({ matched: [MOTHER, FATHER, SISTER], mismatches: 1 }))))
      .mockResolvedValueOnce(reply('correct', BROTHER, playing(board({ id: 'ePair00002', round: 2 }), { done: 1, total: 3 }), true))
    const r = await render(playing(board()))

    // A right pair.
    await match(r, MOTHER)
    expect(state(r, 'lM1')).toBe('correct')
    expect(state(r, 'rU2')).toBe('correct')
    expect(r.wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    await later(400)
    expect(state(r, 'lM1')).toBe('gone')
    expect(tg.notification).not.toHaveBeenCalled()

    // A wrong one: red, one buzz - the screen does not buzz for the board's own matches a second time.
    await match(r, [FATHER[0], SISTER[1]])
    expect(state(r, 'lF2')).toBe('incorrect')
    expect(state(r, 'rT1')).toBe('incorrect')
    expect(tg.notification).toHaveBeenCalledTimes(1)
    expect(tg.notification).toHaveBeenCalledWith('error')
    await later(600)
    expect(state(r, 'lF2')).toBe('idle')
    expect(state(r, 'rT1')).toBe('idle')

    // The rest.
    await match(r, FATHER)
    await later(400)
    await match(r, SISTER)
    await later(400)
    expect(r.wrapper.find('[data-testid=answer-reveal]').exists()).toBe(false)
    expect(r.wrapper.get('[data-testid=pairs-count]').text()).toBe('Осталась 1 пара')

    await match(r, BROTHER)

    expect(r.wrapper.get('[data-testid=reveal-text]').text()).toBe('Раунд 1 из 3 пройден')
    expect(r.wrapper.get('[data-testid=round-mistakes]').text()).toBe('Ошибок в раунде: 1')
    expect(r.wrapper.findAll('[data-testid=round-pair]').map((row) => row.findAll('span').map((span) => span.text()))).toEqual([
      ['mother', 'мама'],
      ['father', 'папа'],
      ['sister', 'сестра'],
      ['brother', 'брат'],
    ])
    expect(tg.notification).toHaveBeenLastCalledWith('success')
    expect(answer).toHaveBeenCalledTimes(5)
    expect(answer.mock.calls.map((call) => call[1].answer)).toEqual([
      { left_id: 'lM1', right_id: 'rU2' },
      { left_id: 'lF2', right_id: 'rT1' },
      { left_id: 'lF2', right_id: 'rW4' },
      { left_id: 'lS3', right_id: 'rT1' },
      { left_id: 'lB4', right_id: 'rV3' },
    ])

    // «Next»: a new board with nothing left over.
    await r.wrapper.get('[data-testid=continue-btn]').trigger('click')
    expect(r.wrapper.get('[data-testid=pairs-round]').text()).toBe('Раунд 2 из 3')
    expect(r.wrapper.findAll('button[data-state="idle"]')).toHaveLength(8)
    r.wrapper.unmount()
  })

  it('sends ONE request however often the learner taps while it is on its way', async () => {
    let settle!: (value: PracticeAnswerResponse) => void
    vi.mocked(api.practice.answer).mockReturnValue(new Promise((resolve) => (settle = resolve)))
    const r = await render(playing(board()))

    await match(r, MOTHER)
    await match(r, MOTHER)
    await match(r, FATHER)
    await card(r, 'lS3').trigger('click')

    expect(api.practice.answer).toHaveBeenCalledTimes(1)
    expect(r.wrapper.get('[data-testid=pairs-board] > div').attributes('aria-busy')).toBe('true')
    expect(state(r, 'lF2')).toBe('idle')

    settle(reply('correct', MOTHER, playing(board({ matched: [MOTHER] }))))
    await flushPromises()

    expect(state(r, 'lM1')).toBe('correct')
    expect(r.wrapper.get('[data-testid=pairs-board] > div').attributes('aria-busy')).toBeUndefined()
    await later(400)
    await match(r, FATHER) // and now the next one goes
    expect(api.practice.answer).toHaveBeenCalledTimes(2)
    r.wrapper.unmount()
  })

  it('frees the board when the reply is lost, says so, and sends the same pair again under the same request id', async () => {
    const answer = vi.mocked(api.practice.answer)
    answer.mockRejectedValueOnce(offline()).mockResolvedValueOnce(reply('correct', MOTHER, playing(board({ matched: [MOTHER] }))))
    const r = await render(playing(board()))

    await match(r, MOTHER)

    expect(showErrorToast).toHaveBeenCalledTimes(1)
    expect(state(r, 'lM1')).toBe('idle')
    expect(state(r, 'rU2')).toBe('idle')
    expect(tg.notification).not.toHaveBeenCalled()

    await match(r, MOTHER)

    expect(answer).toHaveBeenCalledTimes(2)
    expect(answer.mock.calls[1]?.[1].request_id).toBe(answer.mock.calls[0]?.[1].request_id) // the server counts it once
    expect(state(r, 'lM1')).toBe('correct')
    r.wrapper.unmount()
  })

  it('shows the board as the server has it after 409 pair_already_matched (another tab was first), with no complaint', async () => {
    const answer = vi.mocked(api.practice.answer)
    answer.mockRejectedValueOnce(new ApiClientError('conflict', 409, 'pair_already_matched', 'English message', undefined, { left_id: 'lM1' }))
    const r = await render(playing(board()))
    vi.mocked(api.practice.get).mockResolvedValue(playing(board({ matched: [MOTHER, FATHER] })))

    await match(r, MOTHER)
    await later(400)

    expect(showErrorToast).not.toHaveBeenCalled()
    for (const id of [...MOTHER, ...FATHER]) expect(state(r, id)).toBe('gone')
    expect(state(r, 'lS3')).toBe('idle')
    expect(r.wrapper.get('[data-testid=pairs-count]').text()).toBe('Осталось 2 пары')
    r.wrapper.unmount()
  })

  it('brings back a round that was half played after a reload: the pairs that were done are gone at once, nothing is announced', async () => {
    const r = await render(playing(board({ matched: [MOTHER, FATHER], mismatches: 2 })))

    for (const id of [...MOTHER, ...FATHER]) expect(state(r, id)).toBe('gone')
    expect(r.wrapper.get('[data-testid=pairs-count]').text()).toBe('Осталось 2 пары')
    expect(r.wrapper.get('[data-testid=pairs-status]').attributes('data-tone')).toBe('hint')
    expect(tg.notification).not.toHaveBeenCalled()

    vi.mocked(api.practice.answer).mockResolvedValueOnce(reply('correct', SISTER, playing(board({ matched: [MOTHER, FATHER, SISTER], mismatches: 2 }))))
    await match(r, SISTER)
    expect(state(r, 'lS3')).toBe('correct')
    r.wrapper.unmount()
  })

  it('draws the board in the language of the learner', async () => {
    setLocale('uz')
    const r = await render(playing(board()))

    expect(r.wrapper.get('[data-testid=pairs-round]').text()).toBe('3 raunddan 1-raund')
    expect(r.wrapper.get('[data-testid=pairs-count]').text()).toBe('4 ta juft qoldi')
    r.wrapper.unmount()
  })
})
