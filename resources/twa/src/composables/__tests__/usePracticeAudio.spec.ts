import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { usePracticeStore, type AudioSlot } from '@/stores/practice'
import { usePracticeAudio } from '../usePracticeAudio'

vi.mock('@/api', () => ({ api: { practice: { audioBlob: vi.fn() } } }))

/** An `<audio>` that records what it was asked to do; `play` answers as the test decides. */
class FakeAudio {
  static instances: FakeAudio[] = []
  static playResult: () => Promise<void> = () => Promise.resolve()
  paused = false
  listeners = new Map<string, () => void>()
  constructor(public src: string) {
    FakeAudio.instances.push(this)
  }
  play = vi.fn(() => FakeAudio.playResult())
  pause = vi.fn(() => {
    this.paused = true
  })
  addEventListener(name: string, handler: () => void): void {
    this.listeners.set(name, handler)
  }
  emit(name: string): void {
    this.listeners.get(name)?.()
  }
}

const clip = (size = 4): Blob => new Blob(['x'.repeat(size)], { type: 'audio/mpeg' })

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

let urlCounter = 0
const created = vi.fn()
const revoked = vi.fn()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(api.practice.audioBlob).mockReset()
  FakeAudio.instances = []
  FakeAudio.playResult = () => Promise.resolve()
  urlCounter = 0
  created.mockReset()
  revoked.mockReset()
  vi.stubGlobal('Audio', FakeAudio)
  URL.createObjectURL = (blob: Blob) => {
    created(blob)
    return `blob:clip-${++urlCounter}`
  }
  URL.revokeObjectURL = (url: string) => revoked(url)
})
afterEach(() => vi.unstubAllGlobals())

const slotOf = (): AudioSlot => reactive({ state: 'idle', blobUrl: null })

describe('usePracticeAudio — loading the clip', () => {
  it('goes loading → ready and keeps a blob: URL of what the server sent', async () => {
    const reply = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValue(reply.promise)
    const audio = usePracticeAudio({ slot: slotOf() })

    const loading = audio.load('/api/twa/practice/sessions/7/audio/eD4')
    expect(audio.state.value).toBe('loading')
    expect(api.practice.audioBlob).toHaveBeenCalledWith('/api/twa/practice/sessions/7/audio/eD4', expect.any(AbortSignal))

    reply.resolve(clip())
    await loading

    expect(audio.state.value).toBe('ready')
    expect(audio.blobUrl.value).toBe('blob:clip-1')
  })

  it('is failed when the fetch fails, and when the clip is empty', async () => {
    const audio = usePracticeAudio({ slot: slotOf() })

    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(new Error('503'))
    await audio.load('/a')
    expect(audio.state.value).toBe('failed')
    expect(audio.blobUrl.value).toBeNull()

    vi.mocked(api.practice.audioBlob).mockResolvedValueOnce(new Blob([]))
    await audio.load('/b')
    expect(audio.state.value).toBe('failed')
    expect(created).not.toHaveBeenCalled()
  })

  it('asks once for a clip it has or is loading; `force` and a failure ask again', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const audio = usePracticeAudio({ slot: slotOf() })

    await audio.load('/a')
    await audio.load('/a')
    expect(api.practice.audioBlob).toHaveBeenCalledTimes(1)

    await audio.load('/a', { force: true })
    expect(api.practice.audioBlob).toHaveBeenCalledTimes(2)
    // the clip that was replaced is given back
    expect(revoked).toHaveBeenCalledWith('blob:clip-1')

    vi.mocked(api.practice.audioBlob).mockRejectedValueOnce(new Error('offline'))
    await audio.load('/b')
    expect(audio.state.value).toBe('failed')
    await audio.load('/b') // "Retry" without force: a failed clip is loaded again
    expect(audio.state.value).toBe('ready')
  })

  it('a clip for the previous element does not replace the one for the current (late reply)', async () => {
    const first = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValueOnce(first.promise).mockResolvedValueOnce(clip())
    const audio = usePracticeAudio({ slot: slotOf() })

    const old = audio.load('/old')
    await audio.load('/new')
    first.resolve(clip())
    await old

    expect(audio.state.value).toBe('ready')
    expect(audio.blobUrl.value).toBe('blob:clip-1')
    // the late clip never got a URL: nothing to leak
    expect(created).toHaveBeenCalledTimes(1)
  })

  it('a late failure of the previous clip does not fail the current one', async () => {
    const first = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValueOnce(first.promise).mockResolvedValueOnce(clip())
    const audio = usePracticeAudio({ slot: slotOf() })

    const old = audio.load('/old')
    await audio.load('/new')
    first.reject(new Error('503'))
    await old

    expect(audio.state.value).toBe('ready')
  })

  it('fetches with the function it is given (a battle clip)', async () => {
    const fetchBlob = vi.fn().mockResolvedValue(clip())
    const audio = usePracticeAudio({ slot: slotOf(), fetchBlob })

    await audio.load('/api/twa/practice/battles/15/audio/eD4')

    expect(fetchBlob).toHaveBeenCalledWith('/api/twa/practice/battles/15/audio/eD4', expect.any(AbortSignal))
    expect(api.practice.audioBlob).not.toHaveBeenCalled()
  })
})

describe('usePracticeAudio — playing', () => {
  async function ready() {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const audio = usePracticeAudio({ slot: slotOf() })
    await audio.load('/a')
    return audio
  }

  it('creates the <audio> inside the call, so that the tap that made it keeps its gesture', async () => {
    const audio = await ready()

    const result = audio.play() // no await in between: the element exists and is playing already

    expect(FakeAudio.instances).toHaveLength(1)
    expect(FakeAudio.instances[0]!.src).toBe('blob:clip-1')
    expect(FakeAudio.instances[0]!.play).toHaveBeenCalledTimes(1)
    expect(await result).toBe(true)
  })

  it('a second press starts the clip over on a new element and stops the first', async () => {
    const audio = await ready()

    void audio.play()
    void audio.play()

    expect(FakeAudio.instances).toHaveLength(2)
    expect(FakeAudio.instances[0]!.pause).toHaveBeenCalled()
    expect(FakeAudio.instances[1]!.play).toHaveBeenCalledTimes(1)
  })

  it('says when it plays: from the press to the end of the clip', async () => {
    const audio = await ready()

    await audio.play()
    expect(audio.playing.value).toBe(true)

    FakeAudio.instances[0]!.emit('ended')
    expect(audio.playing.value).toBe(false)
  })

  it('plays nothing, and creates nothing, while the clip is not ready', async () => {
    const audio = usePracticeAudio({ slot: slotOf() })

    expect(await audio.play()).toBe(false)
    expect(FakeAudio.instances).toHaveLength(0)

    vi.mocked(api.practice.audioBlob).mockRejectedValue(new Error('503'))
    await audio.load('/a')
    expect(await audio.play()).toBe(false)
    expect(FakeAudio.instances).toHaveLength(0)
  })

  it('a clip that will not play marks the audio failed (the screen offers a retry or a replacement)', async () => {
    const audio = await ready()
    FakeAudio.playResult = () => Promise.reject(Object.assign(new Error('bad media'), { name: 'NotSupportedError' }))

    expect(await audio.play()).toBe(false)

    expect(audio.state.value).toBe('failed')
    expect(audio.playing.value).toBe(false)
  })

  it('a media error event marks the audio failed', async () => {
    const audio = await ready()
    await audio.play()

    FakeAudio.instances[0]!.emit('error')

    expect(audio.state.value).toBe('failed')
  })

  it('a play() that a newer press interrupted (AbortError) is not a fault of the clip', async () => {
    const audio = await ready()
    FakeAudio.playResult = () => Promise.reject(Object.assign(new Error('interrupted'), { name: 'AbortError' }))

    expect(await audio.play()).toBe(false)

    expect(audio.state.value).toBe('ready')
  })

  it('never reads the text aloud: Web Speech is not used for a dictation', async () => {
    const speak = vi.fn()
    vi.stubGlobal('speechSynthesis', { speak, cancel: vi.fn(), getVoices: () => [] })
    vi.stubGlobal('SpeechSynthesisUtterance', vi.fn())
    const audio = await ready()
    FakeAudio.playResult = () => Promise.reject(Object.assign(new Error('bad media'), { name: 'NotSupportedError' }))

    await audio.play()

    expect(speak).not.toHaveBeenCalled()
  })

  it('the source of the composable has no Web Speech and no useTts in it', () => {
    const source = readFileSync(join(process.cwd(), 'src/composables/usePracticeAudio.ts'), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/(^|\s)\/\/.*$/gm, '$1')

    expect(source).not.toMatch(/speechSynthesis|SpeechSynthesisUtterance|useTts|\/api\/twa\/tts/)
  })
})

describe('usePracticeAudio — giving the clip back', () => {
  it('revoke() stops the clip, revokes the URL and empties the slot', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const slot = slotOf()
    const audio = usePracticeAudio({ slot })
    await audio.load('/a')
    await audio.play()

    audio.revoke()

    expect(FakeAudio.instances[0]!.pause).toHaveBeenCalled()
    expect(revoked).toHaveBeenCalledWith('blob:clip-1')
    expect(slot).toEqual({ state: 'idle', blobUrl: null })
    expect(audio.playing.value).toBe(false)
  })

  it('revoke() drops a load that is still on its way', async () => {
    const reply = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValue(reply.promise)
    const slot = slotOf()
    const audio = usePracticeAudio({ slot })

    const loading = audio.load('/a')
    audio.revoke()
    reply.resolve(clip())
    await loading

    expect(slot).toEqual({ state: 'idle', blobUrl: null })
    expect(created).not.toHaveBeenCalled()
  })

  it('a loaded clip can be loaded again after revoke()', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const audio = usePracticeAudio({ slot: slotOf() })
    await audio.load('/a')

    audio.revoke()
    await audio.load('/a')

    expect(api.practice.audioBlob).toHaveBeenCalledTimes(2)
    expect(audio.state.value).toBe('ready')
  })

  it('the end of the screen that plays stops the sound but keeps the clip for whoever holds the slot', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const slot = slotOf()
    const scope = effectScope()
    const audio = scope.run(() => usePracticeAudio({ slot }))!
    await audio.load('/a')
    await audio.play()

    scope.stop()

    expect(FakeAudio.instances[0]!.pause).toHaveBeenCalled()
    expect(slot.state).toBe('ready')
    expect(slot.blobUrl).toBe('blob:clip-1')
    expect(revoked).not.toHaveBeenCalled()
  })
})

describe('usePracticeAudio — one slot, many users', () => {
  it('the screen that loads and the component that plays see the same clip', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const slot = slotOf()
    const screen = usePracticeAudio({ slot })
    const component = usePracticeAudio({ slot })

    await screen.load('/a')

    expect(component.state.value).toBe('ready')
    expect(await component.play()).toBe(true)
    // and the second one does not fetch a clip the first one has
    await component.load('/a')
    expect(api.practice.audioBlob).toHaveBeenCalledTimes(1)
  })

  it('takes the audio slot of the practice store by default', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const store = usePracticeStore()
    const audio = usePracticeAudio()

    await audio.load('/a')

    expect(store.audio).toEqual({ state: 'ready', blobUrl: 'blob:clip-1' })
  })

  it('a slot of its own fetches again a clip that another slot holds (the review of an answer keeps its own copy)', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const screen = usePracticeAudio({ slot: slotOf() })
    const review = usePracticeAudio({ slot: slotOf() })

    await screen.load('/api/twa/practice/sessions/7/audio/eD4')
    await screen.load('/api/twa/practice/sessions/7/audio/eD5') // the screen goes on to the next clip while the verdict is read
    await review.load('/api/twa/practice/sessions/7/audio/eD4')

    expect(vi.mocked(api.practice.audioBlob).mock.calls.map(([url]) => url)).toEqual([
      '/api/twa/practice/sessions/7/audio/eD4',
      '/api/twa/practice/sessions/7/audio/eD5',
      '/api/twa/practice/sessions/7/audio/eD4',
    ])
    // Each holds its own blob; letting the review's go does not touch the screen's.
    expect(screen.blobUrl.value).not.toBe(review.blobUrl.value)
    review.revoke()
    expect(screen.state.value).toBe('ready')
    expect(review.state.value).toBe('idle')
  })

  it('two slots are two clips (the room of a battle holds five)', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const one = usePracticeAudio({ slot: slotOf() })
    const two = usePracticeAudio({ slot: slotOf() })

    await one.load('/a')
    await two.load('/b')
    one.revoke()

    expect(one.state.value).toBe('idle')
    expect(two.state.value).toBe('ready')
  })
})

describe('usePracticeAudio — a load that nobody waits for any more', () => {
  /** The signal that the n-th fetch of the solo clip was given. */
  const signalOf = (n = 0): AbortSignal => vi.mocked(api.practice.audioBlob).mock.calls[n]![1] as AbortSignal

  it('gives the fetch a signal, and leaving the screen (revoke) fires it: the request is stopped, not only ignored', async () => {
    const reply = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValue(reply.promise)
    const slot = slotOf()
    const audio = usePracticeAudio({ slot })

    const loading = audio.load('/a')
    expect(signalOf().aborted).toBe(false)
    audio.revoke()
    expect(signalOf().aborted).toBe(true)

    // the request ends with an error, as an aborted one does: the slot is empty, not "failed"
    reply.reject(new DOMException('The operation was aborted.', 'AbortError'))
    await loading
    expect(slot).toEqual({ state: 'idle', blobUrl: null })
  })

  it('a newer load stops the request of the older one', async () => {
    const first = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValueOnce(first.promise).mockResolvedValueOnce(clip())
    const audio = usePracticeAudio({ slot: slotOf() })

    const old = audio.load('/old')
    await audio.load('/new')

    expect(signalOf(0).aborted).toBe(true)
    expect(signalOf(1).aborted).toBe(false)
    first.reject(new DOMException('The operation was aborted.', 'AbortError'))
    await old
    expect(audio.state.value).toBe('ready')
  })

  it('"Retry" (force) stops the request that was hanging, and asks again', async () => {
    const hanging = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValueOnce(hanging.promise).mockResolvedValueOnce(clip())
    const audio = usePracticeAudio({ slot: slotOf() })

    void audio.load('/a')
    await audio.load('/a', { force: true })

    expect(signalOf(0).aborted).toBe(true)
    expect(audio.state.value).toBe('ready')
  })

  it('a clip that has come is not "stopped" afterwards: revoke() after it fires nothing', async () => {
    vi.mocked(api.practice.audioBlob).mockResolvedValue(clip())
    const audio = usePracticeAudio({ slot: slotOf() })
    await audio.load('/a')

    audio.revoke()

    expect(signalOf().aborted).toBe(false)
  })

  it('a clip that did not come in time (the client gives up with a network error) is failed, so that the screen offers a retry', async () => {
    vi.mocked(api.practice.audioBlob).mockRejectedValue(Object.assign(new Error('The operation was aborted.'), { kind: 'network' }))
    const audio = usePracticeAudio({ slot: slotOf() })

    await audio.load('/a')

    expect(audio.state.value).toBe('failed')
  })

  it('the end of the screen that plays (its scope) does not stop the load of the clip of the next element: the slot has other holders', async () => {
    const reply = pending<Blob>()
    vi.mocked(api.practice.audioBlob).mockReturnValue(reply.promise)
    const slot = slotOf()
    const scope = effectScope()
    const holder = scope.run(() => usePracticeAudio({ slot }))!
    const loading = holder.load('/next')

    scope.stop()

    expect(signalOf().aborted).toBe(false)
    reply.resolve(clip())
    await loading
    expect(slot.state).toBe('ready')
  })
})
