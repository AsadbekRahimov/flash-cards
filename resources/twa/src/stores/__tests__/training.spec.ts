import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTrainingStore } from '../training'
import { ApiClientError } from '@/api/client'
import { api } from '@/api'
import { setLocale } from '@/i18n'

vi.mock('@/api', () => ({
  api: {
    training: {
      start: vi.fn(),
      next: vi.fn(),
      review: vi.fn(),
    },
  },
}))

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
