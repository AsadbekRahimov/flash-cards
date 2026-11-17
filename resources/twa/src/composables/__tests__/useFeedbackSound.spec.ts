import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SOUNDS_KEY, isSoundEnabled, playSound, resetSounds, setSoundEnabled } from '../useFeedbackSound'
import { trackTtsAudio } from '../ttsActivity'

/** A stand-in of `AudioContext` that counts the tones the sounds are made of. */
function fakeAudio() {
  const started: number[] = []
  class FakeContext {
    state = 'running'
    currentTime = 0
    destination = {}
    resume = vi.fn().mockResolvedValue(undefined)
    createOscillator() {
      return { type: '', frequency: { value: 0 }, connect: vi.fn(), start: (at: number) => started.push(at), stop: vi.fn() }
    }
    createGain() {
      return { gain: { setValueAtTime: vi.fn(), linearRampToValueAtTime: vi.fn() }, connect: vi.fn() }
    }
  }
  const ctor = vi.fn(function () { return new FakeContext() })
  vi.stubGlobal('AudioContext', ctor)
  return { ctor, started }
}

describe('feedback sounds', () => {
  beforeEach(() => resetSounds())
  afterEach(() => {
    vi.unstubAllGlobals()
    trackTtsAudio(null)
    resetSounds()
  })

  it('are on by default', () => {
    expect(isSoundEnabled()).toBe(true)
  })

  it('keep the switch on the device and read it back', () => {
    setSoundEnabled(false)
    expect(isSoundEnabled()).toBe(false)
    expect(window.localStorage.getItem(SOUNDS_KEY)).toBe('off')

    setSoundEnabled(true)
    expect(window.localStorage.getItem(SOUNDS_KEY)).toBeNull()
  })

  it('keep the switch in memory when the storage is not available', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => { throw new Error('blocked') })

    expect(() => setSoundEnabled(false)).not.toThrow()
    expect(isSoundEnabled()).toBe(false)
    vi.restoreAllMocks()
  })

  it.each([
    ['right', 2],
    ['wrong', 2],
    ['done', 3],
  ] as const)('%s is a few short tones', (kind, tones) => {
    const { ctor, started } = fakeAudio()

    playSound(kind)

    expect(ctor).toHaveBeenCalledTimes(1)
    expect(started).toHaveLength(tones)
  })

  it('wake up a suspended context from the gesture and reuse one context', () => {
    const { ctor } = fakeAudio()

    playSound('right')
    playSound('wrong')

    expect(ctor).toHaveBeenCalledTimes(1)
  })

  it('play nothing when switched off', () => {
    const { ctor } = fakeAudio()
    setSoundEnabled(false)

    playSound('done')

    expect(ctor).not.toHaveBeenCalled()
  })

  it('do not talk over the word that is read aloud', () => {
    const { ctor } = fakeAudio()
    trackTtsAudio({ paused: false, ended: false } as HTMLAudioElement)

    playSound('right')
    expect(ctor).not.toHaveBeenCalled()

    trackTtsAudio({ paused: true, ended: false } as HTMLAudioElement)
    playSound('right')
    expect(ctor).toHaveBeenCalledTimes(1)
  })

  it('stay silent, without an error, where there is no AudioContext', () => {
    vi.stubGlobal('AudioContext', undefined)
    vi.stubGlobal('webkitAudioContext', undefined)

    expect(() => playSound('right')).not.toThrow()
  })

  it('stay silent when the context cannot be made', () => {
    vi.stubGlobal('AudioContext', vi.fn(function () { throw new Error('not allowed') }))

    expect(() => playSound('done')).not.toThrow()
  })
})
