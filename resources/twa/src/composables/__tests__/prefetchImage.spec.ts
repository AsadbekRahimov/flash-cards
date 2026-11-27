import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mascotImage, prefetchImage, resetPrefetched } from '../illustrations'

/*
 * F5 "Нет сети" shows the mascot `thinking` exactly when nothing can be loaded (docs/40 T16): the picture is fetched into the cache once,
 * in an idle moment after the start (`requestIdleCallback`, else a timer), not before the first screen.
 */

describe('prefetchImage', () => {
  const sources: string[] = []
  const OriginalImage = window.Image

  beforeEach(() => {
    resetPrefetched()
    sources.length = 0
    vi.useFakeTimers()
    window.Image = class {
      decoding = ''
      set src(value: string) {
        sources.push(value)
      }
    } as unknown as typeof Image
  })

  afterEach(() => {
    window.Image = OriginalImage
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('waits for an idle moment, then asks for the very URL of the mascot, once per open', () => {
    const idle: Array<() => void> = []
    vi.stubGlobal('requestIdleCallback', (callback: () => void) => idle.push(callback))

    prefetchImage(mascotImage('thinking'))
    prefetchImage(mascotImage('thinking'))
    expect(sources).toEqual([])
    expect(idle).toHaveLength(1)

    idle[0]?.()
    expect(sources).toEqual(['/assets/img/m1/mascot/thinking.webp'])
  })

  it('falls back to a timer where there is no requestIdleCallback (Safari)', () => {
    vi.stubGlobal('requestIdleCallback', undefined)

    prefetchImage(mascotImage('thinking'))
    expect(sources).toEqual([])

    vi.advanceTimersByTime(3000)
    expect(sources).toEqual(['/assets/img/m1/mascot/thinking.webp'])
  })
})
