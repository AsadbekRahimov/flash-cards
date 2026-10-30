import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { useBattleAudio } from '../useBattleAudio'

vi.mock('@/api', () => ({ api: { battles: { audioBlob: vi.fn() }, practice: { audioBlob: vi.fn() } } }))

const URLS = [1, 2, 3, 4, 5].map((n) => `/api/twa/practice/battles/15/audio/eD${n}`)

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
const revoked = vi.fn()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  urlCounter = 0
  revoked.mockReset()
  URL.createObjectURL = () => `blob:clip-${++urlCounter}`
  URL.revokeObjectURL = (url: string) => revoked(url)
})

describe('useBattleAudio — the recordings of a dictation, before “Ready”', () => {
  it('starts with nothing to load', () => {
    const audio = useBattleAudio()

    expect(audio.state.value).toBe('idle')
    expect(audio.total.value).toBe(0)
    expect(audio.loaded.value).toBe(0)
  })

  it('loads all five with the token (the battle endpoint), and is ready when the last one is in', async () => {
    const replies = URLS.map(() => pending<Blob>())
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => replies[URLS.indexOf(url)]!.promise)
    const audio = useBattleAudio()

    audio.preload(URLS)
    expect(audio.state.value).toBe('loading')
    expect(audio.total.value).toBe(5)
    expect(api.battles.audioBlob).toHaveBeenCalledTimes(5)
    for (const url of URLS) expect(api.battles.audioBlob).toHaveBeenCalledWith(url)
    expect(api.practice.audioBlob).not.toHaveBeenCalled()

    replies[0]!.resolve(clip())
    replies[1]!.resolve(clip())
    await flushPromises()
    expect(audio.loaded.value).toBe(2)
    expect(audio.state.value).toBe('loading')

    for (const reply of replies.slice(2)) reply.resolve(clip())
    await flushPromises()
    expect(audio.loaded.value).toBe(5)
    expect(audio.state.value).toBe('ready')
  })

  it('one clip that fails makes it failed once the others are done - not before', async () => {
    const slow = pending<Blob>()
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url === URLS[4] ? slow.promise : url === URLS[2] ? Promise.reject(new Error('503')) : Promise.resolve(clip())))
    const audio = useBattleAudio()

    audio.preload(URLS)
    await flushPromises()
    expect(audio.state.value).toBe('loading') // the fifth is still on its way
    expect(audio.loaded.value).toBe(3)

    slow.resolve(clip())
    await flushPromises()
    expect(audio.state.value).toBe('failed')
    expect(audio.loaded.value).toBe(4)
  })

  it('an empty recording is a failed one', async () => {
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => Promise.resolve(url === URLS[0] ? clip(0) : clip()))
    const audio = useBattleAudio()

    audio.preload(URLS)
    await flushPromises()

    expect(audio.state.value).toBe('failed')
    expect(audio.blobFor(URLS[0]!)).toBeNull()
  })

  it('“Retry” loads again only what failed', async () => {
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url === URLS[1] ? Promise.reject(new Error('503')) : Promise.resolve(clip())))
    const audio = useBattleAudio()
    audio.preload(URLS)
    await flushPromises()
    expect(audio.state.value).toBe('failed')
    vi.mocked(api.battles.audioBlob).mockClear()
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())

    audio.retry()
    await flushPromises()

    expect(api.battles.audioBlob).toHaveBeenCalledTimes(1)
    expect(api.battles.audioBlob).toHaveBeenCalledWith(URLS[1])
    expect(audio.state.value).toBe('ready')
  })

  it('the same list again changes nothing: no new requests, what is loaded stays', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    const audio = useBattleAudio()
    audio.preload(URLS)
    await flushPromises()

    audio.preload([...URLS])
    await flushPromises()

    expect(api.battles.audioBlob).toHaveBeenCalledTimes(5)
    expect(audio.state.value).toBe('ready')
    expect(revoked).not.toHaveBeenCalled()
  })

  it('another list drops the old clips (their blob: URLs are given back) and loads the new ones', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    const audio = useBattleAudio()
    audio.preload(URLS)
    await flushPromises()

    audio.preload(['/api/twa/practice/battles/16/audio/eX1'])
    await flushPromises()

    expect(revoked).toHaveBeenCalledTimes(5)
    expect(audio.total.value).toBe(1)
    expect(audio.blobFor(URLS[0]!)).toBeNull()
    expect(audio.state.value).toBe('ready')
  })

  it('keeps the loaded recordings for the match: the round asks for the blob before it goes to the network', async () => {
    const blob = clip(9)
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => Promise.resolve(url === URLS[2] ? blob : clip()))
    const audio = useBattleAudio()
    audio.preload(URLS)
    await flushPromises()

    expect(audio.blobFor(URLS[2]!)).toBe(blob)
    expect(audio.blobFor('/api/twa/practice/battles/15/audio/eOther')).toBeNull()
  })

  it('release gives everything back and forgets the clips', async () => {
    vi.mocked(api.battles.audioBlob).mockResolvedValue(clip())
    const audio = useBattleAudio()
    audio.preload(URLS)
    await flushPromises()

    audio.release()

    expect(revoked).toHaveBeenCalledTimes(5)
    expect(audio.total.value).toBe(0)
    expect(audio.state.value).toBe('idle')
    expect(audio.blobFor(URLS[0]!)).toBeNull()
  })

  it('a clip that was still loading when the list changed is nobody’s: it does not come back to life', async () => {
    const late = pending<Blob>()
    vi.mocked(api.battles.audioBlob).mockImplementation((url) => (url === URLS[0] ? late.promise : Promise.resolve(clip())))
    const audio = useBattleAudio()
    audio.preload(URLS)

    audio.release()
    late.resolve(clip())
    await flushPromises()

    expect(audio.total.value).toBe(0)
    expect(audio.loaded.value).toBe(0)
  })
})
