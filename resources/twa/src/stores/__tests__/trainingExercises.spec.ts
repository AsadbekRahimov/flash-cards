import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore } from '../training'
import { ApiClientError } from '@/api/client'
import { api } from '@/api'
import type { Card, ReviewResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    training: {
      open: vi.fn(),
      start: vi.fn(),
      next: vi.fn(),
      review: vi.fn(),
      answer: vi.fn(),
      finish: vi.fn(),
    },
    words: {
      saveNote: vi.fn(),
    },
  },
}))

const card = (overrides: Partial<Card>): Card => ({
  word_id: 7,
  word: 'resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: null,
  ...overrides,
})

const spelling = card({
  prompt: { type: 'spelling', front: 'Устойчивый', masked_example: null, options: null, answer_length: 9 },
})

const reviewed = (overrides: Partial<ReviewResponse> = {}): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 3,
  easiness_factor: 2.5,
  graded: null,
  card: card({ word_id: 8 }),
  progress: { done: 1, total: 3 },
  ...overrides,
})

/** The answer to the pick of a four-choice card: the server's verdict is always there (the card has no right option in it). */
const choiceReviewed = (overrides: Partial<ReviewResponse> = {}): ReviewResponse =>
  reviewed({ graded: { quality: 2, correct: false, expected: 'resilient' }, ...overrides })

async function startWith(first: Card): Promise<ReturnType<typeof useTrainingStore>> {
  const store = useTrainingStore()
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1,
    mode: 'lesson',
    lesson: { stage: 1, lesson: 1, title: null },
    total_words: 3,
    card: first,
    progress: { done: 0, total: 3 },
  })
  await store.start(1)
  return store
}

describe('training store — exercises', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reads the exercise from the prompt; a card without one is a flash card', async () => {
    const store = await startWith(card({}))
    expect(store.exercise).toBe('flash')
    store.applyCard(spelling)
    expect(store.exercise).toBe('spelling')
  })

  it('sends a typed answer, holds the next card until the verdict is seen', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockResolvedValueOnce(
      reviewed({ graded: { quality: 4, correct: true, expected: 'resilient' } }),
    )

    await store.submitAnswer('resiliant')

    expect(api.training.answer).toHaveBeenCalledWith(1, 7, 'resiliant', expect.any(Number), null, 'spelling')
    expect(api.training.review).not.toHaveBeenCalled()
    expect(store.status).toBe('verdict')
    expect(store.verdict).toEqual({ quality: 4, correct: true, expected: 'resilient' })
    expect(store.card?.word_id).toBe(7) // still the graded card: nothing new on screen yet
    expect(store.reviewedCount).toBe(1)
    expect(store.lastIntervalDays).toBe(3)
    expect(store.progress).toEqual({ done: 1, total: 3 })

    store.continueAfterVerdict()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.verdict).toBeNull()
    expect(store.deferred).toBeNull()
  })

  it('finishes after the verdict when the review answered with no card', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockResolvedValueOnce(
      reviewed({ graded: { quality: 1, correct: false, expected: 'resilient' }, card: null, done_reason: 'lesson_complete' }),
    )

    await store.submitAnswer('')
    expect(store.status).toBe('verdict')
    store.continueAfterVerdict()
    expect(store.status).toBe('finished')
    expect(store.doneReason).toBe('lesson_complete')
  })

  it('continueAfterVerdict is a no-op outside the verdict state', async () => {
    const store = await startWith(spelling)
    store.continueAfterVerdict()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(7)
  })

  it('echoes the learn-ahead serve_token with a typed answer', async () => {
    const store = await startWith(card({ ...spelling, card_kind: 'learning', serve_token: 'ticket-9' }))
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 5, correct: true, expected: 'resilient' } }))
    await store.submitAnswer('resilient')
    expect(vi.mocked(api.training.answer).mock.calls[0]?.[4]).toBe('ticket-9')
  })

  it.each(['answer_not_accepted', 'quality_not_accepted', 'exercise_pro_required'])(
    'reloads the card when the server expects the other kind of grade (%s)',
    async (code) => {
      const store = await startWith(spelling)
      vi.mocked(api.training.answer).mockRejectedValueOnce(new ApiClientError('validation', 422, code, 'x'))
      vi.mocked(api.training.next).mockResolvedValueOnce({ card: card({ word_id: 7 }), progress: { done: 0, total: 3 } })

      await store.submitAnswer('whatever')

      expect(api.training.next).toHaveBeenCalledTimes(1)
      expect(store.status).toBe('ready')
      expect(store.exercise).toBe('flash')
      expect(store.reviewedCount).toBe(0)
    },
  )

  it('a client-graded review with graded: null goes straight to the next card', async () => {
    const store = await startWith(card({ prompt: { type: 'reverse', front: 'Устойчивый', masked_example: null, options: ['a', 'resilient', 'c', 'd'], answer_length: null } }))
    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())

    await store.submitReview(4)

    expect(api.training.review).toHaveBeenCalledWith(1, 7, 4, expect.any(Number), null, 'reverse')
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.verdict).toBeNull()
  })

  it('a flash grade shows the card of the answer at once: nothing is held back', async () => {
    const store = await startWith(card({}))
    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())

    await store.submitReview(5)

    expect(store.status).toBe('ready')
    expect(store.deferred).toBeNull()
    expect(store.card?.word_id).toBe(8)
  })

  it('counts every card the server serves, the same word again included', async () => {
    const store = await startWith(card({}))
    expect(store.serveSeq).toBe(1)

    // A failed learning card served ahead at once: the same word_id, a new serve.
    vi.mocked(api.training.review).mockResolvedValue(reviewed({ card: card({ serve_token: 'ticket-2', card_kind: 'learning' }) }))
    await store.submitReview(2)
    expect(store.card?.word_id).toBe(7)
    expect(store.serveSeq).toBe(2)

    // The card of a verdict counts when the learner moves on to it, not when the review answers.
    const typed = await startWith(spelling)
    const before = typed.serveSeq
    vi.mocked(api.training.answer).mockResolvedValue(
      reviewed({ graded: { quality: 5, correct: true, expected: 'resilient' }, card: card({ word_id: 9 }) }),
    )
    await typed.submitAnswer('resilient')
    expect(typed.serveSeq).toBe(before)
    typed.continueAfterVerdict()
    expect(typed.serveSeq).toBe(before + 1)
  })

  it('does not count the end of the session as a serve', async () => {
    const store = await startWith(card({}))
    vi.mocked(api.training.review).mockResolvedValue(reviewed({ card: null, done_reason: 'lesson_complete' }))

    await store.submitReview(4)

    expect(store.status).toBe('finished')
    expect(store.serveSeq).toBe(1)
  })

  it('resets the verdict with the session', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 5, correct: true, expected: 'resilient' } }))
    await store.submitAnswer('resilient')
    expect(store.status).toBe('verdict')

    await store.start(2)
    expect(store.verdict).toBeNull()
    expect(store.deferred).toBeNull()
    expect(store.status).toBe('ready')
  })
})

describe('training store — four-choice cards: the grade goes at the pick, the next card is held back', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const reverse = card({
    serve_token: 'ticket-7',
    prompt: { type: 'reverse', front: 'Устойчивый', masked_example: null, options: ['a', 'resilient', 'c', 'd'], answer_length: null },
  })

  /** A review whose answer the test settles by hand, so it can come before or after the learner moves on. */
  function pendingReview() {
    let resolve: (value: ReviewResponse) => void = () => {}
    let reject: (reason: unknown) => void = () => {}
    vi.mocked(api.training.answer).mockReturnValueOnce(
      new Promise<ReviewResponse>((res, rej) => {
        resolve = res
        reject = rej
      }),
    )
    return { resolve, reject }
  }

  it('sends the picked option at once (the server grades it), with the ticket of the card it was made on, and holds back the card of the answer', async () => {
    const store = await startWith(reverse)
    vi.mocked(api.training.answer).mockResolvedValueOnce(choiceReviewed({ new_interval_days: 0 }))

    await store.submitChoice('c')

    expect(api.training.answer).toHaveBeenCalledTimes(1)
    expect(api.training.answer).toHaveBeenCalledWith(1, 7, 'c', expect.any(Number), 'ticket-7', 'reverse')
    expect(store.status).toBe('held')
    // Nothing new on screen yet: the learner is still looking at the right answer of this card.
    expect(store.card?.word_id).toBe(7)
    expect(store.serveSeq).toBe(1)
    expect(store.verdict).toBeNull()
    expect(store.deferred).toEqual({ card: expect.objectContaining({ word_id: 8 }), doneReason: null })
    // What the answer says about the session is already true: the grade counted.
    expect(store.reviewedCount).toBe(1)
    expect(store.progress).toEqual({ done: 1, total: 3 })
  })

  it('continueAfterChoice shows the held card, sends nothing more, and a second call changes nothing', async () => {
    const store = await startWith(reverse)
    vi.mocked(api.training.answer).mockResolvedValueOnce(choiceReviewed())
    await store.submitChoice('c')

    store.continueAfterChoice()

    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.serveSeq).toBe(2)
    expect(store.deferred).toBeNull()

    store.continueAfterChoice()
    expect(store.card?.word_id).toBe(8)
    expect(store.serveSeq).toBe(2)
    expect(api.training.answer).toHaveBeenCalledTimes(1)
    expect(api.training.next).not.toHaveBeenCalled()
  })

  it('"Next" before the answer came back: the card is shown the moment the answer arrives, nothing is sent twice', async () => {
    const store = await startWith(reverse)
    const answer = pendingReview()
    const sending = store.submitChoice('c')
    expect(store.status).toBe('submitting')

    store.continueAfterChoice()
    store.continueAfterChoice()
    // Still waiting: the answered card stays.
    expect(store.status).toBe('submitting')
    expect(store.card?.word_id).toBe(7)

    answer.resolve(choiceReviewed())
    await sending

    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.serveSeq).toBe(2)
    expect(store.advanceWhenAnswered).toBe(false)
    expect(api.training.answer).toHaveBeenCalledTimes(1)
  })

  it('without a "Next" the answer that comes back stays held', async () => {
    const store = await startWith(reverse)
    const answer = pendingReview()
    const sending = store.submitChoice('resilient')

    answer.resolve(choiceReviewed())
    await sending

    expect(store.status).toBe('held')
    expect(store.card?.word_id).toBe(7)
  })

  it('is no use pressing "Next" on a card nobody has answered', async () => {
    const store = await startWith(reverse)

    store.continueAfterChoice()
    expect(store.status).toBe('ready')
    expect(store.advanceWhenAnswered).toBe(false)
    expect(store.card?.word_id).toBe(7)
  })

  it('does not send a second grade for a card whose grade is on its way or held', async () => {
    const store = await startWith(reverse)
    const answer = pendingReview()
    const first = store.submitChoice('c')

    await store.submitChoice('resilient')
    expect(api.training.answer).toHaveBeenCalledTimes(1)

    answer.resolve(choiceReviewed())
    await first
    expect(store.status).toBe('held')

    await store.submitChoice('resilient')
    expect(api.training.answer).toHaveBeenCalledTimes(1)
  })

  it('ends the session after "Next" when the held answer had no card', async () => {
    const store = await startWith(reverse)
    vi.mocked(api.training.answer).mockResolvedValueOnce(choiceReviewed({ card: null, done_reason: 'lesson_complete' }))
    await store.submitChoice('c')

    // The grade is saved, but the end of the session waits until the learner has read the answer.
    expect(store.status).toBe('held')
    expect(store.doneReason).toBeNull()

    store.continueAfterChoice()

    expect(store.status).toBe('finished')
    expect(store.doneReason).toBe('lesson_complete')
    expect(store.serveSeq).toBe(1)
  })

  it('an older server that sends no card: "Next" asks /next for it', async () => {
    const store = await startWith(reverse)
    const { card: _unused, ...withoutCard } = choiceReviewed()
    vi.mocked(api.training.answer).mockResolvedValueOnce(withoutCard)
    vi.mocked(api.training.next).mockResolvedValueOnce({ card: card({ word_id: 9 }), progress: { done: 1, total: 3 } })
    await store.submitChoice('resilient')

    expect(store.status).toBe('held')
    expect(store.deferred).toBeNull()
    expect(api.training.next).not.toHaveBeenCalled()

    store.continueAfterChoice()
    await vi.waitFor(() => expect(store.status).toBe('ready'))

    expect(api.training.next).toHaveBeenCalledTimes(1)
    expect(store.card?.word_id).toBe(9)
  })

  it('a grade that fails goes the usual error way, and the held-back "Next" does not outlive it', async () => {
    const store = await startWith(reverse)
    const answer = pendingReview()
    const sending = store.submitChoice('c')
    store.continueAfterChoice()

    answer.reject(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
    await sending

    expect(store.status).toBe('error')
    expect(store.reviewedCount).toBe(0)
    expect(store.card?.word_id).toBe(7)

    // "Retry" asks the server for the card again (the grade was not counted); the next pick is a clean start.
    vi.mocked(api.training.next).mockResolvedValueOnce({ card: reverse, progress: { done: 0, total: 3 } })
    await store.loadNext()
    expect(store.status).toBe('ready')
    vi.mocked(api.training.answer).mockResolvedValueOnce(choiceReviewed())
    await store.submitChoice('resilient')
    expect(store.status).toBe('held')
  })

  it('reloads the card when the server no longer accepts this serve', async () => {
    const store = await startWith(reverse)
    vi.mocked(api.training.answer).mockRejectedValueOnce(new ApiClientError('conflict', 409, 'card_not_due', 'Card is not due.'))
    vi.mocked(api.training.next).mockResolvedValueOnce({ card: card({ word_id: 8 }), progress: { done: 0, total: 3 } })

    await store.submitChoice('resilient')

    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.reviewedCount).toBe(0)
  })

  it('drops an answer that comes back for an earlier session', async () => {
    const store = await startWith(reverse)
    const answer = pendingReview()
    const sending = store.submitChoice('c')

    // The learner left and started another session while the grade was on its way.
    vi.mocked(api.training.start).mockResolvedValueOnce({
      session_id: 2,
      mode: 'daily_review',
      lesson: null,
      total_words: 10,
      card: card({ word_id: 21 }),
      progress: { done: 0, total: 10 },
    })
    await store.start(2)
    expect(store.status).toBe('ready')

    answer.resolve(choiceReviewed({ card: card({ word_id: 99 }), progress: { done: 5, total: 5 } }))
    await sending

    expect(store.sessionId).toBe(2)
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(21)
    expect(store.progress).toEqual({ done: 0, total: 10 })
    expect(store.reviewedCount).toBe(0)
    expect(store.deferred).toBeNull()
  })

  it('drops a failure that comes back for an earlier session as well', async () => {
    const store = await startWith(reverse)
    const answer = pendingReview()
    const sending = store.submitChoice('c')
    vi.mocked(api.training.start).mockResolvedValueOnce({
      session_id: 2, mode: 'daily_review', lesson: null, total_words: 10, card: card({ word_id: 21 }), progress: { done: 0, total: 10 },
    })
    await store.start(2)

    answer.reject(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
    await sending

    expect(store.status).toBe('ready')
    expect(store.errorMessage).toBe('')
  })

  it('forgets the held answer and a pending "Next" with the session', async () => {
    const store = await startWith(reverse)
    vi.mocked(api.training.answer).mockResolvedValueOnce(choiceReviewed())
    await store.submitChoice('resilient')
    expect(store.status).toBe('held')

    await store.start(2)

    expect(store.deferred).toBeNull()
    expect(store.advanceWhenAnswered).toBe(false)
    expect(store.status).toBe('ready')
  })
})

describe('training store — the method (docs/06 §12)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const reverse = card({
    prompt: { type: 'reverse', front: 'Устойчивый', masked_example: null, options: ['a', 'resilient', 'c', 'd'], answer_length: null },
  })

  it('does not show a verdict screen for a four-option card even though the server grades it', async () => {
    const store = await startWith(reverse)
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 2, correct: false, expected: 'resilient' } }))

    await store.submitChoice('c')

    expect(store.status).toBe('held')
    expect(store.verdict).toBeNull()
    expect(store.lastExercise).toBe('reverse')
    store.continueAfterChoice()
    expect(store.card?.word_id).toBe(8)
  })

  it('sends a pretest pick as an answer and shows its verdict before the next card', async () => {
    const pretest = card({ card_kind: 'new', prompt: { type: 'pretest', front: 'resilient', masked_example: null, options: ['a', 'Устойчивый', 'c', 'd'], answer_length: null } })
    const store = await startWith(pretest)
    expect(store.exercise).toBe('pretest')
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 5, correct: true, expected: 'Устойчивый' } }))

    await store.submitAnswer('Устойчивый')

    expect(api.training.answer).toHaveBeenCalledWith(1, 7, 'Устойчивый', expect.any(Number), null, 'pretest')
    expect(store.status).toBe('verdict')
    expect(store.lastExercise).toBe('pretest')
    expect(store.verdict?.expected).toBe('Устойчивый')
    store.continueAfterVerdict()
    expect(store.card?.word_id).toBe(8)
  })

  it('saves "my hint" for the card on screen and keeps the saved text; a blank clears it', async () => {
    const store = await startWith(card({ note: null }))
    vi.mocked(api.words.saveNote).mockResolvedValueOnce({ word_id: 7, note: 'как «резина»' })

    expect(await store.saveNote(' как «резина» ')).toBe(true)
    expect(api.words.saveNote).toHaveBeenCalledWith(7, ' как «резина» ')
    expect(store.card?.note).toBe('как «резина»')

    vi.mocked(api.words.saveNote).mockResolvedValueOnce({ word_id: 7, note: null })
    expect(await store.saveNote('   ')).toBe(true)
    expect(api.words.saveNote).toHaveBeenLastCalledWith(7, null)
    expect(store.card?.note).toBeNull()
  })

  it('reports a failed save and leaves the card as it was; a note that comes back for another card is dropped', async () => {
    const store = await startWith(card({ note: 'old' }))
    vi.mocked(api.words.saveNote).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
    expect(await store.saveNote('new')).toBe(false)
    expect(store.card?.note).toBe('old')

    let resolve: (value: { word_id: number; note: string | null }) => void = () => {}
    vi.mocked(api.words.saveNote).mockReturnValueOnce(new Promise((res) => { resolve = res }))
    const saving = store.saveNote('late')
    store.applyCard(card({ word_id: 9, note: null }))
    resolve({ word_id: 7, note: 'late' })
    expect(await saving).toBe(true)
    expect(store.card?.word_id).toBe(9)
    expect(store.card?.note).toBeNull()
  })
})


describe('training store — the answer to a four-choice card and the exercise the client shows', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const audio = card({
    word: 'resilient',
    translation: '',
    prompt: { type: 'audio', front: '', masked_example: null, options: ['a', 'Устойчивый', 'c', 'd'], answer_length: null },
  })
  const revealed = {
    word: 'resilient', translation: 'Устойчивый', correct: 'Устойчивый', example: 'She is resilient.', transcription: null,
    audio_url: null, hook: 'hint', note: null, confused_with: null,
  }

  it('keeps the verdict and what the card was sent without as `choiceResult` until the next card is shown', async () => {
    const store = await startWith(audio)
    vi.mocked(api.training.answer).mockResolvedValueOnce(
      reviewed({ graded: { quality: 4, correct: true, expected: 'устойчивый' }, revealed }),
    )

    await store.submitChoice('Устойчивый')

    expect(store.status).toBe('held')
    // The exact text of the right option comes from `revealed`; `graded.expected` is normalised.
    expect(store.choiceResult).toEqual({ correct: true, right: 'Устойчивый', revealed })

    store.continueAfterChoice()
    expect(store.choiceResult).toBeNull()
    expect(store.card?.word_id).toBe(8)
  })

  it('takes the right option from `graded.expected` when the server sent no `revealed`', async () => {
    const store = await startWith(audio)
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 2, correct: false, expected: 'устойчивый' } }))

    await store.submitChoice('a')

    expect(store.choiceResult).toEqual({ correct: false, right: 'устойчивый', revealed: null })
  })

  it('goes on to the next card at once when the answer carries no verdict (nothing to colour the options by)', async () => {
    const store = await startWith(audio)
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: null }))

    await store.submitChoice('a')

    expect(store.status).toBe('ready')
    expect(store.choiceResult).toBeNull()
    expect(store.card?.word_id).toBe(8)
  })

  it('tells the server which exercise was shown: the prompt type of the card, flash when there is none', async () => {
    const store = await startWith(card({}))
    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())
    await store.submitReview(4)
    expect(vi.mocked(api.training.review).mock.calls[0]?.[5]).toBe('flash')

    store.applyCard(audio)
    vi.mocked(api.training.answer).mockResolvedValueOnce(reviewed({ graded: { quality: 2, correct: false, expected: 'x' } }))
    await store.submitChoice('a')
    expect(vi.mocked(api.training.answer).mock.calls[0]?.[5]).toBe('audio')
  })

  it('on 409 exercise_mismatch (the plan changed mid-session) fetches the card again, with no error', async () => {
    const store = await startWith(spelling)
    vi.mocked(api.training.answer).mockRejectedValueOnce(new ApiClientError('conflict', 409, 'exercise_mismatch', 'x'))
    vi.mocked(api.training.next).mockResolvedValueOnce({ card: audio, progress: { done: 0, total: 3 } })

    await store.submitAnswer('resilient')

    expect(api.training.next).toHaveBeenCalledTimes(1)
    expect(store.status).toBe('ready')
    expect(store.exercise).toBe('audio')
    expect(store.errorMessage).toBe('')
    expect(store.reviewedCount).toBe(0)
  })
})
