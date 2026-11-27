import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { canShareToStory, shareToStory } from '../telegramStory'

/**
 * `WebApp.shareToStory` of the SDK 8.x (`telegram-web-apps.js`): below 7.8 it throws `WebAppMethodUnsupported`, a media URL that is not
 * http(s) - `WebAppMediaUrlInvalid`; it calls nothing back (docs/40 §2.4).
 */
const sdk = vi.hoisted(() => ({
  version: '7.8',
  isVersionAtLeast: vi.fn(),
  shareToStory: undefined as undefined | ((url: string, params?: unknown) => void),
}))

vi.mock('@twa-dev/sdk', () => ({ default: sdk }))

function versionAtLeast(version: string): boolean {
  const [a = 0, b = 0] = sdk.version.split('.').map(Number)
  const [c = 0, d = 0] = version.split('.').map(Number)
  return a > c || (a === c && b >= d)
}

const sdkShareToStory = vi.fn((url: string) => {
  if (!versionAtLeast('7.8')) throw new Error('WebAppMethodUnsupported')
  if (!/^https?:/.test(url)) throw new Error('WebAppMediaUrlInvalid')
})

const STORY = {
  text: '7 дней подряд в Muhrla · t.me/MuhrlaBot?start=ref_ABCD2345',
  widget_link: { url: 'https://t.me/MuhrlaBot?start=ref_ABCD2345', name: 'Muhrla' },
}

describe('telegramStory: shareToStory', () => {
  beforeEach(() => {
    sdk.version = '7.8'
    sdk.shareToStory = sdkShareToStory
    sdkShareToStory.mockClear()
    sdk.isVersionAtLeast.mockImplementation(versionAtLeast)
    ;(window as unknown as { Telegram: unknown }).Telegram = { WebApp: sdk }
  })

  afterEach(() => {
    delete (window as unknown as { Telegram?: unknown }).Telegram
  })

  it.each(['7.8', '8.0'])('can share to a story on %s', (version) => {
    sdk.version = version
    expect(canShareToStory()).toBe(true)
  })

  it.each(['7.7', '6.9'])('cannot on %s, and does not call the SDK', (version) => {
    sdk.version = version
    expect(canShareToStory()).toBe(false)
    expect(shareToStory('https://example.test/s.jpg', STORY)).toBe('unsupported')
    expect(sdkShareToStory).not.toHaveBeenCalled()
  })

  it('cannot when the SDK has no such method', () => {
    sdk.shareToStory = undefined
    expect(canShareToStory()).toBe(false)
  })

  it('cannot outside Telegram', () => {
    delete (window as unknown as { Telegram?: unknown }).Telegram
    expect(canShareToStory()).toBe(false)
    expect(shareToStory('https://example.test/s.jpg', STORY)).toBe('unsupported')
  })

  it('opens the editor with the picture, the caption and the widget link, and says only "opened"', () => {
    expect(shareToStory('https://example.test/s.jpg', STORY)).toBe('opened')
    expect(sdkShareToStory).toHaveBeenCalledWith('https://example.test/s.jpg', STORY)
  })

  it('leaves the widget link out when the server sent none', () => {
    shareToStory('https://example.test/s.jpg', { text: STORY.text, widget_link: null })
    expect(sdkShareToStory).toHaveBeenCalledWith('https://example.test/s.jpg', { text: STORY.text })
  })

  it('says "failed" when the SDK refuses the picture', () => {
    expect(shareToStory('ftp://example.test/s.jpg', STORY)).toBe('failed')
  })
})
