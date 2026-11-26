import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SHARE_MESSAGE_TIMEOUT_MS, useTelegram } from '../useTelegram'

type Callback = (sent: boolean) => void

/**
 * The SDK 8.x object, played the way its `telegram-web-apps.js` behaves: `shareMessage(id, callback)` posts
 * `web_app_send_prepared_message`; Telegram's answer calls the callback first (`true` / `false`) and then raises `shareMessageSent` or
 * `shareMessageFailed {error}` to the `onEvent` handlers, in the same turn (`answer`). A second call while a picker is open throws
 * `WebAppShareMessageOpened`, a client below 8.0 throws `WebAppMethodUnsupported`.
 */
const sdk = vi.hoisted(() => {
  const handlers = new Map<string, ((params?: unknown) => void)[]>()
  const state = {
    version: '8.0',
    open: null as null | { callback?: (sent: boolean) => void },
    handlers,
    isVersionAtLeast: vi.fn(),
    shareMessage: undefined as undefined | ((id: string, callback?: (sent: boolean) => void) => void),
    onEvent: vi.fn((type: string, handler: (params?: unknown) => void) => {
      handlers.set(type, [...(handlers.get(type) ?? []), handler])
    }),
    offEvent: vi.fn((type: string, handler: (params?: unknown) => void) => {
      handlers.set(type, (handlers.get(type) ?? []).filter((h) => h !== handler))
    }),
  }
  return state
})

vi.mock('@twa-dev/sdk', () => ({ default: sdk }))

function versionAtLeast(version: string): boolean {
  const [a = 0, b = 0] = sdk.version.split('.').map(Number)
  const [c = 0, d = 0] = version.split('.').map(Number)
  return a > c || (a === c && b >= d)
}

/** The method of the SDK 8.x copy of `telegram-web-apps.js`. */
const sdkShareMessage = vi.fn((_id: string, callback?: Callback) => {
  if (!versionAtLeast('8.0')) throw new Error('WebAppMethodUnsupported')
  if (sdk.open) throw new Error('WebAppShareMessageOpened')
  sdk.open = { callback }
})

/** Telegram answers: `prepared_message_sent` or `prepared_message_failed {error}`, as the SDK hands them on. */
function answer(error?: string): void {
  const request = sdk.open
  if (!request) return
  sdk.open = null
  request.callback?.(error === undefined)
  const type = error === undefined ? 'shareMessageSent' : 'shareMessageFailed'
  for (const handler of [...(sdk.handlers.get(type) ?? [])]) handler(error === undefined ? undefined : { error })
}

const handlerCount = (type: string): number => sdk.handlers.get(type)?.length ?? 0

describe('useTelegram().shareMessage', () => {
  beforeEach(() => {
    sdk.version = '8.0'
    sdk.open = null
    sdk.handlers.clear()
    sdk.shareMessage = sdkShareMessage
    sdkShareMessage.mockClear()
    sdk.isVersionAtLeast.mockImplementation(versionAtLeast)
    ;(window as unknown as { Telegram: unknown }).Telegram = { WebApp: sdk }
  })

  afterEach(() => {
    vi.useRealTimers()
    delete (window as unknown as { Telegram?: unknown }).Telegram
  })

  it('can share on 8.0+ through WebApp.shareMessage of the SDK', () => {
    expect(useTelegram().canShareMessage()).toBe(true)
  })

  it.each(['7.10', '6.9'])('cannot share on %s, and does not call the SDK', async (version) => {
    sdk.version = version
    const tg = useTelegram()

    expect(tg.canShareMessage()).toBe(false)
    await expect(tg.shareMessage('prep-1')).resolves.toBe(false)
    expect(sdkShareMessage).not.toHaveBeenCalled()
  })

  it('cannot share outside Telegram', () => {
    delete (window as unknown as { Telegram?: unknown }).Telegram

    expect(useTelegram().canShareMessage()).toBe(false)
  })

  it('cannot share without the method (a script that has none)', () => {
    sdk.shareMessage = undefined

    expect(useTelegram().canShareMessage()).toBe(false)
  })

  it('cannot share when the version check itself throws', () => {
    sdk.isVersionAtLeast.mockImplementation(() => {
      throw new Error('broken')
    })

    expect(useTelegram().canShareMessage()).toBe(false)
  })

  it('calls WebApp.shareMessage once and resolves true when it is sent', async () => {
    const result = useTelegram().shareMessage('prep-1')

    expect(sdkShareMessage).toHaveBeenCalledTimes(1)
    expect(sdkShareMessage).toHaveBeenCalledWith('prep-1', expect.any(Function))
    answer()

    await expect(result).resolves.toBe(true)
    expect(handlerCount('shareMessageSent')).toBe(0)
    expect(handlerCount('shareMessageFailed')).toBe(0)
  })

  it('resolves false when the learner closes the picker (USER_DECLINED)', async () => {
    const result = useTelegram().shareMessage('prep-1')
    answer('USER_DECLINED')

    await expect(result).resolves.toBe(false)
  })

  it.each(['MESSAGE_EXPIRED', 'MESSAGE_SEND_FAILED', 'UNKNOWN_ERROR', 'UNSUPPORTED'])('rejects on %s', async (code) => {
    const result = useTelegram().shareMessage('prep-1')
    answer(code)

    await expect(result).rejects.toThrow(code)
    expect(handlerCount('shareMessageFailed')).toBe(0)
  })

  it('counts a "not sent" without an event as a closed picker', async () => {
    sdk.shareMessage = vi.fn((_id: string, callback?: Callback) => callback?.(false))

    await expect(useTelegram().shareMessage('prep-1')).resolves.toBe(false)
    expect(handlerCount('shareMessageFailed')).toBe(0)
  })

  it('rejects when the SDK refuses the call (a picker still open), so the plain dialog can take over', async () => {
    sdk.open = {}

    await expect(useTelegram().shareMessage('prep-1')).rejects.toThrow('WebAppShareMessageOpened')
    expect(handlerCount('shareMessageSent')).toBe(0)
  })

  it('gives the button back when Telegram never answers', async () => {
    vi.useFakeTimers()
    const result = useTelegram().shareMessage('prep-1')

    vi.advanceTimersByTime(SHARE_MESSAGE_TIMEOUT_MS)

    await expect(result).resolves.toBe(false)
    expect(handlerCount('shareMessageSent')).toBe(0)
  })

  it('settles once: a late answer after the timeout changes nothing', async () => {
    vi.useFakeTimers()
    const result = useTelegram().shareMessage('prep-1')
    vi.advanceTimersByTime(SHARE_MESSAGE_TIMEOUT_MS)
    answer('MESSAGE_SEND_FAILED')

    await expect(result).resolves.toBe(false)
  })
})
