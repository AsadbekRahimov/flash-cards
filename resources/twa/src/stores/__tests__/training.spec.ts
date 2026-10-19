import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore } from '../training'
import { ApiClientError } from '@/api/client'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import type { Card } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    training: {
      open: vi.fn(),
      start: vi.fn(),
      next: vi.fn(),
      review: vi.fn(),
      finish: vi.fn(),
    },
  },
}))

describe('training store — learn-ahead serve_token', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const card = (overrides: Partial<Card>): Card => ({
    word_id: 7,
    word: 'Resilient',
    translation: 'Устойчивый',
    translation_fallback: false,
    example: null,
    transcription: null,
    card_kind: 'new',
    serve_token: null,
    ...overrides,
  })

  it('echoes the serve_token of the card being graded and switches to the re-served card', async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 1,
      mode: 'group_lesson',
      lesson: { stage: 1, lesson: 1, title: null },
      total_words: 1,
      card: card({ card_kind: 'new', serve_token: null }),
      progress: { done: 0, total: 1 },
    })
    vi.mocked(api.training.review)
      .mockResolvedValueOnce({
        next_review_at: null,
        new_interval_days: 0,
        easiness_factor: 2.5,
        card: card({ card_kind: 'learning', serve_token: 'ticket-1' }),
        progress: { done: 0, total: 1 },
      })
      .mockResolvedValueOnce({
        next_review_at: null,
        new_interval_days: 1,
        easiness_factor: 2.5,
        card: null,
        progress: { done: 1, total: 1 },
      })

    const store = useTrainingStore()
    await store.start(1)
    expect(store.card?.serve_token).toBeNull()

    // Due card: no ticket to echo.
    await store.submitReview(4)
    expect(vi.mocked(api.training.review).mock.calls[0]?.slice(0, 3)).toEqual([1, 7, 4])
    expect(vi.mocked(api.training.review).mock.calls[0]?.[4]).toBeNull()
    expect(store.status).toBe('ready')
    expect(store.card?.serve_token).toBe('ticket-1')

    // Learn-ahead card: the ticket from *this* serve goes back to the server.
    await store.submitReview(4)
    expect(vi.mocked(api.training.review).mock.calls[1]?.[4]).toBe('ticket-1')
    expect(store.status).toBe('finished')
  })
})

describe('training store — review rejected with card_not_due', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const card = (overrides: Partial<Card>): Card => ({
    word_id: 7,
    word: 'Resilient',
    translation: 'Устойчивый',
    translation_fallback: false,
    example: null,
    transcription: null,
    card_kind: 'learning',
    serve_token: 'ticket-1',
    ...overrides,
  })

  const startWithCard = async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 1,
      mode: 'group_lesson',
      lesson: { stage: 1, lesson: 1, title: null },
      total_words: 2,
      card: card({}),
      progress: { done: 0, total: 2 },
    })
    const store = useTrainingStore()
    await store.start(1)
    return store
  }

  const cardNotDue = () => new ApiClientError('conflict', 409, 'card_not_due', 'Card is not due.')

  it('silently loads the next card without counting the grade', async () => {
    const store = await startWithCard()
    vi.mocked(api.training.review).mockRejectedValue(cardNotDue())
    vi.mocked(api.training.next).mockResolvedValue({
      card: card({ word_id: 8, serve_token: 'ticket-2' }),
      progress: { done: 0, total: 2 },
    })

    await store.submitReview(4)

    expect(api.training.review).toHaveBeenCalledTimes(1)
    expect(api.training.next).toHaveBeenCalledTimes(1)
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(8)
    expect(store.card?.serve_token).toBe('ticket-2')
    expect(store.reviewedCount).toBe(0)
    expect(store.errorMessage).toBe('')
  })

  it('falls back to the error state when the follow-up next fails too', async () => {
    const store = await startWithCard()
    vi.mocked(api.training.review).mockRejectedValue(cardNotDue())
    vi.mocked(api.training.next).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'fetch failed'))

    await store.submitReview(4)

    expect(api.training.next).toHaveBeenCalledTimes(1)
    expect(store.status).toBe('error')
    expect(store.reviewedCount).toBe(0)
  })

  it('keeps the error state for other error codes without reloading', async () => {
    const store = await startWithCard()
    vi.mocked(api.training.review).mockRejectedValue(
      new ApiClientError('conflict', 409, 'something_else', 'Conflict.'),
    )

    await store.submitReview(4)

    expect(api.training.next).not.toHaveBeenCalled()
    expect(store.status).toBe('error')
  })
})

describe('training store — localized errors', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  afterEach(() => setLocale('ru'))

  it('maps a 410 session_closed to the closed state with a localized message', async () => {
    vi.mocked(api.training.start).mockRejectedValue(
      new ApiClientError('gone', 410, 'session_closed', 'This training session is closed.'),
    )

    const store = useTrainingStore()
    await store.start(1)

    expect(store.status).toBe('closed')
    expect(store.errorMessage).toBe('Эта тренировка уже закрыта: она завершена или время сессии истекло.')

    setLocale('uz')
    await store.start(1)
    expect(store.errorMessage).toBe("Bu mashg'ulot allaqachon yopilgan: u yakunlangan yoki sessiya vaqti tugagan.")
  })

  it('explains API error codes in the active language instead of the English server message', async () => {
    vi.mocked(api.training.start).mockRejectedValue(
      new ApiClientError('not_found', 404, 'session_not_found', 'Training session not found.'),
    )

    const store = useTrainingStore()
    await store.start(1)

    expect(store.status).toBe('error')
    expect(store.errorMessage).toBe('Сессия не найдена. Попросите учителя запустить её заново.')

    setLocale('uz')
    await store.start(1)
    expect(store.errorMessage).toBe("Sessiya topilmadi. O'qituvchidan uni qaytadan boshlashini so'rang.")
  })

  it('shows a localized network error and a generic fallback for unexpected errors', async () => {
    vi.mocked(api.training.start).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
    const store = useTrainingStore()
    await store.start(1)
    expect(store.errorMessage).toBe('Нет связи с сервером. Попробуйте ещё раз.')

    vi.mocked(api.training.start).mockRejectedValueOnce(new Error('boom'))
    await store.start(1)
    expect(store.status).toBe('error')
    expect(store.errorMessage).toBe('Непредвиденная ошибка.')
  })
})

describe('training store — personal sessions', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  afterEach(() => setLocale('ru'))

  const card: Card = {
    word_id: 7,
    word: 'Resilient',
    translation: 'Устойчивый',
    translation_fallback: false,
    example: null,
    transcription: null,
    card_kind: 'due',
    serve_token: null,
  }

  it('opens a session and returns its id', async () => {
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 42,
      mode: 'lesson',
      lesson: { stage: 1, lesson: 2, title: null },
      total_words: 3,
      card,
      progress: { done: 0, total: 3 },
      done_reason: null,
    })

    const store = useTrainingStore()
    const id = await store.open({ mode: 'lesson', lesson_id: 11, source: 'picker' })

    expect(id).toBe(42)
    expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 11, source: 'picker' })
    expect(store.opening).toBe(false)
    expect(store.openError).toBe('')
  })

  it('hands the answer of open() to start() instead of asking the server for it again', async () => {
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 42,
      mode: 'lesson',
      lesson: { stage: 1, lesson: 2, title: null },
      total_words: 3,
      card,
      progress: { done: 1, total: 3 },
      done_reason: null,
    })

    const store = useTrainingStore()
    await store.open({ mode: 'lesson', lesson_id: 11, source: 'picker' })
    await store.start(42)

    expect(api.training.start).not.toHaveBeenCalled()
    expect(api.training.next).not.toHaveBeenCalled()
    expect(store.status).toBe('ready')
    expect(store.card?.word_id).toBe(7)
    expect(store.progress).toEqual({ done: 1, total: 3 })
    expect(store.mode).toBe('lesson')

    // Consumed once: a later visit of the same session asks the server.
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 42, mode: 'lesson', lesson: { stage: 1, lesson: 2, title: null }, total_words: 3, card, progress: { done: 2, total: 3 },
    })
    await store.start(42)
    expect(api.training.start).toHaveBeenCalledTimes(1)
    expect(store.progress).toEqual({ done: 2, total: 3 })
  })

  it('asks the server for a session whose answer was not preloaded by open()', async () => {
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 42, mode: 'daily_review', lesson: null, total_words: 10, card, progress: { done: 0, total: 10 },
    })
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 9, mode: 'group_lesson', lesson: { stage: 1, lesson: 1, title: null }, total_words: 1, card, progress: { done: 0, total: 1 },
    })

    const store = useTrainingStore()
    await store.open({ mode: 'daily_review' })
    await store.start(9)

    expect(api.training.start).toHaveBeenCalledWith(9)
    expect(store.mode).toBe('group_lesson')
  })

  it('offers a restart request only for a personal session this run opened', async () => {
    vi.mocked(api.training.open).mockResolvedValue({
      session_id: 42, mode: 'lesson', lesson: { stage: 1, lesson: 2, title: null }, total_words: 3,
    })
    vi.mocked(api.training.start).mockRejectedValue(
      new ApiClientError('gone', 410, 'session_closed', 'This training session is closed.'),
    )

    const store = useTrainingStore()
    expect(store.restartRequest).toBeNull()

    await store.open({ mode: 'lesson', lesson_id: 11, source: 'picker' })
    store.preloaded = null // the visit that follows open() was long ago
    await store.start(42)
    expect(store.status).toBe('closed')
    expect(store.restartRequest).toEqual({ mode: 'lesson', lesson_id: 11, source: 'picker' })

    // A group session (or one opened before a page reload) is not ours to restart.
    await store.start(7)
    expect(store.status).toBe('closed')
    expect(store.restartRequest).toBeNull()
  })

  it('forgets the open error on demand', async () => {
    vi.mocked(api.training.open).mockRejectedValue(
      new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
    )
    const store = useTrainingStore()
    await store.open({ mode: 'lesson', lesson_id: 11 })
    expect(store.openError).not.toBe('')

    store.clearOpenError()

    expect(store.openError).toBe('')
  })

  it('explains an open failure in the active language and returns null', async () => {
    vi.mocked(api.training.open).mockRejectedValue(
      new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
    )

    const store = useTrainingStore()
    expect(await store.open({ mode: 'lesson', lesson_id: 11 })).toBeNull()
    expect(store.openError).toBe('Урок не найден.')

    vi.mocked(api.training.open).mockRejectedValue(
      new ApiClientError('validation', 422, 'lesson_mismatch', 'A lesson session needs a lesson.'),
    )
    setLocale('uz')
    await store.open({ mode: 'lesson' })
    expect(store.openError).toBe('Bu rejim tanlangan darsga mos kelmaydi.')
    expect(store.opening).toBe(false)
  })

  it('ignores a second open while one is in flight', async () => {
    let resolve: (v: Awaited<ReturnType<typeof api.training.open>>) => void = () => {}
    vi.mocked(api.training.open).mockReturnValue(new Promise((r) => (resolve = r)))

    const store = useTrainingStore()
    const first = store.open({ mode: 'daily_review' })
    expect(await store.open({ mode: 'daily_review' })).toBeNull()
    resolve({ session_id: 5, mode: 'daily_review', lesson: null, total_words: 10 })

    expect(await first).toBe(5)
    expect(api.training.open).toHaveBeenCalledTimes(1)
  })

  it('starts a daily review with lesson: null, keeps the done reason and clamps progress to the goal', async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 5,
      mode: 'daily_review',
      lesson: null,
      total_words: 10,
      card,
      progress: { done: 12, total: 10 },
      done_reason: null,
    })
    vi.mocked(api.training.review).mockResolvedValue({
      next_review_at: null,
      new_interval_days: 3,
      easiness_factor: 2.5,
      card: null,
      progress: { done: 13, total: 10 },
      done_reason: 'new_quota_exhausted',
    })

    const store = useTrainingStore()
    await store.start(5)
    expect(store.mode).toBe('daily_review')
    expect(store.lesson).toBeNull()
    expect(store.progress).toEqual({ done: 10, total: 10 })

    await store.submitReview(4)
    expect(store.status).toBe('finished')
    expect(store.doneReason).toBe('new_quota_exhausted')
    expect(store.progress).toEqual({ done: 10, total: 10 })
  })

  it('takes done_reason from /next and resets it with a new session', async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 6, mode: 'lesson', lesson: { stage: 1, lesson: 1, title: null }, total_words: 2,
    })
    vi.mocked(api.training.next).mockResolvedValue({
      card: null,
      progress: { done: 2, total: 2 },
      done_reason: 'lesson_complete',
    })

    const store = useTrainingStore()
    await store.start(6)
    expect(store.status).toBe('finished')
    expect(store.doneReason).toBe('lesson_complete')

    store.resetForSession(7)
    expect(store.doneReason).toBeNull()
    expect(store.summary).toBeNull()
  })

  it('finalize closes a personal session and keeps the server summary', async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 5, mode: 'daily_review', lesson: null, total_words: 10, card: null, progress: { done: 0, total: 10 },
      done_reason: 'nothing_due',
    })
    vi.mocked(api.training.finish).mockResolvedValue({ reviewed: 8, correct: 6, new_words: 2 })

    const store = useTrainingStore()
    await store.start(5)
    await store.finalize()

    expect(api.training.finish).toHaveBeenCalledWith(5)
    expect(store.summary).toEqual({ reviewed: 8, correct: 6, new_words: 2 })
  })

  it('finalize never closes a group session and survives a failing finish', async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 1, mode: 'group_lesson', lesson: { stage: 1, lesson: 1, title: null }, total_words: 1, card: null,
      progress: { done: 1, total: 1 }, done_reason: 'lesson_complete',
    })
    const store = useTrainingStore()
    await store.start(1)
    await store.finalize()
    expect(api.training.finish).not.toHaveBeenCalled()
    expect(store.summary).toBeNull()

    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 2, mode: 'lesson', lesson: { stage: 1, lesson: 1, title: null }, total_words: 1, card: null,
      progress: { done: 1, total: 1 }, done_reason: 'lesson_complete',
    })
    vi.mocked(api.training.finish).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'fetch failed'))
    await store.start(2)
    await store.finalize()
    expect(api.training.finish).toHaveBeenCalledWith(2)
    expect(store.summary).toBeNull()
  })

  it('explains out_of_scope in both languages', async () => {
    vi.mocked(api.training.start).mockRejectedValue(
      new ApiClientError('validation', 422, 'out_of_scope', 'Word is outside the scope.'),
    )
    const store = useTrainingStore()
    await store.start(1)
    expect(store.errorMessage).toBe('Это слово не относится к этой тренировке.')

    setLocale('uz')
    await store.start(1)
    expect(store.errorMessage).toBe("Bu so'z ushbu mashg'ulotga tegishli emas.")
  })
})
