import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ERROR_TOAST_MS, showErrorToast } from '../errorToast'
import { useToast } from '../useToast'

describe('showErrorToast', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    useToast().hide()
    vi.useRealTimers()
  })

  it('shows the refusal and keeps it longer than a "Saved" toast: it has to be read', () => {
    const { toast } = useToast()

    showErrorToast('Урок не найден.')

    expect(toast.value?.text).toBe('Урок не найден.')
    expect(ERROR_TOAST_MS).toBeGreaterThan(1800)
    vi.advanceTimersByTime(ERROR_TOAST_MS - 1)
    expect(toast.value?.text).toBe('Урок не найден.')
    vi.advanceTimersByTime(1)
    expect(toast.value).toBeNull()
  })

  it('replaces the toast that is on screen', () => {
    const { toast } = useToast()
    useToast().show('Сохранено')

    showErrorToast('Нет связи с сервером. Попробуйте ещё раз.')

    expect(toast.value?.text).toBe('Нет связи с сервером. Попробуйте ещё раз.')
  })
})
