import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import {
  CHROME_COLORS,
  applyTelegramTheme,
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
  setBottomBarColor: Mock<(color: `#${string}`) => void>
  onEvent: Mock<(event: 'themeChanged', handler: () => void) => void>
}

function stubHost(overrides: Partial<ThemeHost> = {}): StubHost {
  return {
    colorScheme: 'dark',
    platform: 'ios',
    isVersionAtLeast: vi.fn<(version: string) => boolean>(() => true),
    setHeaderColor: vi.fn<(color: 'bg_color' | 'secondary_bg_color' | `#${string}`) => void>(),
    setBackgroundColor: vi.fn<(color: `#${string}`) => void>(),
    setBottomBarColor: vi.fn<(color: `#${string}`) => void>(),
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

      expect(host.setBackgroundColor).toHaveBeenCalledWith('#f6f2ea')
      expect(host.setHeaderColor).toHaveBeenCalledWith('secondary_bg_color')
      expect(host.setBottomBarColor).not.toHaveBeenCalled()
    })

    it('paints the bottom bar in the canvas colour too on Bot API 7.10+ (kit TELEGRAM.md), and lives without the method', () => {
      const modern = stubHost()
      paintTelegramChrome(modern, 'light')
      expect(modern.setBottomBarColor).toHaveBeenCalledWith('#f6f2ea')

      const dark = stubHost()
      paintTelegramChrome(dark, 'dark')
      expect(dark.setBottomBarColor).toHaveBeenCalledWith('#121117')

      const older = stubHost({ isVersionAtLeast: vi.fn((version: string) => version !== '7.10') })
      paintTelegramChrome(older, 'light')
      expect(older.setBottomBarColor).not.toHaveBeenCalled()
      expect(older.setHeaderColor).toHaveBeenCalledWith('#f6f2ea')

      const noMethod = { ...stubHost(), setBottomBarColor: undefined }
      expect(() => paintTelegramChrome(noMethod, 'light')).not.toThrow()
      expect(noMethod.setHeaderColor).toHaveBeenCalledWith('#f6f2ea')
    })

    it('leaves the chrome alone on a client older than 6.1', () => {
      const host = stubHost({ isVersionAtLeast: vi.fn(() => false) })

      applyTelegramTheme(host, root, null)

      expect(host.setHeaderColor).not.toHaveBeenCalled()
      expect(host.setBackgroundColor).not.toHaveBeenCalled()
      expect(root.dataset.theme).toBe('dark')
    })

    it('uses the very page colours of style.css, light and dark', () => {
      const light = /:root\s*\{[^}]*?--app-page:\s*(#[0-9a-f]{6})/i.exec(css)?.[1]
      const dark = /:root\[data-theme='dark'\]\s*\{[^}]*?--app-page:\s*(#[0-9a-f]{6})/i.exec(css)?.[1]

      expect(light?.toLowerCase()).toBe(CHROME_COLORS.light.page)
      expect(dark?.toLowerCase()).toBe(CHROME_COLORS.dark.page)
    })
  })

  describe('<meta name="theme-color">', () => {
    afterEach(() => document.head.querySelector('meta[name="theme-color"]')?.remove())

    it('takes the page colour of the scheme, and follows a change', () => {
      const meta = document.createElement('meta')
      meta.name = 'theme-color'
      meta.content = '#f6f2ea'
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

// The kit Muhrla paints the Telegram chrome in the canvas only (TELEGRAM.md): no screen is white as a whole, the lesson and the rounds
// of the games stand on the canvas too, and the boards they share take it for their sticky blocks (`--app-screen`, StickyActions).
describe('every screen stands on the canvas', () => {
  // vitest runs with cwd = resources/twa
  const views = readdirSync(join(process.cwd(), 'src', 'views'))
    .filter((file) => file.endsWith('.vue'))
    .map((file) => ({ file, code: readFileSync(join(process.cwd(), 'src', 'views', file), 'utf8') }))

  it.each(['TrainingView.vue', 'ExamView.vue', 'PracticeSessionView.vue', 'BattleView.vue', 'CupRoundView.vue', 'GameRunView.vue'])(
    '%s: the canvas, and the canvas for the sticky blocks of its boards',
    (file) => {
      const code = views.find((view) => view.file === file)?.code ?? ''
      expect(code).toContain('flex-1 flex-col bg-page"')
      expect(code).toContain('--app-screen: var(--app-page)')
    },
  )

  it('no screen is white as a whole (a static or bound `bg-surface` on the whole screen)', () => {
    const white = views.filter(({ code }) => /flex-1 flex-col bg-surface"|[?:] 'bg-surface'/.test(code)).map(({ file }) => file)
    expect(white).toEqual([])
  })
})
