import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import {
  THEME_VARIABLES,
  applyTelegramTheme,
  initTelegramTheme,
  paintTelegramChrome,
  type ThemeHost,
  type ThemeParamKey,
} from '../telegramTheme'

const FULL_THEME: Record<ThemeParamKey, string> = {
  bg_color: '#212121',
  text_color: '#ffffff',
  hint_color: '#aaaaaa',
  link_color: '#8774e1',
  button_color: '#8774e1',
  button_text_color: '#ffffff',
  secondary_bg_color: '#181818',
  section_bg_color: '#212121',
  accent_text_color: '#8774e1',
  destructive_text_color: '#ff595a',
  subtitle_text_color: '#a2acb4',
  section_header_text_color: '#8774e1',
  section_separator_color: '#000000',
  bottom_bar_bg_color: '#1c1c1d',
}

interface StubHost extends ThemeHost {
  isVersionAtLeast: Mock<(version: string) => boolean>
  setHeaderColor: Mock<(color: 'secondary_bg_color') => void>
  setBackgroundColor: Mock<(color: 'secondary_bg_color') => void>
  onEvent: Mock<(event: 'themeChanged', handler: () => void) => void>
}

function stubHost(overrides: Partial<ThemeHost> = {}): StubHost {
  return {
    themeParams: { ...FULL_THEME },
    colorScheme: 'dark',
    isVersionAtLeast: vi.fn<(version: string) => boolean>(() => true),
    setHeaderColor: vi.fn<(color: 'secondary_bg_color') => void>(),
    setBackgroundColor: vi.fn<(color: 'secondary_bg_color') => void>(),
    onEvent: vi.fn<(event: 'themeChanged', handler: () => void) => void>(),
    ...overrides,
  } as StubHost
}

describe('telegramTheme', () => {
  let root: HTMLElement

  beforeEach(() => {
    root = document.createElement('div')
  })

  it('maps all 14 theme parameters of docs/14 §4.1 to their CSS variables', () => {
    applyTelegramTheme(stubHost(), root)

    expect(Object.keys(THEME_VARIABLES)).toHaveLength(14)
    for (const [param, variable] of Object.entries(THEME_VARIABLES)) {
      expect(root.style.getPropertyValue(variable), `${param} -> ${variable}`).toBe(FULL_THEME[param as ThemeParamKey])
    }
  })

  it('every mapped variable has a fallback in style.css', () => {
    // vitest runs with cwd = resources/twa
    const css = readFileSync(join(process.cwd(), 'src', 'style.css'), 'utf8')

    for (const variable of Object.values(THEME_VARIABLES)) {
      expect(css, variable).toMatch(new RegExp(`^\\s+${variable}:`, 'm'))
    }
  })

  it('removes a parameter the client did not send, so the CSS fallback applies', () => {
    root.style.setProperty('--tg-section-bg', '#123456')
    root.style.setProperty('--tg-separator', '#654321')
    const { section_bg_color: _s, section_separator_color: _p, ...partial } = FULL_THEME

    applyTelegramTheme(stubHost({ themeParams: partial }), root)

    expect(root.style.getPropertyValue('--tg-section-bg')).toBe('')
    expect(root.style.getPropertyValue('--tg-separator')).toBe('')
    expect(root.style.getPropertyValue('--tg-bg')).toBe('#212121')
  })

  it('treats an empty value as missing', () => {
    applyTelegramTheme(stubHost({ themeParams: { ...FULL_THEME, hint_color: '' } }), root)

    expect(root.style.getPropertyValue('--tg-hint')).toBe('')
  })

  it('sets <html data-theme> from the colour scheme', () => {
    applyTelegramTheme(stubHost({ colorScheme: 'dark' }), root)
    expect(root.dataset.theme).toBe('dark')

    applyTelegramTheme(stubHost({ colorScheme: 'light' }), root)
    expect(root.dataset.theme).toBe('light')
  })

  it('paints the header and the window background with the page colour (Bot API 6.1+)', () => {
    const host = stubHost()

    applyTelegramTheme(host, root)

    expect(host.isVersionAtLeast).toHaveBeenCalledWith('6.1')
    expect(host.setHeaderColor).toHaveBeenCalledWith('secondary_bg_color')
    expect(host.setBackgroundColor).toHaveBeenCalledWith('secondary_bg_color')
  })

  it('leaves the header alone on a client older than 6.1', () => {
    const host = stubHost({ isVersionAtLeast: vi.fn(() => false) })

    applyTelegramTheme(host, root)

    expect(host.setHeaderColor).not.toHaveBeenCalled()
    expect(host.setBackgroundColor).not.toHaveBeenCalled()
    expect(root.style.getPropertyValue('--tg-bg')).toBe('#212121')
  })

  it('re-applies the theme on themeChanged', () => {
    const host = stubHost()
    initTelegramTheme(host, root)

    expect(host.onEvent).toHaveBeenCalledTimes(1)
    expect(host.onEvent.mock.calls[0]?.[0]).toBe('themeChanged')
    expect(root.dataset.theme).toBe('dark')

    // The user switches Telegram to the light theme (and the new one has no section colour).
    const { section_bg_color: _s, ...light } = FULL_THEME
    host.themeParams = { ...light, bg_color: '#ffffff', text_color: '#000000' }
    host.colorScheme = 'light'
    const handler = host.onEvent.mock.calls[0]?.[1] as () => void
    handler()

    expect(root.dataset.theme).toBe('light')
    expect(root.style.getPropertyValue('--tg-bg')).toBe('#ffffff')
    expect(root.style.getPropertyValue('--tg-text')).toBe('#000000')
    expect(root.style.getPropertyValue('--tg-section-bg')).toBe('')
    expect(host.setHeaderColor).toHaveBeenCalledTimes(2)
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

    expect(() => initTelegramTheme(broken, root)).not.toThrow()
    expect(() => paintTelegramChrome(broken)).not.toThrow()

    const noParams = {
      get themeParams(): Partial<Record<ThemeParamKey, string>> {
        throw new Error('not in Telegram')
      },
      colorScheme: 'light',
      isVersionAtLeast: () => false,
      setHeaderColor: () => {},
      setBackgroundColor: () => {},
      onEvent: () => {},
    } as ThemeHost

    expect(() => applyTelegramTheme(noParams, root)).not.toThrow()
    expect(root.dataset.theme).toBe('light')
    expect(root.style.getPropertyValue('--tg-bg')).toBe('')
  })
})
