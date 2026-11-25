import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SHARE_MESSAGE_TIMEOUT_MS, useTelegram } from '../useTelegram'

/** The SDK object: 7.10 has `isVersionAtLeast` and no `shareMessage` (a test adds one to play a newer SDK). */
const sdk = vi.hoisted(() => ({
  version: '8.0',
  isVersionAtLeast: vi.fn(),
  shareMessage: undefined as undefined | ((id: string, callback?: (sent: boolean) => void) => void),
}))

vi.mock('@twa-dev/sdk', () => ({ default: sdk }))

type Handler = (eventType: string, eventData: unknown) => void

/** `Telegram.WebView` of the SDK copy: posted events are recorded, Telegram's answers are played with `receive`. */
function fakeWebView() {
  const handlers = new Map<string, Handler[]>()
  const posted: { type: string; data: unknown }[] = []
  let postError: unknown = undefined
  return {
    posted,
    failPosting(error: unknown) {
      postError = error
    },
    handlerCount: (type: string) => handlers.get(type)?.length ?? 0,
    receive(type: string, data?: unknown) {
      for (const handler of [...(handlers.get(type) ?? [])]) handler(type, data)
    },
    postEvent: vi.fn((type: string, callback: ((error?: unknown) => void) | false, data?: unknown) => {
      posted.push({ type, data })
      if (callback) callback(postError)
    }),
    onEvent: vi.fn((type: string, handler: Handler) => {
      handlers.set(type, [...(handlers.get(type) ?? []), handler])
    }),
    offEvent: vi.fn((type: string, handler: Handler) => {
      handlers.set(type, (handlers.get(type) ?? []).filter((h) => h !== handler))
    }),
  }
}

let webView: ReturnType<typeof fakeWebView>

function versionAtLeast(version: string): boolean {
  const [a = 0, b = 0] = sdk.version.split('.').map(Number)
  const [c = 0, d = 0] = version.split('.').map(Number)
  return a > c || (a === c && b >= d)
}

describe('useTelegram().shareMessage', () => {
  beforeEach(() => {
    sdk.version = '8.0'
    sdk.shareMessage = undefined
    sdk.isVersionAtLeast.mockImplementation(versionAtLeast)
    webView = fakeWebView()
    ;(window as unknown as { Telegram: unknown }).Telegram = { WebApp: sdk, WebView: webView }
  })

  afterEach(() => {
    vi.useRealTimers()
    delete (window as unknown as { Telegram?: unknown }).Telegram
  })

  it('can share on 8.0+ through the WebView event the SDK 7.10 has no method for', () => {
    expect(useTelegram().canShareMessage()).toBe(true)
  })

  it.each(['7.10', '6.9'])('cannot share on %s', async (version) => {
    sdk.version = version
    const tg = useTelegram()

    expect(tg.canShareMessage()).toBe(false)
    await expect(tg.shareMessage('prep-1')).resolves.toBe(false)
    expect(webView.postEvent).not.toHaveBeenCalled()
  })

  it('cannot share outside Telegram', () => {
    delete (window as unknown as { Telegram?: unknown }).Telegram

    expect(useTelegram().canShareMessage()).toBe(false)
  })

  it('cannot share without a WebView to post to', () => {
    ;(window as unknown as { Telegram: unknown }).Telegram = { WebApp: sdk }

    expect(useTelegram().canShareMessage()).toBe(false)
  })

  it('posts web_app_send_prepared_message and resolves true on prepared_message_sent', async () => {
    const result = useTelegram().shareMessage('prep-1')

    expect(webView.posted).toEqual([{ type: 'web_app_send_prepared_message', data: { id: 'prep-1' } }])
    webView.receive('prepared_message_sent')

    await expect(result).resolves.toBe(true)
    expect(webView.handlerCount('prepared_message_sent')).toBe(0)
    expect(webView.handlerCount('prepared_message_failed')).toBe(0)
  })

  it('resolves false when the learner closes the picker (USER_DECLINED)', async () => {
    const result = useTelegram().shareMessage('prep-1')
    webView.receive('prepared_message_failed', { error: 'USER_DECLINED' })

    await expect(result).resolves.toBe(false)
  })

  it.each(['MESSAGE_EXPIRED', 'MESSAGE_SEND_FAILED', 'UNKNOWN_ERROR'])('rejects on %s', async (code) => {
    const result = useTelegram().shareMessage('prep-1')
    webView.receive('prepared_message_failed', { error: code })

    await expect(result).rejects.toThrow(code)
    expect(webView.handlerCount('prepared_message_failed')).toBe(0)
  })

  it('rejects when the event cannot be posted', async () => {
    webView.failPosting({ notAvailable: true })

    await expect(useTelegram().shareMessage('prep-1')).rejects.toThrow('share_message_unavailable')
    expect(webView.handlerCount('prepared_message_sent')).toBe(0)
  })

  it('gives the button back when Telegram never answers', async () => {
    vi.useFakeTimers()
    const result = useTelegram().shareMessage('prep-1')

    vi.advanceTimersByTime(SHARE_MESSAGE_TIMEOUT_MS)

    await expect(result).resolves.toBe(false)
    expect(webView.handlerCount('prepared_message_sent')).toBe(0)
  })

  it('uses the method of a newer SDK when there is one', async () => {
    sdk.shareMessage = vi.fn((_id: string, callback?: (sent: boolean) => void) => callback?.(true))
    const tg = useTelegram()

    await expect(tg.shareMessage('prep-1')).resolves.toBe(true)
    expect(sdk.shareMessage).toHaveBeenCalledWith('prep-1', expect.any(Function))
    expect(webView.postEvent).not.toHaveBeenCalled()

    sdk.shareMessage = vi.fn((_id: string, callback?: (sent: boolean) => void) => callback?.(false))
    await expect(tg.shareMessage('prep-2')).resolves.toBe(false)
  })
})
