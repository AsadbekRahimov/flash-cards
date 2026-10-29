import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { usePracticeStore } from '../practice'
import {
  activeSession,
  answerResponse,
  dictationElement,
  finishedSession,
  hub,
  pairsElement,
  sentenceElement,
  session,
} from '@/api/__tests__/practiceFixtures'

vi.mock('@/api', () => ({
  api: { practice: { hub: vi.fn(), open: vi.fn(), get: vi.fn(), answer: vi.fn(), replace: vi.fn() } },
}))

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)
const offline = () => apiError('network', 0, 'network')

/** The body of the n-th `POST …/answer`. */
const sent = (n = 0) => vi.mocked(api.practice.answer).mock.calls[n]![1]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => setLocale('ru'))

/** A store with a session of `mode` on screen. */
async function started(s = session()) {
  vi.mocked(api.practice.get).mockResolvedValue(s)
  const store = usePracticeStore()
  await store.load(s.id)
  return store
}

describe('practice store — the hub', () => {
  it('loads the hub of a lesson: loading, then ready', async () => {
    let finish!: (value: ReturnType<typeof hub>) => void
    vi.mocked(api.practice.hub).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const store = usePracticeStore()

    const loading = store.loadHub('english-a1', 41)
    expect(store.hubStatus).toBe('loading')
    expect(api.practice.hub).toHaveBeenCalledWith('english-a1', 41)

    finish(hub())
    await loading
    expect(store.hubStatus).toBe('ready')
    expect(store.hub?.modes).toHaveLength(4)
  })

  it('asks for a course without a lesson', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ lesson: null }))
    const store = usePracticeStore()

    await store.loadHub('english-a1')

    expect(api.practice.hub).toHaveBeenCalledWith('english-a1', null)
  })

  it('practice_unavailable (403) is its own state, not an error', async () => {
    vi.mocked(api.practice.hub).mockRejectedValue(apiError('forbidden', 403, 'practice_unavailable'))
    const store = usePracticeStore()

    await store.loadHub('english-a1', 41)

    expect(store.hubStatus).toBe('unavailable')
    expect(store.hub).toBeNull()
  })

  it('any other failure is an error with the text of its code (never the English message)', async () => {
    vi.mocked(api.practice.hub).mockRejectedValue(apiError('not_found', 404, 'course_not_found'))
    const store = usePracticeStore()

    await store.loadHub('nope')

    expect(store.hubStatus).toBe('error')
    expect(store.hubError).toBe('Курс не найден или недоступен.')
    expect(store.hubError).not.toContain('English')
  })

  it('a network failure says so', async () => {
    vi.mocked(api.practice.hub).mockRejectedValue(offline())
    const store = usePracticeStore()

    await store.loadHub('english-a1')

    expect(store.hubError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
  })

  it('the hub that is already on screen stays when the refresh fails', async () => {
    vi.mocked(api.practice.hub).mockResolvedValueOnce(hub()).mockRejectedValueOnce(offline())
    const store = usePracticeStore()
    await store.loadHub('english-a1', 41)

    await store.loadHub('english-a1', 41)

    expect(store.hubStatus).toBe('ready')
    expect(store.hub).not.toBeNull()
  })

  it('the hub of another lesson is not shown while the new one loads', async () => {
    vi.mocked(api.practice.hub).mockResolvedValueOnce(hub())
    const store = usePracticeStore()
    await store.loadHub('english-a1', 41)

    vi.mocked(api.practice.hub).mockReturnValueOnce(new Promise(() => {}))
    void store.loadHub('english-a1', 42)

    expect(store.hub).toBeNull()
    expect(store.hubStatus).toBe('loading')
  })

  it('remembers the scope of the hub: the screens lead back to it', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub())
    const store = usePracticeStore()

    await store.loadHub('english-a1', 41)

    expect(store.scope).toEqual({ course: 'english-a1', lessonId: 41 })
  })
})

describe('practice store — opening a session', () => {
  const request = { mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'hub' } as const

  it('opens it and keeps the answer: entering the session does not read it a second time', async () => {
    const opened = session({ id: 78, mode: 'pairs' })
    vi.mocked(api.practice.open).mockResolvedValue(opened)
    const store = usePracticeStore()

    expect(await store.open(request)).toBe(78)
    expect(api.practice.open).toHaveBeenCalledWith(request)
    expect(store.opening).toBeNull()

    await store.load(78)

    expect(api.practice.get).not.toHaveBeenCalled()
    expect(store.status).toBe('ready')
    expect(store.session?.id).toBe(78)
  })

  it('does not open two at once: a second tap is ignored', async () => {
    let finish!: (value: ReturnType<typeof session>) => void
    vi.mocked(api.practice.open).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const store = usePracticeStore()

    const first = store.open(request)
    expect(store.opening).toBe('pairs')
    expect(await store.open(request)).toBeNull()
    expect(api.practice.open).toHaveBeenCalledTimes(1)

    finish(session())
    await first
  })

  it('an unfinished session (409) is handed to the screen with what the server said about it', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(
      apiError('conflict', 409, 'practice_session_active', {
        session_id: 77,
        mode: 'sentence',
        course: 'english-a1',
        lesson_id: 41,
        progress: { done: 2, total: 5 },
      }),
    )
    const store = usePracticeStore()

    expect(await store.open(request)).toBeNull()

    expect(store.conflict).toEqual({ id: 77, mode: 'sentence', course: 'english-a1', lesson_id: 41, progress: { done: 2, total: 5 } })
    expect(store.openError).toBe('')
  })

  it('falls back to the session the hub knows when the details are missing', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ active_session: activeSession({ id: 90 }) }))
    vi.mocked(api.practice.open).mockRejectedValue(apiError('conflict', 409, 'practice_session_active'))
    const store = usePracticeStore()
    await store.loadHub('english-a1', 41)

    await store.open(request)

    expect(store.conflict?.id).toBe(90)
  })

  it('says it in words when there is nothing to offer instead', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(apiError('conflict', 409, 'practice_session_active'))
    const store = usePracticeStore()

    await store.open(request)

    expect(store.conflict).toBeNull()
    expect(store.openError).toBe('У вас уже есть незавершённая практика.')
  })

  it('asks the paywall to open when the plan is Pro-only (403)', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    const store = usePracticeStore()

    expect(await store.open(request)).toBeNull()

    expect(store.paywall).toBe(true)
    expect(store.openError).toBe('')
  })

  it('tells why a mode has no material (422), in the words of the code', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(
      apiError('validation', 422, 'practice_not_enough_material', { mode: 'pairs', available: 2, required: 3, reason: 'no_words' }),
    )
    const store = usePracticeStore()

    await store.open(request)

    expect(store.openError).toBe('Для этого режима пока мало материала. Выучите ещё несколько слов.')
    expect(store.opening).toBeNull()
  })

  it('practice_unavailable makes the hub unavailable', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_unavailable'))
    const store = usePracticeStore()

    await store.open(request)

    expect(store.hubStatus).toBe('unavailable')
  })

  it('clears the old refusals when it tries again', async () => {
    vi.mocked(api.practice.open).mockRejectedValueOnce(offline()).mockResolvedValueOnce(session())
    const store = usePracticeStore()
    await store.open(request)
    expect(store.openError).not.toBe('')

    await store.open(request)

    expect(store.openError).toBe('')
    expect(store.conflict).toBeNull()
  })

  it('sends replace_active when the learner chose to start a new one', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 79 }))
    const store = usePracticeStore()

    expect(await store.open({ ...request, replace_active: true })).toBe(79)

    expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ replace_active: true })
  })
})

describe('practice store — entering a session', () => {
  it('reads the session and shows its first element', async () => {
    const store = await started(session({ mode: 'collocation' }))

    expect(store.status).toBe('ready')
    expect(store.current?.kind).toBe('collocation')
    expect(store.progress).toEqual({ done: 0, total: 5 })
    expect(store.elementSeq).toBe(1)
  })

  it('a session that is finished already shows the summary', async () => {
    const store = await started(finishedSession())

    expect(store.status).toBe('finished')
    expect(store.session?.summary?.correct_first).toBe(3)
    expect(store.current).toBeNull()
  })

  it('a closed session (410) is closed, with the words of the screen', async () => {
    vi.mocked(api.practice.get).mockRejectedValue(apiError('gone', 410, 'practice_session_closed', { finish_reason: 'expired' }))
    const store = usePracticeStore()

    await store.load(77)

    expect(store.status).toBe('closed')
    expect(store.errorMessage).toBe('Эта практика уже завершена или её время вышло.')
  })

  it('a session that is not found is an error, and says so', async () => {
    vi.mocked(api.practice.get).mockRejectedValue(apiError('not_found', 404, 'practice_session_not_found'))
    const store = usePracticeStore()

    await store.load(404)

    expect(store.status).toBe('error')
    expect(store.errorMessage).toBe('Эта практика не найдена.')
  })

  it('a lost connection is an error that can be retried by entering again', async () => {
    vi.mocked(api.practice.get).mockRejectedValueOnce(offline()).mockResolvedValueOnce(session())
    const store = usePracticeStore()

    await store.load(77)
    expect(store.status).toBe('error')

    await store.load(77)
    expect(store.status).toBe('ready')
    expect(store.errorMessage).toBe('')
  })

  it('a Free learner on a Pro-only plan gets the paywall (403) over a session that was in progress', async () => {
    const store = await started(session())
    vi.mocked(api.practice.get).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))

    await store.load(77)

    expect(store.paywall).toBe(true)
    expect(store.status).toBe('error')
  })

  it('forgets everything about the earlier session', async () => {
    const store = await started(session())
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline())
    await store.answer({ order: ['fW2'] })
    expect(store.pendingRequestId).not.toBeNull()
    const before = store.generation

    vi.mocked(api.practice.get).mockResolvedValue(session({ id: 80 }))
    await store.load(80)

    expect(store.generation).toBe(before + 1)
    expect(store.pendingRequestId).toBeNull()
    expect(store.actionError).toBe('')
    expect(store.outcome).toBeNull()
  })
})

describe('practice store — answering', () => {
  const order = ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1']

  it('sends the answer with a request id, the element and the time, and shows the verdict', async () => {
    const store = await started(session({ mode: 'sentence' }))
    const reply = answerResponse({ verdict: 'correct' })
    let finish!: (value: typeof reply) => void
    vi.mocked(api.practice.answer).mockReturnValue(new Promise((resolve) => (finish = resolve)))

    const answering = store.answer({ order })
    expect(store.status).toBe('submitting')

    finish(reply)
    await answering

    expect(api.practice.answer).toHaveBeenCalledWith(77, expect.objectContaining({ element_id: 'eSent00001', answer: { order } }))
    expect(sent().request_id).toMatch(UUID)
    expect(sent().time_spent_ms).toBeGreaterThanOrEqual(0)
    expect(store.status).toBe('verdict')
    expect(store.outcome).toMatchObject({ verdict: 'correct', firstAttempt: true, answer: { order }, roundDone: false })
    expect(store.outcome?.element.id).toBe('eSent00001')
    expect(store.outcome?.reveal).toMatchObject({ correct_text: 'My mother is a teacher.' })
    expect(store.progress).toEqual({ done: 1, total: 5 })
  })

  it('keeps the verdict and the explanation of the answer for the screen, until "Next"', async () => {
    const store = await started()
    expect(store.verdict).toBeNull()
    expect(store.reveal).toBeNull()
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ verdict: 'wrong' }))

    await store.answer({ order })

    expect(store.verdict).toBe('wrong')
    expect(store.reveal).toMatchObject({ correct_text: 'My mother is a teacher.' })
    store.continueAfterVerdict()
    expect(store.verdict).toBeNull()
    expect(store.reveal).toBeNull()
  })

  it('the next element waits behind "Next"; the summary follows the last one', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse())
    await store.answer({ order })
    expect(store.current?.id).toBe('eSent00002') // the session already holds it …
    const seq = store.elementSeq

    store.continueAfterVerdict() // … and "Next" shows it

    expect(store.status).toBe('ready')
    expect(store.outcome).toBeNull()
    expect(store.elementSeq).toBe(seq + 1)

    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: finishedSession() }))
    await store.answer({ order })
    expect(store.status).toBe('verdict')
    store.continueAfterVerdict()
    expect(store.status).toBe('finished')
  })

  it('"Next" does nothing when no verdict is waiting', async () => {
    const store = await started()

    store.continueAfterVerdict()

    expect(store.status).toBe('ready')
    expect(store.elementSeq).toBe(1)
  })

  it('"I do not know" sends skip', async () => {
    const store = await started(session({ mode: 'dictation' }))
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ verdict: 'skipped' }))

    await store.skip()

    expect(sent().answer).toEqual({ skip: true })
    expect(store.outcome?.verdict).toBe('skipped')
  })

  it('a typo is a verdict of its own', async () => {
    const store = await started(session({ mode: 'dictation' }))
    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({ verdict: 'typo', first_attempt: true, reveal: { expected: 'See you tomorrow', your_text: 'see you tomorow', diff: null, translation: null, explanation: null } }),
    )

    await store.answer({ text: 'see you tomorow' })

    expect(store.outcome?.verdict).toBe('typo')
    expect(store.status).toBe('verdict')
  })

  it.each(['verdict', 'submitting', 'finished', 'closed', 'error', 'idle', 'starting'] as const)(
    'ignores an answer while the status is %s',
    async (status) => {
      const store = await started()
      store.status = status

      await store.answer({ order })

      expect(api.practice.answer).not.toHaveBeenCalled()
    },
  )

  it('ignores an answer when there is no element', async () => {
    const store = usePracticeStore()

    await store.answer({ skip: true })

    expect(api.practice.answer).not.toHaveBeenCalled()
  })

  it('the time spent is telemetry: never negative, never more than ten minutes', async () => {
    const store = await started()
    store.elementShownAt = Date.now() + 60_000
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse())
    await store.answer({ order })
    expect(sent(0).time_spent_ms).toBe(0)

    store.status = 'ready'
    store.elementShownAt = Date.now() - 3 * 3_600_000
    await store.answer({ order })
    expect(sent(1).time_spent_ms).toBe(600_000)
  })

  describe('pairs', () => {
    const match = { left_id: 'lM1', right_id: 'rU2' }

    it('a match inside a round goes on in the same round: the board stays, with the new element', async () => {
      const store = await started(session({ mode: 'pairs' }))
      const seq = store.elementSeq
      vi.mocked(api.practice.answer).mockResolvedValue(
        answerResponse({
          verdict: 'correct',
          reveal: null,
          pair: match,
          round_done: false,
          session: session({ mode: 'pairs', current: pairsElement({ matched: [['lM1', 'rU2']] }) }),
        }),
      )

      await store.answer(match)

      expect(store.status).toBe('ready')
      expect(store.elementSeq).toBe(seq)
      expect(store.outcome).toMatchObject({ verdict: 'correct', pair: match, roundDone: false })
      expect((store.current as ReturnType<typeof pairsElement>).matched).toEqual([['lM1', 'rU2']])
    })

    it('a wrong match is a verdict too, and the round goes on', async () => {
      const store = await started(session({ mode: 'pairs' }))
      vi.mocked(api.practice.answer).mockResolvedValue(
        answerResponse({
          verdict: 'wrong',
          reveal: null,
          pair: match,
          session: session({ mode: 'pairs', current: pairsElement({ mismatches: 1 }), counters: { correct_first: 0, hints: 0, mismatches: 1 } }),
        }),
      )

      await store.answer(match)

      expect(store.status).toBe('ready')
      expect(store.outcome?.verdict).toBe('wrong')
      expect(store.session?.counters.mismatches).toBe(1)
    })

    it('the last match of a round stops at the verdict; "Next" opens the next round', async () => {
      const store = await started(session({ mode: 'pairs' }))
      const next = pairsElement({ id: 'ePair00002', round: 2 })
      vi.mocked(api.practice.answer).mockResolvedValue(
        answerResponse({ verdict: 'correct', reveal: null, pair: match, round_done: true, session: session({ mode: 'pairs', current: next, progress: { done: 1, total: 3 } }) }),
      )

      await store.answer(match)

      expect(store.status).toBe('verdict')
      expect(store.outcome).toMatchObject({ roundDone: true })
      store.continueAfterVerdict()
      expect(store.status).toBe('ready')
      expect(store.current?.id).toBe('ePair00002')
    })
  })
})

describe('practice store — a request that may be repeated', () => {
  const order = ['fW2', 'fT5']

  it('keeps the request id after a network error: the same answer is sent with the same id', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerResponse())

    await store.answer({ order })
    const first = store.pendingRequestId

    expect(first).toMatch(UUID)
    expect(store.status).toBe('ready') // the learner is still on the element, with their answer
    expect(store.actionError).toBe('Нет связи с сервером. Попробуйте ещё раз.')

    await store.answer({ order })

    expect(sent(1).request_id).toBe(sent(0).request_id)
    expect(sent(0).request_id).toBe(first)
    expect(store.status).toBe('verdict')
    expect(store.pendingRequestId).toBeNull() // the next answer is a new request
  })

  it.each([
    ['a server error', apiError('server', 500, 'server_error')],
    ['a rate limit', apiError('rate_limited', 429, 'too_many_requests')],
  ])('%s keeps it too (the server may have counted the answer)', async (_name, err) => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValueOnce(err).mockResolvedValueOnce(answerResponse())

    await store.answer({ order })
    await store.answer({ order })

    expect(sent(1).request_id).toBe(sent(0).request_id)
  })

  it('a different answer after the error is a new request (the first, if it landed, is answered by 409)', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(answerResponse())

    await store.answer({ order })
    await store.answer({ order: ['fT5', 'fW2'] })

    expect(sent(1).request_id).not.toBe(sent(0).request_id)
  })

  it('each answer has its own id', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse())

    await store.answer({ order })
    store.continueAfterVerdict()
    await store.answer({ order })

    expect(sent(1).request_id).not.toBe(sent(0).request_id)
  })

  it('a refusal of the server ends the request: the id is not kept', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('validation', 422, 'invalid_answer_shape'))

    await store.answer({ order })

    expect(store.pendingRequestId).toBeNull()
    expect(store.status).toBe('ready')
    expect(store.actionError).toBe('Не получилось отправить ответ. Обновите экран и попробуйте ещё раз.')
  })
})

describe('practice store — answers that the server does not take', () => {
  const order = ['fW2', 'fT5']

  it('element_not_current: another tab got ahead, the session is read again and the screen follows', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'element_not_current', { current: 'eSent00002' }))
    vi.mocked(api.practice.get).mockResolvedValue(session({ progress: { done: 1, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) }))
    const seq = store.elementSeq

    await store.answer({ order })

    expect(api.practice.get).toHaveBeenCalledTimes(2) // entering, and the re-read
    expect(store.status).toBe('ready')
    expect(store.current?.id).toBe('eSent00002')
    expect(store.elementSeq).toBe(seq + 1)
    expect(store.actionError).toBe('Задание уже сменилось. Экран обновлён.')
    expect(store.pendingRequestId).toBeNull()
  })

  it('element_not_current with the same element keeps the component (what the learner did stays)', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'element_not_current'))
    const seq = store.elementSeq

    await store.answer({ order })

    expect(store.status).toBe('ready')
    expect(store.elementSeq).toBe(seq)
  })

  it('pair_already_matched: a silent re-read (a double tap is not worth a message)', async () => {
    const store = await started(session({ mode: 'pairs' }))
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'pair_already_matched', { left_id: 'lM1' }))
    vi.mocked(api.practice.get).mockResolvedValue(session({ mode: 'pairs', current: pairsElement({ matched: [['lM1', 'rU2']] }) }))

    await store.answer({ left_id: 'lM1', right_id: 'rU2' })

    expect(store.status).toBe('ready')
    expect(store.actionError).toBe('')
    expect((store.current as ReturnType<typeof pairsElement>).matched).toHaveLength(1)
  })

  it('answer_already_accepted: shows the verdict that was counted, and moves to what the server has now', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'wrong' }))
    vi.mocked(api.practice.get).mockResolvedValue(session({ progress: { done: 1, total: 5 }, current: sentenceElement({ id: 'eSent00002' }) }))

    await store.answer({ order })

    expect(store.status).toBe('verdict')
    expect(store.outcome).toMatchObject({ verdict: 'wrong', reveal: null })
    expect(store.outcome?.element.id).toBe('eSent00001')
    store.continueAfterVerdict()
    expect(store.current?.id).toBe('eSent00002')
  })

  it('answer_already_accepted without a readable verdict just follows the server', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'amazing' }))
    vi.mocked(api.practice.get).mockResolvedValue(session({ current: sentenceElement({ id: 'eSent00002' }) }))

    await store.answer({ order })

    expect(store.status).toBe('ready')
    expect(store.current?.id).toBe('eSent00002')
  })

  it('a re-read that fails ends in the error state, not in a stuck "submitting"', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'element_not_current'))
    vi.mocked(api.practice.get).mockRejectedValue(offline())

    await store.answer({ order })

    expect(store.status).toBe('error')
  })

  it('410: the session is over', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('gone', 410, 'practice_session_closed', { finish_reason: 'expired' }))

    await store.answer({ order })

    expect(store.status).toBe('closed')
  })

  it('404: the session is gone', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('not_found', 404, 'practice_session_not_found'))

    await store.answer({ order })

    expect(store.status).toBe('error')
    expect(store.errorMessage).toBe('Эта практика не найдена.')
  })

  it('403 practice_pro_required: the paywall opens over the session, which stays as it is', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))

    await store.answer({ order })

    expect(store.paywall).toBe(true)
    expect(store.status).toBe('ready')
    expect(store.session?.id).toBe(77)
  })

  it('422 unknown_element_id: the learner can still answer', async () => {
    const store = await started()
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('validation', 422, 'unknown_element_id'))

    await store.answer({ order })

    expect(store.status).toBe('ready')
    expect(store.actionError).toBe('Ответ не относится к этому заданию. Обновите экран.')
  })
})

describe('practice store — replacing a dictation without sound', () => {
  it('swaps the element, gives the clip back and sends a request id', async () => {
    const store = await started(session({ mode: 'dictation' }))
    store.audio.blobUrl = 'blob:old'
    store.audio.state = 'failed'
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    vi.mocked(api.practice.replace).mockResolvedValue(
      session({ mode: 'dictation', current: dictationElement({ id: 'eDict00002', replacements_left: 1 }) }),
    )
    const seq = store.elementSeq

    expect(await store.replace()).toBe(true)

    const body = vi.mocked(api.practice.replace).mock.calls[0]![1]
    expect(body).toMatchObject({ element_id: 'eDict00001', reason: 'audio_unavailable' })
    expect(body.request_id).toMatch(UUID)
    expect(store.current?.id).toBe('eDict00002')
    expect(store.elementSeq).toBe(seq + 1)
    expect(store.status).toBe('ready')
    expect(revoke).toHaveBeenCalledWith('blob:old')
    expect(store.audio).toEqual({ state: 'idle', blobUrl: null })
    revoke.mockRestore()
  })

  it('has nothing to replace in the other modes', async () => {
    const store = await started(session({ mode: 'sentence' }))

    expect(await store.replace()).toBe(false)

    expect(api.practice.replace).not.toHaveBeenCalled()
  })

  it('no replacements left (409): the learner stays, and is told', async () => {
    const store = await started(session({ mode: 'dictation' }))
    vi.mocked(api.practice.replace).mockRejectedValue(apiError('conflict', 409, 'replace_not_allowed', { reason: 'limit' }))

    expect(await store.replace()).toBe(false)

    expect(store.status).toBe('ready')
    expect(store.actionError).toBe('Заменить задание больше нельзя.')
    expect(store.current?.id).toBe('eDict00001')
  })

  it('after a network error the same replacement is repeated with the same id', async () => {
    const store = await started(session({ mode: 'dictation' }))
    vi.mocked(api.practice.replace).mockRejectedValueOnce(offline()).mockResolvedValueOnce(session({ mode: 'dictation', current: dictationElement({ id: 'eDict00002' }) }))

    expect(await store.replace()).toBe(false)
    expect(store.status).toBe('ready')
    expect(await store.replace()).toBe(true)

    const [first, second] = vi.mocked(api.practice.replace).mock.calls.map((call) => call[1].request_id)
    expect(second).toBe(first)
  })
})

describe('practice store — after the summary', () => {
  it('"Repeat mistakes" opens a session out of the mistakes of this one', async () => {
    const store = await started(finishedSession({ mode: 'dictation' }))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 91, mode: 'dictation' }))

    expect(await store.retry()).toBe(91)

    expect(api.practice.open).toHaveBeenCalledWith({
      mode: 'dictation',
      course: 'english-a1',
      lesson_id: 41,
      source: 'retry',
      retry_of: 77,
    })
  })

  it('a course-level session is repeated at the course level', async () => {
    const store = await started(finishedSession({ lesson: null }))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 91 }))

    await store.retry()

    expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ lesson_id: null })
  })

  it('"Again" is a new session of the same mode in the same place, not a retry', async () => {
    const store = await started(finishedSession({ mode: 'collocation' }))
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 92, mode: 'collocation' }))

    expect(await store.again()).toBe(92)

    const body = vi.mocked(api.practice.open).mock.calls[0]![0]
    expect(body).toMatchObject({ mode: 'collocation', course: 'english-a1', lesson_id: 41, source: 'hub' })
    expect(body.retry_of).toBeUndefined()
  })

  it('nothing to repeat (409): the retry gives way to "Again", and the learner is told', async () => {
    const store = await started(finishedSession())
    vi.mocked(api.practice.open).mockRejectedValue(apiError('conflict', 409, 'retry_not_available'))

    expect(await store.retry()).toBeNull()

    expect(store.retryUnavailable).toBe(true)
    expect(store.openError).toBe('Повторить ошибки не получится: их слишком мало или нет совсем.')
  })

  it('cannot repeat a session that is not finished', async () => {
    const store = await started(session())

    expect(await store.retry()).toBeNull()
    expect(await store.again()).toBeNull()
    expect(api.practice.open).not.toHaveBeenCalled()
  })

  it('a new session forgets that the retry was refused', async () => {
    const store = await started(finishedSession())
    vi.mocked(api.practice.open).mockRejectedValueOnce(apiError('conflict', 409, 'retry_not_available')).mockResolvedValueOnce(session({ id: 93 }))
    await store.retry()
    expect(store.retryUnavailable).toBe(true)

    await store.again()

    expect(store.retryUnavailable).toBe(false)
  })
})

describe('practice store — the hub the learner comes back to', () => {
  /** A store with the hub of the lesson read (an unfinished session `77` of pairs, 1 of 3) and the session `77` entered. */
  async function withHub(s = session({ mode: 'pairs', progress: { done: 1, total: 3 } })) {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ active_session: activeSession({ id: 77, progress: { done: 1, total: 3 } }) }))
    const store = await started(s)
    await store.loadHub('english-a1', 41)
    return store
  }

  it('shows the session that was just opened as the one to continue', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub())
    const store = usePracticeStore()
    await store.loadHub('english-a1', 41)
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 90, mode: 'dictation', progress: { done: 0, total: 5 } }))

    await store.open({ mode: 'dictation', course: 'english-a1', lesson_id: 41, source: 'hub' })

    expect(store.hub?.active_session).toEqual({ id: 90, mode: 'dictation', course: 'english-a1', lesson_id: 41, progress: { done: 0, total: 5 } })
  })

  it('follows the progress of the session as the answers come', async () => {
    const store = await withHub(session({ id: 77, mode: 'sentence', progress: { done: 1, total: 5 } }))
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: session({ id: 77, progress: { done: 2, total: 5 } }) }))

    await store.answer({ order: ['fW2'] })

    expect(store.hub?.active_session?.progress).toEqual({ done: 2, total: 5 })
  })

  it('drops the session from the hub when it is over, so that no "Continue" is left behind', async () => {
    const store = await withHub(session({ id: 77, mode: 'sentence', progress: { done: 4, total: 5 } }))
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: finishedSession({ id: 77 }) }))

    await store.answer({ order: ['fW2'] })

    expect(store.hub?.active_session).toBeNull()
  })

  it('leaves the card of another session alone', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ active_session: activeSession({ id: 99 }) }))
    const store = await started(session({ id: 77 }))
    await store.loadHub('english-a1', 41)
    vi.mocked(api.practice.answer).mockResolvedValue(answerResponse({ session: finishedSession({ id: 77 }) }))

    await store.answer({ order: ['fW2'] })

    expect(store.hub?.active_session?.id).toBe(99)
  })
})

describe('practice store — a new session from the summary', () => {
  it('has no sheet to ask with: an unfinished session of another tab is told in a sentence', async () => {
    const store = await started(finishedSession())
    vi.mocked(api.practice.open).mockRejectedValue(
      apiError('conflict', 409, 'practice_session_active', { session_id: 91, mode: 'pairs', course: 'english-a1', lesson_id: 41, progress: { done: 0, total: 3 } }),
    )

    expect(await store.retry()).toBeNull()
    expect(store.conflict).toBeNull()
    expect(store.openError).toBe('У вас уже есть незавершённая практика.')

    store.clearOpenError()
    expect(await store.again()).toBeNull()
    expect(store.conflict).toBeNull()
    expect(store.openError).toBe('У вас уже есть незавершённая практика.')
  })
})

describe('practice store — the clip of the element', () => {
  it('is given back when another session is entered', async () => {
    const store = await started(session({ mode: 'dictation' }))
    store.audio.blobUrl = 'blob:clip'
    store.audio.state = 'ready'
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})

    vi.mocked(api.practice.get).mockResolvedValue(session({ id: 81 }))
    await store.load(81)

    expect(revoke).toHaveBeenCalledWith('blob:clip')
    expect(store.audio).toEqual({ state: 'idle', blobUrl: null })
    revoke.mockRestore()
  })
})
