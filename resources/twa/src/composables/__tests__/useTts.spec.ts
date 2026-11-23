import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useTts } from '../useTts'

const played: string[] = []
let playImpl: () => Promise<void>

const paused: string[] = []

const unloaded: string[] = []

class FakeAudio {
  currentTime = 0
  private source: string | null
  constructor(public src: string) {
    this.source = src
  }
  play(): Promise<void> {
    played.push(this.src)
    return playImpl()
  }
  pause(): void {
    paused.push(this.src)
  }
  removeAttribute(name: string): void {
    if (name === 'src') this.source = null
  }
  load(): void {
    // The download is let go only when the element reloads without a source.
    if (this.source === null) unloaded.push(this.src)
  }
}

describe('useTts', () => {
  const utterances: string[] = []
  const speakSpy = vi.fn((u: { text: string }) => utterances.push(u.text))

  beforeEach(() => {
    played.length = 0
    paused.length = 0
    unloaded.length = 0
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

  it('lets the download of a stopped file go: no source, reloaded (docs/26 §9.11)', async () => {
    const tts = useTts()
    tts.stop() // the sound a previous test left current (the module keeps one for all instances)
    paused.length = 0
    unloaded.length = 0
    await tts.play('/storage/audio/example.mp3')
    expect(unloaded).toEqual([])

    await tts.speak('word', 'en-US', '/storage/audio/word.mp3') // the word stops the example
    expect(paused).toEqual(['/storage/audio/example.mp3'])
    expect(unloaded).toEqual(['/storage/audio/example.mp3'])

    tts.stop()
    expect(unloaded).toEqual(['/storage/audio/example.mp3', '/storage/audio/word.mp3'])
    expect(tts.playingUrl.value).toBeNull()
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

  it('plays a word on R2 by its public address as it came (docs/26 §9.9)', async () => {
    await useTts().speak('resilient', 'en-US', 'https://audio.example.uz/audio/abc.mp3')
    expect(played).toEqual(['https://audio.example.uz/audio/abc.mp3'])
    expect(speakSpy).not.toHaveBeenCalled()
  })

  it('stays silent without a file and without Web Speech: no call to the removed /api/twa/tts (docs/26 §9.9)', async () => {
    vi.stubGlobal('speechSynthesis', undefined)
    await useTts().speak('resilient', 'en-US', null)
    expect(played).toEqual([])
  })

  it('stays silent when the file fails and there is no Web Speech: only the file was tried', async () => {
    vi.stubGlobal('speechSynthesis', undefined)
    playImpl = () => Promise.reject(new Error('404'))
    await useTts().speak('resilient', 'en-US', '/storage/audio/missing.mp3')
    expect(played).toEqual(['/storage/audio/missing.mp3'])
  })

  it('does not start Web Speech over the audio file', async () => {
    const cancel = vi.fn()
    vi.stubGlobal('speechSynthesis', { getVoices: () => [], addEventListener: () => {}, cancel, speak: speakSpy })
    await useTts().speak('resilient', 'en-US', '/storage/audio/a.mp3')
    expect(cancel).toHaveBeenCalled()
    expect(speakSpy).not.toHaveBeenCalled()
  })
})

describe('useTts — the first word heard in the open (docs/04 A.21)', () => {
  let clock = 0
  let playImpl2: () => Promise<void>
  const spoken: Array<{ onstart?: () => void }> = []

  beforeEach(async () => {
    const { resetTimingMarks, startTimingMarks } = await import('../useTimingMarks')
    resetTimingMarks()
    startTimingMarks({ now: () => clock, openId: '00000006-0000-4000-8000-000000000006', send: async () => undefined })
    clock = 0
    spoken.length = 0
    playImpl2 = () => Promise.resolve()
    vi.stubGlobal('Audio', class {
      currentTime = 0
      constructor(public src: string) {}
      play(): Promise<void> {
        return playImpl2()
      }
      pause(): void {}
    })
    vi.stubGlobal('SpeechSynthesisUtterance', class { onstart?: () => void; constructor(public text: string) {} })
    vi.stubGlobal('speechSynthesis', { getVoices: () => [], addEventListener: () => {}, cancel: () => {}, speak: (u: { onstart?: () => void }) => spoken.push(u) })
    vi.stubGlobal('window', globalThis)
  })

  afterEach(async () => {
    vi.unstubAllGlobals()
    ;(await import('../useTimingMarks')).resetTimingMarks()
  })

  it('marks first_audio once the file has started, once', async () => {
    const { timingMarks } = await import('../useTimingMarks')
    clock = 2900
    await useTts().speak('resilient', 'en-US', '/storage/audio/a.mp3')
    clock = 5000
    await useTts().speak('resilient', 'en-US', '/storage/audio/a.mp3')

    expect(timingMarks()).toEqual({ first_audio: 2900 })
  })

  it('does not mark a file that failed; the speech synthesis marks when it starts speaking', async () => {
    const { timingMarks } = await import('../useTimingMarks')
    playImpl2 = () => Promise.reject(new Error('404'))
    clock = 1000
    await useTts().speak('resilient', 'en-US', '/storage/audio/missing.mp3')
    expect(timingMarks()).toEqual({})

    clock = 1300
    spoken[0]?.onstart?.()
    expect(timingMarks()).toEqual({ first_audio: 1300 })
  })
})
