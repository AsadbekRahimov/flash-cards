import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { answerKey, usePracticeStore } from '../practice'
import {
  errorHuntElement,
  errorHuntFixElement,
  errorHuntReveal,
  finishedSession,
  session,
  wordFormElement,
  wordFormReveal,
} from '@/api/__tests__/practiceFixtures'
import type { ErrorHuntLocateReveal, PracticeAnswerResponse, PracticeSession } from '@/api/types'

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

async function started(s: PracticeSession) {
  vi.mocked(api.practice.get).mockResolvedValue(s)
  const store = usePracticeStore()
  await store.load(s.id)
  return store
}

const huntSession = (overrides: Partial<PracticeSession> = {}): PracticeSession => session({ mode: 'error_hunt', current: errorHuntElement(), ...overrides })

const locateReveal = (ok = false): ErrorHuntLocateReveal => ({ step: 'locate', located_ok: ok, your_token_id: ok ? 'tOn4' : 'tAm1', error_token_id: 'tOn4' })

/** The reply of the step `locate` (contract: `element_done: false`, `session.current` is the same element at the step `fix`). */
const locateReply = (tokenId = 'tAm1'): PracticeAnswerResponse => ({
  verdict: tokenId === 'tOn4' ? 'correct' : 'wrong',
  first_attempt: true,
  reveal: locateReveal(tokenId === 'tOn4'),
  pair: null,
  element_done: false,
  session: huntSession({ current: errorHuntFixElement(tokenId) }),
})

/** The reply of the step `fix`: the element is done, the next one is on (or the summary). */
const fixReply = (overrides: Partial<PracticeAnswerResponse> = {}): PracticeAnswerResponse => ({
  verdict: 'wrong',
  first_attempt: true,
  reveal: errorHuntReveal(),
  pair: null,
  element_done: true,
  session: huntSession({ progress: { done: 1, total: 5 }, current: errorHuntElement({ id: 'eHunt00002' }) }),
  ...overrides,
})

describe('practice store — the key of an answer', () => {
  it('is the element for a one-answer kind and the element with its step for an "Error hunt"', () => {
    expect(answerKey(wordFormElement())).toBe('eWord00001')
    expect(answerKey(errorHuntElement())).toBe('eHunt00001:locate')
    expect(answerKey(errorHuntFixElement())).toBe('eHunt00001:fix')
  })
})

describe('practice store — "Error hunt" in two steps', () => {
  it('the first step leaves the element on screen at its second step: ready, the same elementSeq, the reply kept', async () => {
    const store = await started(huntSession())
    const seq = store.elementSeq
    vi.mocked(api.practice.answer).mockResolvedValue(locateReply('tAm1'))

    await store.answer({ token_id: 'tAm1' })

    expect(sent().answer).toEqual({ token_id: 'tAm1' })
    expect(sent().element_id).toBe('eHunt00001')
    expect(sent().request_id).toMatch(UUID)
    expect(store.status).toBe('ready')
    expect(store.elementSeq).toBe(seq) // the board keeps its marks
    expect(store.current).toEqual(errorHuntFixElement('tAm1'))
    expect(store.outcome?.reveal).toEqual(locateReveal(false))
    expect(store.outcome?.verdict).toBe('wrong')
    expect(store.pendingRequestId).toBeNull()
  })

  it('the second step has a request id of its own and ends with the verdict', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer).mockResolvedValueOnce(locateReply('tOn4')).mockResolvedValueOnce(fixReply({ verdict: 'correct' }))

    await store.answer({ token_id: 'tOn4' })
    await store.answer({ option_id: 'oIn2' })

    expect(sent(1).answer).toEqual({ option_id: 'oIn2' })
    expect(sent(1).element_id).toBe('eHunt00001')
    expect(sent(1).request_id).toMatch(UUID)
    expect(sent(1).request_id).not.toBe(sent(0).request_id)
    expect(store.status).toBe('verdict')
    expect(store.verdict).toBe('correct')
    expect(store.reveal).toEqual(errorHuntReveal())
    // The element answered is the second step (its words and corrections are what the review names).
    expect(store.outcome?.element).toEqual(errorHuntFixElement('tOn4'))

    const seq = store.elementSeq
    store.continueAfterVerdict()
    expect(store.status).toBe('ready')
    expect(store.elementSeq).toBe(seq + 1)
    expect(store.current?.id).toBe('eHunt00002')
  })

  it('the last element of the session goes to the summary after its second step', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer)
      .mockResolvedValueOnce(locateReply())
      .mockResolvedValueOnce(fixReply({ session: finishedSession({ mode: 'error_hunt' }) }))

    await store.answer({ token_id: 'tAm1' })
    await store.answer({ option_id: 'oIn2' })
    expect(store.status).toBe('verdict')
    store.continueAfterVerdict()
    expect(store.status).toBe('finished')
  })

  it('a skip on the first step ends the element (element_done: true)', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer).mockResolvedValue(
      fixReply({ verdict: 'skipped', reveal: errorHuntReveal({ located_ok: false, fixed_ok: false, your_token_id: null, your_option_id: null }) }),
    )

    await store.skip()

    expect(sent().answer).toEqual({ skip: true })
    expect(store.status).toBe('verdict')
    expect(store.verdict).toBe('skipped')
  })

  it('a lost reply of the first step is repeated with the same id and the same word, whatever is handed over', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockResolvedValueOnce(locateReply('tAm1'))

    await store.answer({ token_id: 'tAm1' })
    expect(store.status).toBe('ready')
    expect(store.actionError).not.toBe('')

    await store.answer({ token_id: 'tVery2' })
    expect(sent(1).request_id).toBe(sent(0).request_id)
    expect(sent(1).answer).toEqual({ token_id: 'tAm1' })
    expect(store.current).toEqual(errorHuntFixElement('tAm1'))
  })

  it('a lost reply of the second step is repeated with the id of the second step, not of the first', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer)
      .mockResolvedValueOnce(locateReply('tAm1'))
      .mockRejectedValueOnce(offline())
      .mockResolvedValueOnce(fixReply())

    await store.answer({ token_id: 'tAm1' })
    await store.answer({ option_id: 'oFor3' })
    await store.answer({ option_id: 'oIn2' })

    expect(sent(2).request_id).toBe(sent(1).request_id)
    expect(sent(2).request_id).not.toBe(sent(0).request_id)
    expect(sent(2).answer).toEqual({ option_id: 'oFor3' })
    expect(store.status).toBe('verdict')
  })

  it('step_not_current (another tab took the step): the session is read again, the screen follows it, the toast says why', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'step_not_current', { step: 'fix' }))
    vi.mocked(api.practice.get).mockResolvedValue(huntSession({ current: errorHuntFixElement('tOn4') }))
    const seq = store.elementSeq

    await store.answer({ token_id: 'tAm1' })

    expect(api.practice.get).toHaveBeenCalledTimes(2) // the load, and the re-read
    expect(store.status).toBe('ready')
    expect(store.current).toEqual(errorHuntFixElement('tOn4'))
    expect(store.elementSeq).toBe(seq) // the same element; the board turns by its step
    expect(store.actionError).toBe('Задание уже продвинулось в другой вкладке. Экран обновлён.')
    expect(store.pendingRequestId).toBeNull()
  })

  it('step_not_current with the server still at the step that was sent: the board starts the step afresh', async () => {
    const store = await started(huntSession())
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'step_not_current', { step: 'locate' }))
    vi.mocked(api.practice.get).mockResolvedValue(huntSession())
    const seq = store.elementSeq

    await store.answer({ token_id: 'tAm1' })

    expect(store.status).toBe('ready')
    expect(store.elementSeq).toBe(seq + 1)
  })

  it('step_not_current in Uzbek', async () => {
    setLocale('uz')
    const store = await started(huntSession())
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'step_not_current', { step: 'fix' }))
    vi.mocked(api.practice.get).mockResolvedValue(huntSession({ current: errorHuntFixElement('tOn4') }))

    await store.answer({ token_id: 'tAm1' })

    expect(store.actionError).toBe('Topshiriq boshqa oynada davom etgan. Ekran yangilandi.')
  })

  it('answer_already_accepted at the second step: the verdict that was counted, as for any element', async () => {
    const store = await started(huntSession({ current: errorHuntFixElement('tAm1') }))
    vi.mocked(api.practice.answer).mockRejectedValue(apiError('conflict', 409, 'answer_already_accepted', { verdict: 'wrong' }))
    vi.mocked(api.practice.get).mockResolvedValue(huntSession({ progress: { done: 1, total: 5 }, current: errorHuntElement({ id: 'eHunt00002' }) }))

    await store.answer({ option_id: 'oIn2' })

    expect(store.status).toBe('verdict')
    expect(store.outcome?.earlier).toBe(true)
    expect(store.verdict).toBe('wrong')
  })

  it('a reload between the steps: the session read brings the second step back', async () => {
    const store = await started(huntSession({ current: errorHuntFixElement('tAm1') }))

    expect(store.status).toBe('ready')
    expect(store.current).toEqual(errorHuntFixElement('tAm1'))
  })
})

describe('practice store — "Word form"', () => {
  it('one answer per element: the text, then the verdict', async () => {
    const store = await started(session({ mode: 'word_form', current: wordFormElement() }))
    vi.mocked(api.practice.answer).mockResolvedValue({
      verdict: 'wrong',
      first_attempt: true,
      reveal: wordFormReveal({ your_text: 'decide', same_as_base: true }),
      pair: null,
      session: session({ mode: 'word_form', progress: { done: 1, total: 5 }, current: wordFormElement({ id: 'eWord00002' }) }),
    })

    await store.answer({ text: 'decide' })

    expect(sent().answer).toEqual({ text: 'decide' })
    expect(store.status).toBe('verdict')
    expect(store.reveal).toEqual(wordFormReveal({ your_text: 'decide', same_as_base: true }))
  })

  it('a lost reply repeats the same text under the same id', async () => {
    const store = await started(session({ mode: 'word_form', current: wordFormElement() }))
    vi.mocked(api.practice.answer).mockRejectedValueOnce(offline()).mockRejectedValueOnce(offline())

    await store.answer({ text: 'decision' })
    await store.answer({ text: 'decisions' })

    expect(sent(1).request_id).toBe(sent(0).request_id)
    expect(sent(1).answer).toEqual({ text: 'decision' })
  })
})

describe('practice store — a mode this build does not know', () => {
  it('does not offer an unfinished session of an unknown mode by name: the refusal is told in words', async () => {
    const store = usePracticeStore()
    vi.mocked(api.practice.open).mockRejectedValue(
      apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'speed_round', course: 'english-a1', lesson_id: 41, progress: { done: 0, total: 3 } }),
    )

    expect(await store.open({ mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'hub' })).toBeNull()

    expect(store.conflict).toBeNull()
    expect(store.openError).not.toBe('')
  })

  it('opens a session of a new mode by its name', async () => {
    const store = usePracticeStore()
    vi.mocked(api.practice.open).mockRejectedValue(
      apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'error_hunt', course: 'english-a1', lesson_id: 41, progress: { done: 1, total: 3 } }),
    )

    await store.open({ mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'hub' })

    expect(store.conflict?.mode).toBe('error_hunt')
  })
})
