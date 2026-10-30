import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { useWakeAt } from '../useWakeAt'

const T0 = Date.parse('2026-10-30T10:00:00.000Z')

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(T0))
})
afterEach(() => {
  vi.useRealTimers()
})

function setup(initial: number | null, offset = 0) {
  const due = ref<number | null>(initial)
  const offsetMs = ref(offset)
  const wake = vi.fn()
  const scope = effectScope()
  scope.run(() => useWakeAt({ dueAt: () => due.value, offsetMs: () => offsetMs.value, wake }))
  return { due, offsetMs, wake, scope }
}

describe('useWakeAt', () => {
  it('wakes the screen once, right after the moment the server is due to move the battle on', async () => {
    const { wake } = setup(T0 + 10_000)

    await vi.advanceTimersByTimeAsync(9_999)
    expect(wake).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(100)
    expect(wake).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(60_000)
    expect(wake).toHaveBeenCalledTimes(1)
  })

  it('counts in the time of the server: a device clock that is behind wakes the screen earlier on the device', async () => {
    // the server is 4 s ahead of the device, so its moment is 4 s closer
    const { wake } = setup(T0 + 10_000, 4000)

    await vi.advanceTimersByTimeAsync(5_900)
    expect(wake).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(200)
    expect(wake).toHaveBeenCalledTimes(1)
  })

  it('does not wait for a moment that has already passed: the read that just came would have moved the server on', async () => {
    const { wake } = setup(T0 - 500)

    await vi.advanceTimersByTimeAsync(10_000)

    expect(wake).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('a new moment replaces the old one', async () => {
    const { due, wake } = setup(T0 + 5_000)

    due.value = T0 + 20_000
    await nextTick()
    await vi.advanceTimersByTimeAsync(6_000)
    expect(wake).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(14_100)
    expect(wake).toHaveBeenCalledTimes(1)
  })

  it('nothing due cancels the wake-up', async () => {
    const { due, wake } = setup(T0 + 5_000)

    due.value = null
    await nextTick()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(wake).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('wakes again for the next moment after the first has been served', async () => {
    const { due, wake } = setup(T0 + 1_000)
    await vi.advanceTimersByTimeAsync(1_200)
    expect(wake).toHaveBeenCalledTimes(1)

    due.value = T0 + 5_000
    await nextTick()
    await vi.advanceTimersByTimeAsync(4_000)

    expect(wake).toHaveBeenCalledTimes(2)
  })

  it('ends with the scope that called it', async () => {
    const { scope, wake } = setup(T0 + 5_000)

    scope.stop()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(wake).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })
})
