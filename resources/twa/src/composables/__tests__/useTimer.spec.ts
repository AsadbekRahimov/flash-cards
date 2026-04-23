import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { useTimer } from '../useTimer'

// Mount inside a dummy component so `onBeforeUnmount` inside `useTimer`
// has an active Vue instance.
function withTimer<T>(factory: () => T): { value: T; unmount: () => void } {
  let value!: T
  const Host = defineComponent({
    setup() {
      value = factory()
      return () => h('div')
    },
  })
  const wrapper = mount(Host)
  return { value, unmount: () => wrapper.unmount() }
}

describe('useTimer', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('counts down to zero and fires onExpire exactly once', () => {
    const onExpire = vi.fn()
    const { value: t, unmount } = withTimer(() => useTimer(onExpire))
    t.start(3)
    expect(t.remaining.value).toBe(3)

    vi.advanceTimersByTime(1000)
    expect(t.remaining.value).toBe(2)
    vi.advanceTimersByTime(1000)
    expect(t.remaining.value).toBe(1)
    vi.advanceTimersByTime(1000)
    expect(t.remaining.value).toBe(0)
    expect(t.expired.value).toBe(true)
    expect(onExpire).toHaveBeenCalledTimes(1)

    // Extra ticks must not re-fire onExpire.
    vi.advanceTimersByTime(5000)
    expect(onExpire).toHaveBeenCalledTimes(1)

    unmount()
  })

  it('stop() freezes the countdown and prevents onExpire', () => {
    const onExpire = vi.fn()
    const { value: t, unmount } = withTimer(() => useTimer(onExpire))
    t.start(5)
    vi.advanceTimersByTime(2000)
    t.stop()
    vi.advanceTimersByTime(10_000)
    expect(t.remaining.value).toBe(3)
    expect(onExpire).not.toHaveBeenCalled()
    unmount()
  })

  it('start() resets a previous countdown cleanly', () => {
    const { value: t, unmount } = withTimer(() => useTimer())
    t.start(3)
    vi.advanceTimersByTime(1000)
    t.start(10)
    expect(t.remaining.value).toBe(10)
    expect(t.expired.value).toBe(false)
    unmount()
  })
})
