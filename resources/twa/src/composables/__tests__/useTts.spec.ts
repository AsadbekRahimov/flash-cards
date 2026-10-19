import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useTts } from '../useTts'

const played: string[] = []
let playImpl: () => Promise<void>

class FakeAudio {
  constructor(public src: string) {}
  play(): Promise<void> {
    played.push(this.src)
    return playImpl()
  }
}

describe('useTts', () => {
  const utterances: string[] = []
  const speakSpy = vi.fn((u: { text: string }) => utterances.push(u.text))

  beforeEach(() => {
    played.length = 0
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
})
