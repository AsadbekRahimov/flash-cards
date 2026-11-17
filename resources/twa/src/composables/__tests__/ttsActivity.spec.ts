import { afterEach, describe, expect, it, vi } from 'vitest'
import { isTtsPlaying, trackTtsAudio, trackTtsUtterance, whenTtsEnds } from '../ttsActivity'

afterEach(() => {
  trackTtsAudio(null)
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('whenTtsEnds', () => {
  it('resolves at once when nothing is playing', async () => {
    await expect(whenTtsEnds(1000)).resolves.toBeUndefined()
  })

  it('waits for the end of an mp3, and lets go on `ended`', async () => {
    const listeners: Record<string, () => void> = {}
    const el = { paused: false, ended: false, addEventListener: (type: string, fn: () => void) => (listeners[type] = fn) }
    trackTtsAudio(el as unknown as HTMLAudioElement)
    let done = false
    void whenTtsEnds(5000).then(() => (done = true))

    await Promise.resolve()
    expect(done).toBe(false)
    el.ended = true
    listeners.ended?.()
    await Promise.resolve()
    expect(done).toBe(true)
  })

  it('waits for the end of a spoken utterance (`onend`)', async () => {
    const speech = { speaking: true }
    vi.stubGlobal('window', { speechSynthesis: speech })
    const utterance = {} as SpeechSynthesisUtterance
    trackTtsUtterance(utterance)
    expect(isTtsPlaying()).toBe(true)
    let done = false
    void whenTtsEnds(5000).then(() => (done = true))

    await Promise.resolve()
    expect(done).toBe(false)
    speech.speaking = false
    utterance.onend?.(new Event('end') as SpeechSynthesisEvent)
    await Promise.resolve()
    expect(done).toBe(true)
  })

  it('gives up at the ceiling', async () => {
    vi.useFakeTimers()
    trackTtsAudio({ paused: false, ended: false, addEventListener: () => {} } as unknown as HTMLAudioElement)
    let done = false
    void whenTtsEnds(1100).then(() => (done = true))

    await vi.advanceTimersByTimeAsync(1099)
    expect(done).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    expect(done).toBe(true)
  })
})
