import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { CHROME_COLORS, resolveColorScheme, type SchemeQuery, type ThemeHost } from '@/composables/telegramTheme'
import { resolveLocale } from '@/i18n'

// vitest runs with cwd = resources/twa
const source = readFileSync(join(process.cwd(), 'src', 'boot', 'theme-boot.js'), 'utf8')
const FONT = '/twa/assets/source-serif-4-cyrillic-wght-normal-abc123.woff2'

type Win = Window & { Telegram?: unknown }

interface Situation {
  /** `undefined`: telegram-web-app.js did not load (no `window.Telegram`). */
  platform?: string
  colorScheme?: 'light' | 'dark'
  languageCode?: string | null
  /** `null`: no `matchMedia` at all. */
  systemDark: boolean | null
}

function setMatchMedia(value: unknown): void {
  Object.defineProperty(window, 'matchMedia', { value, configurable: true, writable: true })
}

/** Puts the situation into the window, runs the start script as the browser would, returns what it did. */
function boot(situation: Situation, withFontTag = true) {
  const win = window as Win
  if (situation.platform === undefined) {
    delete win.Telegram
  } else {
    const user = situation.languageCode === null ? undefined : { id: 1, language_code: situation.languageCode }
    win.Telegram = { WebApp: { platform: situation.platform, colorScheme: situation.colorScheme ?? 'light', initDataUnsafe: { user } } }
  }
  setMatchMedia(situation.systemDark === null ? undefined : (query: string) => ({ matches: query === '(prefers-color-scheme: dark)' && situation.systemDark === true, media: query }))
  if (withFontTag) {
    const tag = document.createElement('script')
    tag.setAttribute('data-cyrillic-font', FONT)
    document.head.append(tag)
  }

  new Function(source)()

  return {
    theme: document.documentElement.dataset.theme,
    themeColor: document.querySelector('meta[name="theme-color"]')?.getAttribute('content'),
    preload: document.head.querySelector<HTMLLinkElement>('link[rel="preload"][as="font"]'),
  }
}

/** The same situation through the code of the bundle (`resolveColorScheme`). */
function bundleScheme(situation: Situation) {
  const host = { platform: situation.platform ?? '', colorScheme: situation.colorScheme ?? 'light' } as ThemeHost
  const query: SchemeQuery | null = situation.systemDark === null ? null : { matches: situation.systemDark, addEventListener: () => {} }
  return resolveColorScheme(host, query)
}

describe('the start script (src/boot/theme-boot.js)', () => {
  let originalMatchMedia: unknown

  beforeEach(() => {
    originalMatchMedia = window.matchMedia
    const meta = document.createElement('meta')
    meta.name = 'theme-color'
    meta.content = '#f6f7f9'
    document.head.append(meta)
  })

  afterEach(() => {
    delete (window as Win).Telegram
    setMatchMedia(originalMatchMedia)
    document.head.innerHTML = ''
    delete document.documentElement.dataset.theme
  })

  const platforms = ['ios', 'android', 'tdesktop', 'weba', 'unknown', '', undefined]
  const schemes = ['light', 'dark'] as const
  const systems = [true, false, null]
  const cases: Situation[] = platforms.flatMap((platform) =>
    schemes.flatMap((colorScheme) => systems.map((systemDark) => ({ platform, colorScheme, systemDark }))),
  )

  it.each(cases)('chooses the palette exactly as the bundle does: %o', (situation) => {
    const scheme = bundleScheme(situation)

    const { theme, themeColor } = boot(situation)

    expect(theme).toBe(scheme)
    expect(themeColor).toBe(CHROME_COLORS[scheme].page)
  })

  it('makes a dark Telegram dark even on a light system, and the browser follows the system', () => {
    expect(boot({ platform: 'ios', colorScheme: 'dark', systemDark: false }).theme).toBe('dark')
    expect(boot({ platform: 'unknown', colorScheme: 'dark', systemDark: false }).theme).toBe('light')
  })

  describe('the preload of the cyrillic font', () => {
    it.each(['ru', 'ru-RU', 'en', 'en-GB', 'kk', '', null, 'uz', 'uz-UZ', 'UZ_latn', ' uz '])(
      'is there exactly when the interface will be Russian (language_code %o)',
      (languageCode) => {
        const { preload } = boot({ platform: 'android', languageCode, systemDark: false })

        expect(preload !== null).toBe(resolveLocale(languageCode) === 'ru')
        if (preload) {
          expect(preload.getAttribute('href')).toBe(FONT)
          expect(preload.type).toBe('font/woff2')
          expect(preload.crossOrigin).toBe('anonymous')
        }
      },
    )

    it('is there outside Telegram: the interface is Russian then', () => {
      expect(boot({ systemDark: false }).preload).not.toBeNull()
    })

    it('is not there without the URL of the font', () => {
      expect(boot({ platform: 'android', languageCode: 'ru', systemDark: false }, false).preload).toBeNull()
    })
  })

  it('never throws: a broken Telegram object and a broken matchMedia leave the light default', () => {
    Object.defineProperty(window, 'Telegram', {
      get() {
        throw new Error('blocked')
      },
      configurable: true,
    })
    setMatchMedia(() => {
      throw new Error('no media queries')
    })

    expect(() => new Function(source)()).not.toThrow()
    expect(document.documentElement.dataset.theme).toBeUndefined()

    Reflect.deleteProperty(window, 'Telegram')
  })

  it('is a classic script: no import / export, nothing left in the global scope', () => {
    expect(source).not.toMatch(/^\s*(import|export)\s/m)
    expect(source.trim()).toMatch(/^(\/\*[\s\S]*?\*\/\s*)?;\(function \(\) \{[\s\S]*\}\)\(\)$/)
  })
})
