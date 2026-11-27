import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useTelegram } from '../useTelegram'

/**
 * `requestWriteAccess` of the onboarding's O7 (docs/40 §2.1): the SDK 8.x method (Bot API 6.9) asks Telegram and calls back with
 * `true` / `false`; below 6.9 it throws `WebAppMethodUnsupported`, a second call while one waits throws `WebAppWriteAccessRequested`.
 */
const sdk = vi.hoisted(() => ({
  version: '7.0',
  pending: null as null | ((granted: boolean) => void),
  isVersionAtLeast: vi.fn(),
  requestWriteAccess: vi.fn(),
  initDataUnsafe: { user: { first_name: 'Aziz' } } as { user?: { first_name?: string } },
}))

vi.mock('@twa-dev/sdk', () => ({ default: sdk }))

function versionAtLeast(version: string): boolean {
  const [a = 0, b = 0] = sdk.version.split('.').map(Number)
  const [c = 0, d = 0] = version.split('.').map(Number)
  return a > c || (a === c && b >= d)
}

describe('useTelegram: write access and the first name', () => {
  beforeEach(() => {
    sdk.version = '7.0'
    sdk.pending = null
    sdk.initDataUnsafe = { user: { first_name: 'Aziz' } }
    sdk.isVersionAtLeast.mockImplementation(versionAtLeast)
    sdk.requestWriteAccess.mockReset()
    sdk.requestWriteAccess.mockImplementation((callback: (granted: boolean) => void) => {
      if (!versionAtLeast('6.9')) throw new Error('WebAppMethodUnsupported')
      if (sdk.pending) throw new Error('WebAppWriteAccessRequested')
      sdk.pending = callback
    })
    ;(window as unknown as { Telegram: unknown }).Telegram = { WebApp: sdk }
  })

  afterEach(() => {
    delete (window as unknown as { Telegram?: unknown }).Telegram
  })

  function answer(granted: boolean): void {
    const callback = sdk.pending
    sdk.pending = null
    callback?.(granted)
  }

  it('asks Telegram on 6.9+ and resolves granted / denied by the answer', async () => {
    const tg = useTelegram()
    expect(tg.canRequestWriteAccess()).toBe(true)

    const first = tg.requestWriteAccess()
    answer(true)
    await expect(first).resolves.toBe('granted')

    const second = tg.requestWriteAccess()
    answer(false)
    await expect(second).resolves.toBe('denied')
    expect(sdk.requestWriteAccess).toHaveBeenCalledTimes(2)
  })

  it('shares one request while Telegram has not answered yet', async () => {
    const tg = useTelegram()

    const a = tg.requestWriteAccess()
    const b = tg.requestWriteAccess()
    answer(true)

    await expect(a).resolves.toBe('granted')
    await expect(b).resolves.toBe('granted')
    expect(sdk.requestWriteAccess).toHaveBeenCalledTimes(1)
  })

  it('cannot ask below 6.9 and outside Telegram: unsupported, the SDK is not called', async () => {
    sdk.version = '6.4'
    const tg = useTelegram()
    expect(tg.canRequestWriteAccess()).toBe(false)
    await expect(tg.requestWriteAccess()).resolves.toBe('unsupported')

    delete (window as unknown as { Telegram?: unknown }).Telegram
    expect(useTelegram().canRequestWriteAccess()).toBe(false)
    await expect(useTelegram().requestWriteAccess()).resolves.toBe('unsupported')
    expect(sdk.requestWriteAccess).not.toHaveBeenCalled()
  })

  it('reads an SDK that throws after all (a request made elsewhere) as unsupported', async () => {
    sdk.requestWriteAccess.mockImplementation(() => {
      throw new Error('WebAppWriteAccessRequested')
    })

    await expect(useTelegram().requestWriteAccess()).resolves.toBe('unsupported')
  })

  it('gives the first name of the page as a hint, null when there is none', () => {
    expect(useTelegram().firstName()).toBe('Aziz')
    sdk.initDataUnsafe = { user: { first_name: '  ' } }
    expect(useTelegram().firstName()).toBeNull()
    delete (window as unknown as { Telegram?: unknown }).Telegram
    expect(useTelegram().firstName()).toBeNull()
  })
})
