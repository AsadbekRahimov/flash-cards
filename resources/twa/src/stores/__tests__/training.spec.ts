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
      start: vi.fn(),
      next: vi.fn(),
      review: vi.fn(),
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
    example: null,
    transcription: null,
    card_kind: 'new',
    serve_token: null,
    ...overrides,
  })

  it('echoes the serve_token of the card being graded and switches to the re-served card', async () => {
    vi.mocked(api.training.start).mockResolvedValue({
      session_id: 1,
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
    expect(store.errorMessage).toBe('Учитель завершил тренировку.')

    setLocale('uz')
    await store.start(1)
    expect(store.errorMessage).toBe("O'qituvchi mashg'ulotni yakunladi.")
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
