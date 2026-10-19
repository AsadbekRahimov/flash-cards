import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useTts } from '../useTts'

const played: string[] = []
let playImpl: () => Promise<void>

const paused: string[] = []

class FakeAudio {
  currentTime = 0
  constructor(public src: string) {}
  play(): Promise<void> {
    played.push(this.src)
    return playImpl()
  }
  pause(): void {
    paused.push(this.src)
  }
}

describe('useTts', () => {
  const utterances: string[] = []
  const speakSpy = vi.fn((u: { text: string }) => utterances.push(u.text))

  beforeEach(() => {
    played.length = 0
    paused.length = 0
    utterances.length = 0
    speakSpy.mockClear()
    playImpl = () => Promise.resolve()
    vi.stubGlobal('Audio', FakeAudio)
    vi.stubGlobal('SpeechSynthesisUtterance', class { constructor(public text: string) {} })
    vi.stubGlobal('speechSynthesis', {
      getVoices: () => [],
      addEventListener: () => {},
      cancel: () => {},
      speak: speakSpy,
    })
    vi.stubGlobal('window', globalThis)
  })

  afterEach(() => vi.unstubAllGlobals())

  it('plays the pre-generated audio_url and skips Web Speech', async () => {
    await useTts().speak('resilient', 'en-US', '/storage/audio/a.mp3')
    expect(played).toEqual(['/storage/audio/a.mp3'])
    expect(speakSpy).not.toHaveBeenCalled()
  })

  it('falls back to Web Speech when there is no audio_url', async () => {
    await useTts().speak('resilient', 'en-US', null)
    expect(played).toEqual([])
    expect(utterances).toEqual(['resilient'])
  })

  it('falls back to Web Speech when the file cannot be played', async () => {
    playImpl = () => Promise.reject(new Error('404'))
    await useTts().speak('resilient', 'en-US', '/storage/audio/missing.mp3')
    expect(played).toEqual(['/storage/audio/missing.mp3'])
    expect(utterances).toEqual(['resilient'])
  })

  it('stops the previous sound on a fast second tap instead of layering', async () => {
    const releases: Array<() => void> = []
    playImpl = () => new Promise<void>((resolve) => { releases.push(resolve) })
    const tts = useTts()
    const first = tts.speak('one', 'en-US', '/storage/audio/one.mp3')
    const second = tts.speak('two', 'en-US', '/storage/audio/two.mp3')
    releases.forEach((r) => r())
    await Promise.all([first, second])
    expect(played).toEqual(['/storage/audio/one.mp3', '/storage/audio/two.mp3'])
    expect(paused).toEqual(['/storage/audio/one.mp3'])
  })

  it('a superseded call does not fall back to Web Speech on top of the newer sound', async () => {
    let rejectFirst: (e: Error) => void = () => {}
    let n = 0
    playImpl = () => (++n === 1 ? new Promise<void>((_, reject) => { rejectFirst = reject }) : Promise.resolve())
    const tts = useTts()
    const first = tts.speak('one', 'en-US', '/storage/audio/one.mp3')
    const second = tts.speak('two', 'en-US', '/storage/audio/two.mp3')
    rejectFirst(new Error('aborted by pause'))
    await Promise.all([first, second])
    expect(utterances).toEqual([])
  })

  it('does not start Web Speech over the audio file', async () => {
    const cancel = vi.fn()
    vi.stubGlobal('speechSynthesis', { getVoices: () => [], addEventListener: () => {}, cancel, speak: speakSpy })
    await useTts().speak('resilient', 'en-US', '/storage/audio/a.mp3')
    expect(cancel).toHaveBeenCalled()
    expect(speakSpy).not.toHaveBeenCalled()
  })
})
