import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  MAX_OFFSET_MS,
  OPENED_KEY,
  crc32,
  flushTiming,
  inTimingSample,
  markTiming,
  normalizePlatform,
  resetTimingMarks,
  setTimingSampleRate,
  startTimingMarks,
  timingMarks,
  type ClientTimingPayload,
  type TimingOptions,
} from '../useTimingMarks'

vi.mock('@/api', () => ({ api: { telemetry: { clientTiming: vi.fn() } } }))

/** Bucket 0 of `client_timing:<id>` (in any sample above 0); the server's ClientTimingApiTest pins the same ids. */
const IN_ANY = '00000006-0000-4000-8000-000000000006'
/** Bucket 31: outside a 10 % sample, inside 32 % and above. */
const BUCKET_31 = '11111111-1111-4111-8111-111111111111'

let clock = 0
const send = vi.fn<(payload: ClientTimingPayload) => Promise<unknown>>()

function start(options: TimingOptions = {}): void {
  startTimingMarks({ now: () => clock, openId: IN_ANY, platform: 'android', cold: true, entry: 'home', send, ...options })
}

function hide(): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' })
  document.dispatchEvent(new Event('visibilitychange'))
}

describe('useTimingMarks', () => {
  beforeEach(() => {
    resetTimingMarks()
    clock = 0
    send.mockReset()
    send.mockResolvedValue(undefined)
    window.sessionStorage.clear()
  })

  afterEach(() => {
    resetTimingMarks()
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
  })

  it('hashes like PHP crc32, so the client and the server agree on the sample', () => {
    // PHP: crc32('The quick brown fox jumps over the lazy dog') === 1095738169
    expect(crc32('The quick brown fox jumps over the lazy dog')).toBe(1095738169)
    expect(crc32(`client_timing:${IN_ANY}`) % 100).toBe(0)
    expect(crc32(`client_timing:${BUCKET_31}`) % 100).toBe(31)
    expect(inTimingSample(BUCKET_31, 31)).toBe(false)
    expect(inTimingSample(BUCKET_31, 32)).toBe(true)
    expect(inTimingSample(BUCKET_31.toUpperCase(), 32)).toBe(true)
    expect(inTimingSample(IN_ANY, 0)).toBe(false)
    expect(inTimingSample(IN_ANY, 1)).toBe(true)
  })

  it('records nothing before the open is started', () => {
    markTiming('auth_ready')
    setTimingSampleRate(100)
    markTiming('first_answer_ack')

    expect(timingMarks()).toEqual({})
    expect(send).not.toHaveBeenCalled()
  })

  it('keeps the first offset of every mark, rounded to whole milliseconds', () => {
    start()
    clock = 310.4
    markTiming('auth_ready')
    clock = 900
    markTiming('auth_ready')
    clock = 640.6
    markTiming('today_received')

    expect(timingMarks()).toEqual({ auth_ready: 310, today_received: 641 })
  })

  it('drops an offset past the ceiling or a broken clock, and keeps the bound itself', () => {
    start()
    clock = MAX_OFFSET_MS + 1
    markTiming('first_audio')
    clock = Number.NaN
    markTiming('first_tap')
    clock = MAX_OFFSET_MS
    markTiming('button_ready')

    expect(timingMarks()).toEqual({ button_ready: MAX_OFFSET_MS })
  })

  it('sends once, right after the first acknowledged answer, with exactly the payload of the API', async () => {
    start()
    setTimingSampleRate(10)
    for (const [mark, at] of [['auth_ready', 300], ['today_received', 650], ['button_ready', 700], ['first_audio', 2900], ['first_tap', 2100], ['first_answer_ack', 4800]] as const) {
      clock = at
      markTiming(mark)
    }
    clock = 9000
    markTiming('first_answer_ack')
    hide()
    flushTiming()

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0][0]).toEqual({
      open_id: IN_ANY,
      platform: 'android',
      cold: true,
      entry: 'home',
      auth_ready: 300,
      today_received: 650,
      button_ready: 700,
      first_tap: 2100,
      first_audio: 2900,
      first_answer_ack: 4800,
    })
  })

  it('sends whatever marks exist when the page is hidden, once', () => {
    start()
    setTimingSampleRate(100)
    clock = 300
    markTiming('auth_ready')
    clock = 700
    markTiming('button_ready')

    hide()
    window.dispatchEvent(new Event('pagehide'))
    clock = 5000
    markTiming('first_answer_ack')

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0][0]).toEqual({ open_id: IN_ANY, platform: 'android', cold: true, entry: 'home', auth_ready: 300, button_ready: 700 })
  })

  it('sends on pagehide too', () => {
    start({ cold: false, entry: 'deep_link' })
    setTimingSampleRate(100)
    markTiming('auth_ready')

    window.dispatchEvent(new Event('pagehide'))

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0][0]).toMatchObject({ cold: false, entry: 'deep_link', auth_ready: 0 })
  })

  it('does not send while the page stays visible', () => {
    start()
    setTimingSampleRate(100)
    markTiming('auth_ready')

    document.dispatchEvent(new Event('visibilitychange'))

    expect(send).not.toHaveBeenCalled()
  })

  it('sends nothing when the share is 0 or absent (an older server)', () => {
    for (const rate of [0, undefined, null]) {
      resetTimingMarks()
      start()
      setTimingSampleRate(rate)
      markTiming('auth_ready')
      markTiming('first_answer_ack')
      hide()
      window.dispatchEvent(new Event('pagehide'))
    }

    expect(send).not.toHaveBeenCalled()
  })

  it('waits for the share: a hide before /auth answered sends nothing, a later moment still may', () => {
    start()
    clock = 120
    markTiming('first_tap')
    hide()
    expect(send).not.toHaveBeenCalled()

    setTimingSampleRate(100)
    markTiming('first_answer_ack')
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('sends nothing for an open outside the sample', () => {
    start({ openId: BUCKET_31 })
    setTimingSampleRate(10)
    markTiming('auth_ready')
    markTiming('first_answer_ack')
    hide()

    expect(send).not.toHaveBeenCalled()
  })

  it('sends the open of the sample at a higher share', () => {
    start({ openId: BUCKET_31 })
    setTimingSampleRate(32)
    markTiming('first_answer_ack')

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0][0].open_id).toBe(BUCKET_31)
  })

  it('swallows a failed send and a sender that throws, and never retries', async () => {
    start()
    setTimingSampleRate(100)
    send.mockRejectedValueOnce(new Error('offline'))
    expect(() => markTiming('first_answer_ack')).not.toThrow()
    await Promise.resolve()
    hide()
    expect(send).toHaveBeenCalledTimes(1)

    resetTimingMarks()
    startTimingMarks({
      now: () => 1,
      openId: IN_ANY,
      send: () => {
        throw new Error('no fetch')
      },
    })
    setTimingSampleRate(100)
    expect(() => markTiming('first_answer_ack')).not.toThrow()
  })

  it('names the platform from a closed list', () => {
    expect(normalizePlatform('ios')).toBe('ios')
    expect(normalizePlatform('tdesktop')).toBe('tdesktop')
    expect(normalizePlatform('visionos')).toBe('other')
    expect(normalizePlatform('')).toBe('unknown')
    expect(normalizePlatform(null)).toBe('unknown')

    start({ platform: 'Android 14; Pixel 8' })
    setTimingSampleRate(100)
    markTiming('first_answer_ack')
    expect(send.mock.calls[0][0].platform).toBe('other')
  })

  it('tells a cold open from a warm one by the marker of the webview session, and a fresh uuid per open', () => {
    startTimingMarks({ now: () => 1, send })
    setTimingSampleRate(100)
    markTiming('first_answer_ack')
    resetTimingMarks()
    startTimingMarks({ now: () => 1, send })
    setTimingSampleRate(100)
    markTiming('first_answer_ack')

    const [first, second] = send.mock.calls.map((call) => call[0])
    expect(window.sessionStorage.getItem(OPENED_KEY)).toBe('1')
    expect(first.cold).toBe(true)
    expect(second.cold).toBe(false)
    expect(first.open_id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(second.open_id).not.toBe(first.open_id)
    // Outside Telegram there is no platform; the test page is not a deep link.
    expect(first.platform).toBe('unknown')
    expect(['home', 'other']).toContain(first.entry)
  })

  it('reads a reminder or announcement link as a deep link', () => {
    window.history.replaceState(null, '', '/twa/?from=reminder')
    startTimingMarks({ now: () => 1, send })
    setTimingSampleRate(100)
    markTiming('first_answer_ack')
    resetTimingMarks()
    window.history.replaceState(null, '', '/twa/')
    startTimingMarks({ now: () => 1, send })
    setTimingSampleRate(100)
    markTiming('first_answer_ack')
    resetTimingMarks()
    window.history.replaceState(null, '', '/twa/league')
    startTimingMarks({ now: () => 1, send })
    setTimingSampleRate(100)
    markTiming('first_answer_ack')
    window.history.replaceState(null, '', '/')

    expect(send.mock.calls.map((call) => call[0].entry)).toEqual(['deep_link', 'home', 'other'])
  })
})
