import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ERROR_TOAST_MS, hideErrorToast, showErrorToast } from '../errorToast'
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

describe('hideErrorToast', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    useToast().hide()
    hideErrorToast()
    vi.useRealTimers()
  })

  it('takes the toast of a refusal off the screen at once', () => {
    const { toast } = useToast()
    showErrorToast('Нет связи с сервером. Попробуйте ещё раз.')
    expect(toast.value?.text).toBe('Нет связи с сервером. Попробуйте ещё раз.')

    hideErrorToast()

    expect(toast.value).toBeNull()
  })

  it('leaves a message that has taken its place ("Saved" must not go with the refusal)', () => {
    const { toast } = useToast()
    showErrorToast('Урок не найден.')
    useToast().show('Сохранено')

    hideErrorToast()

    expect(toast.value?.text).toBe('Сохранено')
  })

  it('does nothing when no refusal is on screen: a "Saved" that was never an error stays', () => {
    const { toast } = useToast()
    useToast().show('Скопировано')

    hideErrorToast()

    expect(toast.value?.text).toBe('Скопировано')
  })

  it('does nothing once the refusal has run its time, and is fine to call again', () => {
    const { toast } = useToast()
    showErrorToast('Урок не найден.')
    vi.advanceTimersByTime(ERROR_TOAST_MS)
    expect(toast.value).toBeNull()
    useToast().show('Сохранено')

    hideErrorToast()
    hideErrorToast()

    expect(toast.value?.text).toBe('Сохранено')
  })

  it('takes off the latest refusal when one has replaced another, and not an older one that is gone', () => {
    const { toast } = useToast()
    showErrorToast('первое')
    showErrorToast('второе')
    expect(toast.value?.text).toBe('второе')

    hideErrorToast()

    expect(toast.value).toBeNull()
  })
})
