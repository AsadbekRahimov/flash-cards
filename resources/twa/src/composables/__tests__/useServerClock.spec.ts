import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useServerClock } from '../useServerClock'

const DEVICE_NOW = Date.parse('2026-10-30T10:00:00.000Z')

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(DEVICE_NOW)
})
afterEach(() => vi.useRealTimers())

/** The server's `server_now` that is `ms` ahead of the device clock. */
const serverAhead = (ms: number): string => new Date(DEVICE_NOW + ms).toISOString()

describe('useServerClock', () => {
  it('has no offset until the first reading: the server is taken to agree with the device', () => {
    const clock = useServerClock()

    expect(clock.offsetMs.value).toBe(0)
    expect(clock.now()).toBe(DEVICE_NOW)
  })

  it('learns how far the device is off from `server_now`', () => {
    const clock = useServerClock()

    clock.sync(serverAhead(3000))

    expect(clock.offsetMs.value).toBe(3000)
    expect(clock.now()).toBe(DEVICE_NOW + 3000)
  })

  it('counts the time left to a deadline by the clock of the server', () => {
    const clock = useServerClock()
    clock.sync(serverAhead(-4000)) // the device is 4 s ahead of the server

    // the deadline is 45 s after the server's now: 45 s are left, whatever the device clock says
    const deadline = new Date(DEVICE_NOW - 4000 + 45_000).toISOString()
    expect(clock.remaining(deadline)).toBe(45_000)

    vi.advanceTimersByTime(10_000)
    expect(clock.remaining(deadline)).toBe(35_000)
  })

  it('is negative once the deadline has passed, and 0 for a missing or unreadable one', () => {
    const clock = useServerClock()

    expect(clock.remaining(new Date(DEVICE_NOW - 1500).toISOString())).toBe(-1500)
    expect(clock.remaining(null)).toBe(0)
    expect(clock.remaining(undefined)).toBe(0)
    expect(clock.remaining('soon')).toBe(0)
  })

  it('ignores a reading that is not a date', () => {
    const clock = useServerClock()
    clock.sync(serverAhead(1000))

    clock.sync(null)
    clock.sync(undefined)
    clock.sync('yesterday')

    expect(clock.offsetMs.value).toBe(1000)
  })

  it('takes the median of the last three readings: one slow answer does not drag the offset', () => {
    const clock = useServerClock()

    clock.sync(serverAhead(1000))
    clock.sync(serverAhead(1000))
    clock.sync(serverAhead(-2000)) // an answer that spent two seconds on the way

    expect(clock.offsetMs.value).toBe(1000)
  })

  it('with two readings the median is their mean; the oldest of four is forgotten', () => {
    const clock = useServerClock()

    clock.sync(serverAhead(1000))
    clock.sync(serverAhead(3000))
    expect(clock.offsetMs.value).toBe(2000)

    clock.sync(serverAhead(5000))
    clock.sync(serverAhead(5000)) // 1000 is out: 3000, 5000, 5000
    expect(clock.offsetMs.value).toBe(5000)
  })

  it('forgets everything on reset (another battle)', () => {
    const clock = useServerClock()
    clock.sync(serverAhead(3000))

    clock.reset()

    expect(clock.offsetMs.value).toBe(0)
    clock.sync(serverAhead(500))
    expect(clock.offsetMs.value).toBe(500)
  })

  it('every clock has its own readings', () => {
    const a = useServerClock()
    const b = useServerClock()

    a.sync(serverAhead(3000))

    expect(b.offsetMs.value).toBe(0)
  })
})
