import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useToast } from '../useToast'

describe('useToast', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    useToast().hide()
    vi.useRealTimers()
  })

  it('shows a message and hides it after 1.8 s by default', () => {
    const { toast, show } = useToast()
    expect(toast.value).toBeNull()

    show('Сохранено')
    expect(toast.value?.text).toBe('Сохранено')

    vi.advanceTimersByTime(1799)
    expect(toast.value?.text).toBe('Сохранено')
    vi.advanceTimersByTime(1)
    expect(toast.value).toBeNull()
  })

  it('takes a custom duration', () => {
    const { toast, show } = useToast()

    show('Скопировано', 500)

    vi.advanceTimersByTime(499)
    expect(toast.value).not.toBeNull()
    vi.advanceTimersByTime(1)
    expect(toast.value).toBeNull()
  })

  it('replaces the message on screen and restarts the timer', () => {
    const { toast, show } = useToast()

    show('first')
    const firstId = toast.value?.id
    vi.advanceTimersByTime(1500)
    show('second')

    expect(toast.value?.text).toBe('second')
    expect(toast.value?.id).not.toBe(firstId)

    // The first message's timer must not hide the second one.
    vi.advanceTimersByTime(1500)
    expect(toast.value?.text).toBe('second')
    vi.advanceTimersByTime(300)
    expect(toast.value).toBeNull()
  })

  it('is one shared state for every caller', () => {
    useToast().show('shared')

    expect(useToast().toast.value?.text).toBe('shared')
  })

  it('hide() removes it at once and cancels the timer', () => {
    const { toast, show, hide } = useToast()

    show('x')
    hide()
    expect(toast.value).toBeNull()

    show('y')
    vi.advanceTimersByTime(100)
    expect(toast.value?.text).toBe('y')
  })
})
