import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, effectScope, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { usePolling } from '../usePolling'

function setVisibility(state: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })
  document.dispatchEvent(new Event('visibilitychange'))
}

function pending() {
  let resolve!: () => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<void>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

beforeEach(() => {
  vi.useFakeTimers()
  setVisibility('visible')
})
afterEach(() => {
  vi.useRealTimers()
  setVisibility('visible')
})

/** Polling inside an effect scope, like a screen: `dispose()` is the unmount. */
function poll(tick: () => Promise<void>, enabled = ref(true), extra: { immediate?: boolean; intervalMs?: number } = {}) {
  const scope = effectScope()
  const handle = scope.run(() => usePolling(tick, { enabled, ...extra }))!
  return { enabled, handle, dispose: () => scope.stop() }
}

describe('usePolling — the beat', () => {
  it('reads every two seconds, the first time after one beat', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    poll(tick)

    expect(tick).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1999)
    expect(tick).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(tick).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(4000)
    expect(tick).toHaveBeenCalledTimes(4)
  })

  it('reads at once when asked to (`immediate`), and keeps the beat after it', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    poll(tick, ref(true), { immediate: true })

    await vi.advanceTimersByTimeAsync(0)
    expect(tick).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(2)
  })

  it('takes another beat from the options', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    poll(tick, ref(true), { intervalMs: 500 })

    await vi.advanceTimersByTimeAsync(1500)

    expect(tick).toHaveBeenCalledTimes(3)
  })

  it('does not run while it is turned off, starts when it is turned on, stops when it is turned off', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    const { enabled } = poll(tick, ref(false))

    await vi.advanceTimersByTimeAsync(10_000)
    expect(tick).not.toHaveBeenCalled()

    enabled.value = true
    await nextTick()
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(1)

    enabled.value = false
    await nextTick()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(tick).toHaveBeenCalledTimes(1)
  })

  it('stops by itself when the tick turns it off (a terminal status)', async () => {
    const enabled = ref(true)
    const tick = vi.fn(async () => {
      enabled.value = false
    })
    poll(tick, enabled)

    await vi.advanceTimersByTimeAsync(20_000)

    expect(tick).toHaveBeenCalledTimes(1)
  })
})

describe('usePolling — the page in the background', () => {
  it('stops when the page is hidden and reads at once when it comes back, then beats again', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    poll(tick)
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(1)

    setVisibility('hidden')
    await vi.advanceTimersByTimeAsync(30_000)
    expect(tick).toHaveBeenCalledTimes(1)

    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(0)
    expect(tick).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(3)
  })

  it('does not start while the page is hidden, even if it is turned on', async () => {
    setVisibility('hidden')
    const tick = vi.fn().mockResolvedValue(undefined)
    poll(tick)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(tick).not.toHaveBeenCalled()

    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(0)
    expect(tick).toHaveBeenCalledTimes(1)
  })

  it('a page that comes back while polling is off reads nothing', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    poll(tick, ref(false))

    setVisibility('hidden')
    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(10_000)

    expect(tick).not.toHaveBeenCalled()
  })
})

describe('usePolling — readings never overlap', () => {
  it('skips a beat while the previous reading is still on its way', async () => {
    const first = pending()
    const tick = vi.fn().mockReturnValueOnce(first.promise).mockResolvedValue(undefined)
    poll(tick)

    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(1)

    // the slow reading is not done: no second one is started on top of it, however long it takes
    await vi.advanceTimersByTimeAsync(10_000)
    expect(tick).toHaveBeenCalledTimes(1)

    first.resolve()
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(2)
  })

  it('a page that comes back while a reading is on its way does not start a second one', async () => {
    const slow = pending()
    const tick = vi.fn().mockReturnValueOnce(slow.promise).mockResolvedValue(undefined)
    poll(tick)
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(1)

    setVisibility('hidden')
    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(0)
    expect(tick).toHaveBeenCalledTimes(1)

    slow.resolve()
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(2)
  })
})

describe('usePolling — failures', () => {
  it('doubles the pause after each failure up to ten seconds, and goes back to two after a success', async () => {
    const results: Array<'fail' | 'ok'> = ['fail', 'fail', 'fail', 'fail', 'fail', 'ok']
    const times: number[] = []
    const start = Date.now()
    const tick = vi.fn(async () => {
      times.push(Date.now() - start)
      if (results.shift() === 'fail') throw new Error('offline')
    })
    poll(tick)

    await vi.advanceTimersByTimeAsync(80_000)

    // beats at 2 s, then 4 s, 8 s, 10 s, 10 s later; the success is followed by the normal two seconds
    const gaps = times.slice(1).map((time, i) => time - times[i]!)
    expect(times[0]).toBe(2000)
    expect(gaps.slice(0, 5)).toEqual([4000, 8000, 10_000, 10_000, 10_000])
    expect(gaps.slice(5, 8)).toEqual([2000, 2000, 2000])
  })

  it('a refusal (429 / 5xx / network) never ends the polling', async () => {
    const tick = vi.fn().mockRejectedValue(new Error('429'))
    poll(tick)

    await vi.advanceTimersByTimeAsync(60_000)

    expect(tick.mock.calls.length).toBeGreaterThan(4)
  })
})

describe('usePolling — the end', () => {
  it('stops with the scope that started it, even with a reading on its way', async () => {
    const slow = pending()
    const tick = vi.fn().mockReturnValueOnce(slow.promise).mockResolvedValue(undefined)
    const { dispose } = poll(tick)
    await vi.advanceTimersByTimeAsync(2000)

    dispose()
    slow.resolve()
    await vi.advanceTimersByTimeAsync(30_000)

    expect(tick).toHaveBeenCalledTimes(1)
  })

  it('stops with the component, and forgets the page', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    const enabled = ref(true)
    const Probe = defineComponent({
      setup() {
        usePolling(tick, { enabled })
        return () => null
      },
    })
    const wrapper = mount(Probe)
    await vi.advanceTimersByTimeAsync(2000)
    expect(tick).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    setVisibility('hidden')
    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(30_000)

    expect(tick).toHaveBeenCalledTimes(1)
  })

  it('`stop()` ends it for good, turning it on again does not bring it back', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    const { handle, enabled } = poll(tick, ref(true))

    handle.stop()
    enabled.value = false
    await nextTick()
    enabled.value = true
    await nextTick()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(tick).not.toHaveBeenCalled()
  })
})
