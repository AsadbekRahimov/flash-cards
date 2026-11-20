import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import {
  CHROME_COLORS,
  applyTelegramTheme,
  chromeTone,
  holdSurfaceChrome,
  initTelegramTheme,
  paintTelegramChrome,
  resolveColorScheme,
  type SchemeQuery,
  type ThemeHost,
} from '../telegramTheme'

interface StubHost extends ThemeHost {
  isVersionAtLeast: Mock<(version: string) => boolean>
  setHeaderColor: Mock<(color: 'bg_color' | 'secondary_bg_color' | `#${string}`) => void>
  setBackgroundColor: Mock<(color: `#${string}`) => void>
  onEvent: Mock<(event: 'themeChanged', handler: () => void) => void>
}

function stubHost(overrides: Partial<ThemeHost> = {}): StubHost {
  return {
    colorScheme: 'dark',
    platform: 'ios',
    isVersionAtLeast: vi.fn<(version: string) => boolean>(() => true),
    setHeaderColor: vi.fn<(color: 'bg_color' | 'secondary_bg_color' | `#${string}`) => void>(),
    setBackgroundColor: vi.fn<(color: `#${string}`) => void>(),
    onEvent: vi.fn<(event: 'themeChanged', handler: () => void) => void>(),
    ...overrides,
  } as StubHost
}

/** A `(prefers-color-scheme: dark)` query whose answer a test can flip, firing `change` like the browser. */
function stubQuery(matches: boolean): SchemeQuery & { flip(next: boolean): void } {
  const listeners: (() => void)[] = []
  return {
    matches,
    addEventListener: (_type, listener) => listeners.push(listener),
    flip(next) {
      this.matches = next
      for (const listener of listeners) listener()
    },
  }
}

// vitest runs with cwd = resources/twa
const css = readFileSync(join(process.cwd(), 'src', 'style.css'), 'utf8')

describe('telegramTheme', () => {
  let root: HTMLElement

  beforeEach(() => {
    root = document.createElement('div')
  })

  describe('the scheme', () => {
    it("follows Telegram's colorScheme inside Telegram, whatever the system says", () => {
      expect(resolveColorScheme(stubHost({ colorScheme: 'dark' }), stubQuery(false))).toBe('dark')
      expect(resolveColorScheme(stubHost({ colorScheme: 'light' }), stubQuery(true))).toBe('light')
    })

    it('follows prefers-color-scheme outside Telegram (platform "unknown")', () => {
      const browser = stubHost({ platform: 'unknown', colorScheme: 'light' })

      expect(resolveColorScheme(browser, stubQuery(true))).toBe('dark')
      expect(resolveColorScheme(browser, stubQuery(false))).toBe('light')
    })

    it('is light outside Telegram when the browser cannot tell', () => {
      expect(resolveColorScheme(stubHost({ platform: 'unknown' }), null)).toBe('light')
    })
  })

  describe('applyTelegramTheme', () => {
    it('sets <html data-theme> from the scheme', () => {
      applyTelegramTheme(stubHost({ colorScheme: 'dark' }), root, null)
      expect(root.dataset.theme).toBe('dark')

      applyTelegramTheme(stubHost({ colorScheme: 'light' }), root, null)
      expect(root.dataset.theme).toBe('light')
    })

    it('writes none of the Telegram theme colours: the palette is the app’s own', () => {
      const host = { ...stubHost(), themeParams: { bg_color: '#212121', button_color: '#8774e1' } }

      applyTelegramTheme(host, root, null)

      expect(root.getAttribute('style') ?? '').toBe('')
    })

    it('paints the header and the window background in the page colour of the scheme (hex, Bot API 6.9+)', () => {
      const dark = stubHost({ colorScheme: 'dark' })
      applyTelegramTheme(dark, root, null)
      expect(dark.setBackgroundColor).toHaveBeenCalledWith(CHROME_COLORS.dark.page)
      expect(dark.setHeaderColor).toHaveBeenCalledWith(CHROME_COLORS.dark.page)

      const light = stubHost({ colorScheme: 'light' })
      applyTelegramTheme(light, root, null)
      expect(light.setBackgroundColor).toHaveBeenCalledWith(CHROME_COLORS.light.page)
      expect(light.setHeaderColor).toHaveBeenCalledWith(CHROME_COLORS.light.page)
    })
  })

  describe('paintTelegramChrome', () => {
    it('gives a 6.1-6.8 client the hex background and the keyword header (a hex header needs 6.9)', () => {
      const host = stubHost({ isVersionAtLeast: vi.fn((version: string) => version === '6.1') })

      paintTelegramChrome(host, 'light')

      expect(host.setBackgroundColor).toHaveBeenCalledWith('#f6f7f9')
      expect(host.setHeaderColor).toHaveBeenCalledWith('secondary_bg_color')
    })

    it('leaves the chrome alone on a client older than 6.1', () => {
      const host = stubHost({ isVersionAtLeast: vi.fn(() => false) })

      applyTelegramTheme(host, root, null)

      expect(host.setHeaderColor).not.toHaveBeenCalled()
      expect(host.setBackgroundColor).not.toHaveBeenCalled()
      expect(root.dataset.theme).toBe('dark')
    })

    it.each(['page', 'surface'] as const)('uses the very %s colours of style.css, light and dark', (tone) => {
      const light = new RegExp(`:root\\s*\\{[^}]*?--app-${tone}:\\s*(#[0-9a-f]{6})`, 'i').exec(css)?.[1]
      const dark = new RegExp(`:root\\[data-theme='dark'\\]\\s*\\{[^}]*?--app-${tone}:\\s*(#[0-9a-f]{6})`, 'i').exec(css)?.[1]

      expect(light?.toLowerCase()).toBe(CHROME_COLORS.light[tone])
      expect(dark?.toLowerCase()).toBe(CHROME_COLORS.dark[tone])
    })

    it('paints the surface tone: hex since 6.9, the keyword of Telegram\'s own card colour on 6.1-6.8', () => {
      const modern = stubHost()
      paintTelegramChrome(modern, 'dark', 'surface')
      expect(modern.setBackgroundColor).toHaveBeenCalledWith('#191d28')
      expect(modern.setHeaderColor).toHaveBeenCalledWith('#191d28')

      const old = stubHost({ isVersionAtLeast: vi.fn((version: string) => version === '6.1') })
      paintTelegramChrome(old, 'light', 'surface')
      expect(old.setBackgroundColor).toHaveBeenCalledWith('#ffffff')
      expect(old.setHeaderColor).toHaveBeenCalledWith('bg_color')
    })
  })

  describe('<meta name="theme-color">', () => {
    afterEach(() => document.head.querySelector('meta[name="theme-color"]')?.remove())

    it('takes the page colour of the scheme, and follows a change', () => {
      const meta = document.createElement('meta')
      meta.name = 'theme-color'
      meta.content = '#f6f7f9'
      document.head.append(meta)

      const host = stubHost({ colorScheme: 'dark' })
      initTelegramTheme(host, root, null)
      expect(meta.content).toBe(CHROME_COLORS.dark.page)

      host.colorScheme = 'light'
      ;(host.onEvent.mock.calls[0]?.[1] as () => void)()
      expect(meta.content).toBe(CHROME_COLORS.light.page)
    })

    it('is no error when the page has no such tag', () => {
      expect(() => applyTelegramTheme(stubHost(), root, null)).not.toThrow()
    })
  })

  describe('the surface tone of the white screens (holdSurfaceChrome)', () => {
    it('paints the chrome in the surface colour while held, in the page colour again when given back', () => {
      const host = stubHost({ colorScheme: 'light' })
      initTelegramTheme(host, root, null)
      host.setHeaderColor.mockClear()
      host.setBackgroundColor.mockClear()

      const release = holdSurfaceChrome()
      expect(chromeTone()).toBe('surface')
      expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.surface)
      expect(host.setBackgroundColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.surface)

      release()
      expect(chromeTone()).toBe('page')
      expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.page)
      expect(host.setBackgroundColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.page)
    })

    it('keeps the surface tone across a theme change: the surface of the new palette', () => {
      const host = stubHost({ colorScheme: 'light' })
      initTelegramTheme(host, root, null)
      const release = holdSurfaceChrome()

      host.colorScheme = 'dark'
      ;(host.onEvent.mock.calls[0]?.[1] as () => void)()
      expect(root.dataset.theme).toBe('dark')
      expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.surface)

      release()
      expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.page)
    })

    it('counts the holds: the order of mount and unmount of two screens does not matter, a second release does nothing', () => {
      const host = stubHost()
      initTelegramTheme(host, root, null)
      host.setHeaderColor.mockClear()

      const first = holdSurfaceChrome()
      const second = holdSurfaceChrome()
      expect(host.setHeaderColor).toHaveBeenCalledTimes(1) // painted once, not per hold

      first()
      first()
      expect(chromeTone()).toBe('surface')
      expect(host.setHeaderColor).toHaveBeenCalledTimes(1)

      second()
      expect(chromeTone()).toBe('page')
      expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.page)
    })

    it('never throws outside Telegram', () => {
      initTelegramTheme(stubHost({ isVersionAtLeast: vi.fn(() => { throw new Error('not in Telegram') }) }), root, null)
      expect(() => holdSurfaceChrome()()).not.toThrow()
    })
  })

  describe('initTelegramTheme', () => {
    it('re-applies the scheme on themeChanged inside Telegram', () => {
      const host = stubHost()
      const query = stubQuery(false)
      initTelegramTheme(host, root, query)

      expect(host.onEvent).toHaveBeenCalledTimes(1)
      expect(host.onEvent.mock.calls[0]?.[0]).toBe('themeChanged')
      expect(root.dataset.theme).toBe('dark')

      // The user switches Telegram to the light theme.
      host.colorScheme = 'light'
      const handler = host.onEvent.mock.calls[0]?.[1] as () => void
      handler()

      expect(root.dataset.theme).toBe('light')
      expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.page)
      // the system preference does not move the app inside Telegram
      query.flip(true)
      expect(root.dataset.theme).toBe('light')
    })

    it('follows a change of prefers-color-scheme outside Telegram', () => {
      const host = stubHost({ platform: 'unknown' })
      const query = stubQuery(false)
      initTelegramTheme(host, root, query)

      expect(root.dataset.theme).toBe('light')
      expect(host.onEvent).not.toHaveBeenCalled()

      query.flip(true)
      expect(root.dataset.theme).toBe('dark')
    })

    it('is a no-op outside Telegram: nothing throws', () => {
      const broken = stubHost({
        isVersionAtLeast: vi.fn(() => {
          throw new Error('not in Telegram')
        }),
        onEvent: vi.fn(() => {
          throw new Error('not in Telegram')
        }),
      })

      expect(() => initTelegramTheme(broken, root, null)).not.toThrow()
      expect(() => paintTelegramChrome(broken, 'dark')).not.toThrow()

      const noHost = {
        get platform(): string {
          throw new Error('not in Telegram')
        },
        colorScheme: 'dark',
        isVersionAtLeast: () => false,
        setHeaderColor: () => {},
        setBackgroundColor: () => {},
        onEvent: () => {},
      } as ThemeHost

      expect(() => applyTelegramTheme(noHost, root, stubQuery(false))).not.toThrow()
      expect(root.dataset.theme).toBe('light')

      const oldQuery: SchemeQuery = {
        matches: true,
        addEventListener: () => {
          throw new Error('no addEventListener on MediaQueryList')
        },
      }
      expect(() => initTelegramTheme(stubHost({ platform: 'unknown' }), root, oldQuery)).not.toThrow()
      expect(root.dataset.theme).toBe('dark')
    })
  })
})
