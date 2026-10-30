import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { clockText, remainingMs, secondsLeft } from '../battleTime'
import { useNow } from '../useNow'

const NOW = Date.parse('2026-10-30T10:00:00.000Z')

describe('remainingMs', () => {
  it('is the time to the deadline by the clock of the server: the device time plus the offset', () => {
    expect(remainingMs('2026-10-30T10:00:45.000Z', NOW, 0)).toBe(45_000)
    // the server is 5 s ahead of the device: 5 s less
    expect(remainingMs('2026-10-30T10:00:45.000Z', NOW, 5000)).toBe(40_000)
    // the server is 5 s behind the device: 5 s more
    expect(remainingMs('2026-10-30T10:00:45.000Z', NOW, -5000)).toBe(50_000)
  })

  it('is negative once the deadline has passed', () => {
    expect(remainingMs('2026-10-30T09:59:58.500Z', NOW, 0)).toBe(-1500)
  })

  it('is 0 for no deadline or one that is not a date', () => {
    expect(remainingMs(null, NOW, 0)).toBe(0)
    expect(remainingMs(undefined, NOW, 0)).toBe(0)
    expect(remainingMs('soon', NOW, 0)).toBe(0)
  })
})

describe('secondsLeft', () => {
  it('rounds up, so that a countdown says 1 until the very last moment and 0 only when it is over', () => {
    expect(secondsLeft(45_000)).toBe(45)
    expect(secondsLeft(44_001)).toBe(45)
    expect(secondsLeft(1)).toBe(1)
    expect(secondsLeft(0)).toBe(0)
    expect(secondsLeft(-3000)).toBe(0)
  })
})

describe('clockText', () => {
  it('writes minutes and two-digit seconds', () => {
    expect(clockText(0)).toBe('0:00')
    expect(clockText(5)).toBe('0:05')
    expect(clockText(32)).toBe('0:32')
    expect(clockText(60)).toBe('1:00')
    expect(clockText(252)).toBe('4:12')
  })

  it('never goes below zero or shows a fraction', () => {
    expect(clockText(-4)).toBe('0:00')
    expect(clockText(7.9)).toBe('0:07')
  })
})

describe('useNow', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW))
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('is the device clock, and moves every quarter of a second', async () => {
    const scope = effectScope()
    const now = scope.run(() => useNow())!

    expect(now.value).toBe(NOW)
    await vi.advanceTimersByTimeAsync(250)
    expect(now.value).toBe(NOW + 250)
    await vi.advanceTimersByTimeAsync(1000)
    expect(now.value).toBe(NOW + 1250)
    scope.stop()
  })

  it('takes the beat it is given', async () => {
    const scope = effectScope()
    const now = scope.run(() => useNow(1000))!

    await vi.advanceTimersByTimeAsync(900)
    expect(now.value).toBe(NOW)
    await vi.advanceTimersByTimeAsync(100)
    expect(now.value).toBe(NOW + 1000)
    scope.stop()
  })

  it('stops with the scope that called it', async () => {
    const scope = effectScope()
    const now = scope.run(() => useNow())!
    expect(vi.getTimerCount()).toBe(1)

    scope.stop()
    await vi.advanceTimersByTimeAsync(2000)

    expect(vi.getTimerCount()).toBe(0)
    expect(now.value).toBe(NOW)
  })
})
