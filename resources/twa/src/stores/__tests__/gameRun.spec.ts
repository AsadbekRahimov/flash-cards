import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useGameRunStore } from '../gameRun'
import { answerResponse, finishedRun, pairs, runView, spell, translateBack, translateFwd, collocation } from '@/api/__tests__/gameRunFixtures'
import type { GameElement, GameRunView } from '@/api/gameRunTypes'

vi.mock('@/api', () => ({
  api: { gameRuns: { get: vi.fn(), answer: vi.fn(), hint: vi.fn(), abandon: vi.fn() } },
}))

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)
const offline = () => apiError('network', 0, 'network')

const sent = (n = 0) => vi.mocked(api.gameRuns.answer).mock.calls[n]![1]

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => setLocale('ru'))

/** A store with `run` on screen (the banner of its event, if any, already dismissed). */
async function started(run: GameRunView = runView()) {
  vi.mocked(api.gameRuns.get).mockResolvedValue(run)
  const store = useGameRunStore()
  await store.load(run.id)
  if (store.status === 'event') store.dismissEvent()
  return store
}

describe('gameRun store — entering a run', () => {
  it('loads the run: loading, then playing', async () => {
    const reply = pending<GameRunView>()
    vi.mocked(api.gameRuns.get).mockReturnValue(reply.promise)
    const store = useGameRunStore()

    const loading = store.load(9)
    expect(store.status).toBe('loading')
    expect(api.gameRuns.get).toHaveBeenCalledWith(9)

    reply.resolve(runView())
    await loading
    expect(store.status).toBe('playing')
    expect(store.element?.id).toBe('e1')
    expect(store.position).toBe(3)
  })

  it('shows the summary of a run that is over (completed or failed)', async () => {
    for (const reason of ['completed', 'failed'] as const) {
      const store = await started(finishedRun(reason))
      expect(store.status, reason).toBe('summary')
      expect(store.summary?.score, reason).toBe(320)
    }
  })

  it('treats a run that was left or ran out of time as closed: it does not count', async () => {
    const left = await started(finishedRun('abandoned'))
    expect(left.status).toBe('closed')
    expect(left.closedReason).toBe('abandoned')

    const late = await started(finishedRun('expired'))
    expect(late.closedReason).toBe('expired')
  })

  it('410 on the read is the closed state, with the reason the server gave', async () => {
    vi.mocked(api.gameRuns.get).mockRejectedValue(apiError('gone', 410, 'game_run_closed', { finish_reason: 'failed' }))
    const store = useGameRunStore()

    await store.load(9)

    expect(store.status).toBe('closed')
    expect(store.closedReason).toBe('failed')
  })

  it('any other failure is an error with the text of its code', async () => {
    vi.mocked(api.gameRuns.get).mockRejectedValue(apiError('not_found', 404, 'game_run_not_found'))
    const store = useGameRunStore()

    await store.load(9)

    expect(store.status).toBe('error')
    expect(store.errorMessage).toBe('Забег не найден.')
  })

  it('drops the reply of a run that was left for another one (generation)', async () => {
    const first = pending<GameRunView>()
    vi.mocked(api.gameRuns.get).mockReturnValueOnce(first.promise)
    const store = useGameRunStore()
    const loading = store.load(9)

    vi.mocked(api.gameRuns.get).mockResolvedValueOnce(runView({ id: 10 }))
    await store.load(10)
    first.resolve(runView({ id: 9 }))
    await loading

    expect(store.runId).toBe(10)
    expect(store.run?.id).toBe(10)
  })
})

describe('gameRun store — the events', () => {
  it('stops at the banner of an event before the task, once per element', async () => {
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({}, translateFwd(), 'sandstorm'))
    const store = useGameRunStore()

    await store.load(9)
    expect(store.status).toBe('event')
    expect(store.event).toBe('sandstorm')

    store.dismissEvent()
    expect(store.status).toBe('playing')

    // The same element read again (a conflict, the hint) does not bring the banner back.
    store.applyRun(runView({}, translateFwd(), 'sandstorm'))
    expect(store.status).toBe('playing')
  })

  it.each(['sandstorm', 'oasis', 'bazaar'] as const)('shows the banner of %s', async (event) => {
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({}, translateFwd(), event))
    const store = useGameRunStore()
    await store.load(9)
    expect(store.status).toBe('event')
    expect(store.event).toBe(event)
  })

  it('the next element with an event gets its banner after "Next"', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ run: runView({ position: 4 }, translateBack(), 'oasis') }))

    await store.answer({ option_id: 'o1' })
    expect(store.status).toBe('reveal')
    store.continueAfterReveal()

    expect(store.status).toBe('event')
    expect(store.event).toBe('oasis')
  })

  it('ignores dismissEvent when there is no banner', async () => {
    const store = await started()
    store.dismissEvent()
    expect(store.status).toBe('playing')
  })
})

describe('gameRun store — an answer', () => {
  it('sends the answer with a uuid request id, the element and the time spent; the reply is the reveal', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse())

    await store.answer({ option_id: 'o1' })

    expect(api.gameRuns.answer).toHaveBeenCalledTimes(1)
    expect(api.gameRuns.answer).toHaveBeenCalledWith(9, expect.objectContaining({ element_id: 'e1', answer: { option_id: 'o1' } }))
    expect(sent().request_id).toMatch(UUID)
    expect(sent().time_spent_ms).toBeGreaterThanOrEqual(0)
    expect(store.status).toBe('reveal')
    expect(store.outcome?.verdict).toBe('correct')
    expect(store.outcome?.points).toBe(10)
    // The header keeps the place of the answered element while the reveal is read; the run has moved on.
    expect(store.run?.position).toBe(4)
    expect(store.position).toBe(3)
  })

  it('"Next" shows the next element; the place is the run\'s again', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse())
    await store.answer({ option_id: 'o1' })
    const seq = store.elementSeq

    store.continueAfterReveal()

    expect(store.status).toBe('playing')
    expect(store.element?.id).toBe('e2')
    expect(store.outcome).toBeNull()
    expect(store.elementSeq).toBe(seq + 1)
    expect(store.position).toBe(4)
  })

  it('the last answer leads from the reveal to the summary', async () => {
    const store = await started(runView({ position: 10 }))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ run: finishedRun('completed') }))

    await store.answer({ option_id: 'o1' })
    expect(store.status).toBe('reveal')
    store.continueAfterReveal()

    expect(store.status).toBe('summary')
  })

  it('a run lost on this answer ends in the summary of a failed run', async () => {
    const store = await started(runView({ flasks: 1 }))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ verdict: 'wrong', flask_lost: true, run: finishedRun('failed') }))

    await store.answer({ option_id: 'o2' })
    store.continueAfterReveal()

    expect(store.status).toBe('summary')
    expect(store.run?.finish_reason).toBe('failed')
  })

  it('"I don\'t know" sends skip', async () => {
    const store = await started(runView({}, spell()))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse())

    await store.skip()

    expect(sent().answer).toEqual({ skip: true })
  })

  it('a double tap is one request (the status is not "playing" while one is on its way)', async () => {
    const store = await started()
    const reply = pending<ReturnType<typeof answerResponse>>()
    vi.mocked(api.gameRuns.answer).mockReturnValue(reply.promise)

    const first = store.answer({ option_id: 'o1' })
    expect(store.status).toBe('submitting')
    await store.answer({ option_id: 'o2' })
    reply.resolve(answerResponse())
    await first

    expect(api.gameRuns.answer).toHaveBeenCalledTimes(1)
  })

  it('drops the late reply of an answer of a run that was left (generation)', async () => {
    const store = await started()
    const reply = pending<ReturnType<typeof answerResponse>>()
    vi.mocked(api.gameRuns.answer).mockReturnValue(reply.promise)
    const answering = store.answer({ option_id: 'o1' })

    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({ id: 12 }, translateBack()))
    await store.load(12)
    reply.resolve(answerResponse())
    await answering

    expect(store.runId).toBe(12)
    expect(store.status).toBe('playing')
    expect(store.outcome).toBeNull()
    expect(store.element?.id).toBe('e2')
  })

  it('ignores an answer while the banner of an event is on', async () => {
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({}, translateFwd(), 'bazaar'))
    const store = useGameRunStore()
    await store.load(9)

    await store.answer({ option_id: 'o1' })

    expect(api.gameRuns.answer).not.toHaveBeenCalled()
  })
})

describe('gameRun store — a reply that does not come', () => {
  it('keeps the request id AND the answer across a network error, and repeats exactly that', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerResponse())

    await store.answer({ option_id: 'o1' })
    expect(store.status).toBe('playing')
    expect(store.actionError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    const firstId = sent(0).request_id
    expect(store.pendingRequestId).toBe(firstId)

    // Whatever the screen hands over now, the answer that went first is the one that is sent again.
    await store.answer({ option_id: 'o3' })

    expect(sent(1).request_id).toBe(firstId)
    expect(sent(1).answer).toEqual({ option_id: 'o1' })
    expect(store.status).toBe('reveal')
    expect(store.pendingRequestId).toBeNull()
    expect(store.pendingAnswer).toBeNull()
  })

  it.each([
    ['server', 500, 'server_error'],
    ['rate_limited', 429, 'too_many_requests'],
  ] as const)('a %s error is repeated under the same id too', async (kind, status, code) => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValueOnce(apiError(kind, status, code)).mockResolvedValueOnce(answerResponse())

    await store.answer({ text: 'apple' })
    await store.answer({ text: 'apple' })

    expect(sent(1).request_id).toBe(sent(0).request_id)
  })

  it('a new element gets a new request id', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValueOnce(offline())
    await store.answer({ option_id: 'o1' })
    const lost = sent(0).request_id

    store.applyRun(runView({ position: 4 }, translateBack()))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse())
    await store.answer({ option_id: 'o1' })

    expect(sent(1).request_id).not.toBe(lost)
  })

  it('each match of a round of pairs has its own id; a lost match is repeated under its own', async () => {
    const store = await started(runView({}, pairs()))
    vi.mocked(api.gameRuns.answer)
      .mockRejectedValueOnce(offline()) // A lost
      .mockResolvedValueOnce(answerResponse({ verdict: 'wrong', pair: { left_id: 'l2', right_id: 'r2' }, round_done: false, reveal: null, run: runView({}, pairs({ mismatches: 1 })) })) // B
      .mockResolvedValueOnce(answerResponse({ pair: { left_id: 'l1', right_id: 'r2' }, round_done: true, reveal: null })) // A again

    await store.answer({ left_id: 'l1', right_id: 'r2' })
    await store.answer({ left_id: 'l2', right_id: 'r2' })
    await store.answer({ left_id: 'l1', right_id: 'r2' })

    expect(sent(0).request_id).not.toBe(sent(1).request_id)
    expect(sent(2).request_id).toBe(sent(0).request_id)
  })
})

describe('gameRun store — rounds of pairs', () => {
  it('stays on the board after a match of the round', async () => {
    const store = await started(runView({}, pairs()))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(
      answerResponse({ pair: { left_id: 'l1', right_id: 'r2' }, round_done: false, reveal: null, run: runView({}, pairs({ matched: [['l1', 'r2']] })) }),
    )

    await store.answer({ left_id: 'l1', right_id: 'r2' })

    expect(store.status).toBe('playing')
    expect((store.element as { matched: unknown }).matched).toEqual([['l1', 'r2']])
  })

  it('the last match of the round goes to the reveal', async () => {
    const store = await started(runView({}, pairs()))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(answerResponse({ pair: { left_id: 'l2', right_id: 'r1' }, round_done: true, reveal: null }))

    await store.answer({ left_id: 'l2', right_id: 'r1' })

    expect(store.status).toBe('reveal')
    expect(store.outcome?.roundDone).toBe(true)
  })

  it('a run lost in the middle of a round goes to the reveal, not back to the board', async () => {
    const store = await started(runView({ flasks: 1 }, pairs()))
    vi.mocked(api.gameRuns.answer).mockResolvedValue(
      answerResponse({ verdict: 'wrong', flask_lost: true, pair: { left_id: 'l1', right_id: 'r1' }, round_done: false, reveal: null, run: finishedRun('failed') }),
    )

    await store.answer({ left_id: 'l1', right_id: 'r1' })

    expect(store.status).toBe('reveal')
  })
})

describe('gameRun store — conflicts', () => {
  it('element_not_current: the run is read again, the learner is told, the screen follows the server', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('conflict', 409, 'element_not_current', { current: 'e2' }))
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({ position: 4 }, translateBack()))
    const seq = store.elementSeq

    await store.answer({ option_id: 'o1' })

    expect(api.gameRuns.get).toHaveBeenLastCalledWith(9)
    expect(store.status).toBe('playing')
    expect(store.element?.id).toBe('e2')
    expect(store.elementSeq).toBe(seq + 1)
    expect(store.actionError).toBe('Задание уже сменилось. Экран обновлён.')
    expect(store.pendingRequestId).toBeNull()
  })

  it('pair_already_matched: the round is read again, quietly', async () => {
    const store = await started(runView({}, pairs()))
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('conflict', 409, 'pair_already_matched'))
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({}, pairs({ matched: [['l1', 'r2']] })))

    await store.answer({ left_id: 'l1', right_id: 'r2' })

    expect(store.status).toBe('playing')
    expect(store.actionError).toBe('')
    expect((store.element as { matched: unknown }).matched).toEqual([['l1', 'r2']])
  })

  it('answer_already_accepted: the verdict that counted is told, nothing else', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'wrong' }))
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({ position: 4 }, translateBack()))

    await store.answer({ option_id: 'o1' })

    expect(store.status).toBe('reveal')
    expect(store.outcome).toMatchObject({ verdict: 'wrong', earlier: true, reveal: null, position: 3 })
    store.continueAfterReveal()
    expect(store.element?.id).toBe('e2')
  })

  it('a refusal for the shape of the answer leaves the learner on the task, afresh, with the text of its code', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('validation', 422, 'unknown_option_id'))
    const seq = store.elementSeq

    await store.answer({ option_id: 'zzz' })

    expect(store.status).toBe('playing')
    expect(store.actionError).toBe('Такого варианта в задании нет. Обновите экран.')
    expect(store.elementSeq).toBe(seq + 1)
    expect(store.pendingRequestId).toBeNull()
  })

  it('410 on an answer is the closed state', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('gone', 410, 'game_run_closed', { finish_reason: 'expired' }))

    await store.answer({ option_id: 'o1' })

    expect(store.status).toBe('closed')
    expect(store.closedReason).toBe('expired')
  })

  it('an error that is neither a conflict nor a lost reply (404) is the error state', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('not_found', 404, 'game_run_not_found'))

    await store.answer({ option_id: 'o1' })

    expect(store.status).toBe('error')
  })
})

describe('gameRun store — the hint of the bazaar', () => {
  const bazaarRun = (element: GameElement = translateFwd(), hint = { price: 5, available: true }) => runView({ hint }, element, 'bazaar')

  it('offers the hint only while the server says it can be bought', async () => {
    expect((await started(runView())).hintOffer).toBeNull()
    expect((await started(bazaarRun())).hintOffer).toEqual({ price: 5 })
  })

  it('buys the hint: the element stays (no new key), carries the hint, and the balance is known', async () => {
    const store = await started(bazaarRun())
    const seq = store.elementSeq
    vi.mocked(api.gameRuns.hint).mockResolvedValue({
      hint: { options_removed: ['o3', 'o4'] },
      coins: { balance: 7 },
      run: runView({ hint: { price: 5, available: false } }, translateFwd({ hint: { options_removed: ['o3', 'o4'] } }), 'bazaar'),
    })

    await store.hint()

    expect(api.gameRuns.hint).toHaveBeenCalledWith(9, { request_id: expect.stringMatching(UUID), element_id: 'e1' })
    expect(store.status).toBe('playing')
    expect(store.elementSeq).toBe(seq)
    expect(store.coins).toBe(7)
    expect(store.hintOffer).toBeNull()
    expect((store.element as { hint?: unknown }).hint).toEqual({ options_removed: ['o3', 'o4'] })
  })

  it('a lost reply is repeated under the same id: the coins go once', async () => {
    const store = await started(bazaarRun())
    vi.mocked(api.gameRuns.hint).mockRejectedValueOnce(offline()).mockResolvedValueOnce({ hint: { prefix: 'ap' }, coins: { balance: 3 }, run: bazaarRun(spell(), { price: 5, available: false }) })

    await store.hint()
    expect(store.status).toBe('playing')
    expect(store.actionError).not.toBe('')
    await store.hint()

    const calls = vi.mocked(api.gameRuns.hint).mock.calls
    expect(calls[1]![1].request_id).toBe(calls[0]![1].request_id)
    expect(store.coins).toBe(3)
  })

  it('coins_insufficient: a toast, the balance from the refusal, the offer stays', async () => {
    const store = await started(bazaarRun())
    vi.mocked(api.gameRuns.hint).mockRejectedValue(apiError('conflict', 409, 'coins_insufficient', { balance: 2, price: 5 }))

    await store.hint()

    expect(store.status).toBe('playing')
    expect(store.actionError).toBe('Не хватает монет на подсказку. Монеты дают за забеги.')
    expect(store.coins).toBe(2)
    expect(store.hintOffer).toEqual({ price: 5 })
  })

  it('hint_not_available: the run is read again and has no button', async () => {
    const store = await started(bazaarRun())
    vi.mocked(api.gameRuns.hint).mockRejectedValue(apiError('conflict', 409, 'hint_not_available'))
    vi.mocked(api.gameRuns.get).mockResolvedValue(runView({ hint: { price: 5, available: false } }, translateFwd(), 'bazaar'))

    await store.hint()

    expect(store.hintOffer).toBeNull()
    expect(store.status).toBe('playing')
    expect(store.actionError).toBe('Подсказки для этого задания нет.')
  })

  it('does nothing when there is no offer', async () => {
    const store = await started()
    await store.hint()
    expect(api.gameRuns.hint).not.toHaveBeenCalled()
  })

  it('410 on the hint is the closed state', async () => {
    const store = await started(bazaarRun())
    vi.mocked(api.gameRuns.hint).mockRejectedValue(apiError('gone', 410, 'game_run_closed', { finish_reason: 'failed' }))

    await store.hint()

    expect(store.status).toBe('closed')
  })

  it('a hint on a collocation is just another version of the same element', async () => {
    const store = await started(bazaarRun(collocation()))
    vi.mocked(api.gameRuns.hint).mockResolvedValue({
      hint: { options_removed: ['c2', 'c3'] },
      coins: { balance: 1 },
      run: runView({ hint: { price: 5, available: false } }, { ...collocation(), hint: { options_removed: ['c2', 'c3'] } }, 'bazaar'),
    })

    await store.hint()

    expect(store.element?.id).toBe('e7')
    expect(store.status).toBe('playing')
  })
})

describe('gameRun store — leaving', () => {
  it('abandons the run: POST once, true', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.abandon).mockResolvedValue(finishedRun('abandoned'))

    expect(await store.abandon()).toBe(true)
    expect(api.gameRuns.abandon).toHaveBeenCalledWith(9)
    expect(store.abandoning).toBe(false)
  })

  it('a run that is over already (410) is left all the same', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.abandon).mockRejectedValue(apiError('gone', 410, 'game_run_closed'))

    expect(await store.abandon()).toBe(true)
  })

  it('a failure keeps the learner on the run, with the reason', async () => {
    const store = await started()
    vi.mocked(api.gameRuns.abandon).mockRejectedValue(offline())

    expect(await store.abandon()).toBe(false)
    expect(store.actionError).not.toBe('')
    expect(store.status).toBe('playing')
  })

  it('a second tap while the first is on its way is one request', async () => {
    const store = await started()
    const reply = pending<GameRunView>()
    vi.mocked(api.gameRuns.abandon).mockReturnValue(reply.promise)

    const first = store.abandon()
    expect(await store.abandon()).toBe(false)
    reply.resolve(finishedRun('abandoned'))
    await first

    expect(api.gameRuns.abandon).toHaveBeenCalledTimes(1)
  })
})

describe('gameRun store — texts', () => {
  it('speaks Uzbek when the locale is uz', async () => {
    setLocale('uz')
    const store = await started()
    vi.mocked(api.gameRuns.answer).mockRejectedValue(apiError('not_found', 404, 'game_run_not_found'))
    await store.answer({ option_id: 'o1' })
    expect(store.status).toBe('error')
    expect(store.errorMessage).toBe('Yurish topilmadi.')
  })
})
