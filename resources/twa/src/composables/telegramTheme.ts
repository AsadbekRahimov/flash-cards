import WebApp from '@twa-dev/sdk'

/**
 * The app has its own palette (docs/14 §4.1, redesign 2026-11): light and dark, both in `style.css`. Telegram only says which
 * of the two (`colorScheme`); its theme colours (`themeParams`) paint nothing. This module sets `<html data-theme>` and paints the
 * window chrome Telegram draws itself (the header above the app and the window background) in the colours of the palette.
 */
export type ColorScheme = 'light' | 'dark'

/**
 * What the Telegram chrome (the header above the app and the window background) is painted like: the page colour on the usual
 * screens, the card colour on the white lesson screens (`bg-surface`: training, exam, battle, practice and game tasks), so there
 * is no seam under the header either way (`useChromeSurface`).
 */
export type ChromeTone = 'page' | 'surface'

/**
 * The colours of the Telegram chrome per scheme and tone: `--app-page` and `--app-surface` of `style.css`. A copy of the CSS
 * values, because the Telegram methods want a hex string; `telegramTheme.spec.ts` checks that they agree, and that the start
 * script `src/boot/theme-boot.js` (`<meta name="theme-color">` before the bundle) uses the same page colours.
 */
export const CHROME_COLORS: Readonly<Record<ColorScheme, Readonly<Record<ChromeTone, `#${string}`>>>> = {
  light: { page: '#f6f7f9', surface: '#ffffff' },
  dark: { page: '#11141c', surface: '#191d28' },
}

/**
 * The header keyword of a Bot API 6.1-6.8 client (a hex header needs 6.9): Telegram's own page colour for the page tone, its own
 * card colour for the white screens - the closest such a client can do.
 */
const HEADER_KEYWORDS: Readonly<Record<ChromeTone, 'bg_color' | 'secondary_bg_color'>> = {
  page: 'secondary_bg_color',
  surface: 'bg_color',
}

/** The part of `Telegram.WebApp` the theme code touches; the SDK object satisfies it, tests pass a stub. */
export interface ThemeHost {
  colorScheme: ColorScheme
  /** `unknown` outside Telegram (a browser, Playwright). */
  platform: string
  isVersionAtLeast(version: string): boolean
  setHeaderColor(color: 'bg_color' | 'secondary_bg_color' | `#${string}`): void
  setBackgroundColor(color: `#${string}`): void
  onEvent(event: 'themeChanged', handler: () => void): void
}

/** The part of `MediaQueryList` of `(prefers-color-scheme: dark)` used outside Telegram; `null` where there is no `matchMedia`. */
export interface SchemeQuery {
  matches: boolean
  addEventListener(type: 'change', listener: () => void): void
}

function insideTelegram(host: ThemeHost): boolean {
  try {
    return typeof host.platform === 'string' && host.platform !== '' && host.platform !== 'unknown'
  } catch {
    return false
  }
}

function systemSchemeQuery(): SchemeQuery | null {
  try {
    return typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null
  } catch {
    return null
  }
}

/** Telegram's `colorScheme` inside Telegram; outside it the system preference (`prefers-color-scheme`), light when unknown. */
export function resolveColorScheme(host: ThemeHost = WebApp, query: SchemeQuery | null = systemSchemeQuery()): ColorScheme {
  if (insideTelegram(host)) {
    try {
      return host.colorScheme === 'dark' ? 'dark' : 'light'
    } catch {
      return 'light'
    }
  }
  return query?.matches === true ? 'dark' : 'light'
}

/**
 * Header and window background take the colour of the scheme and tone (the page colour unless a white screen holds the
 * surface tone), so the Mini App has no seam under Telegram's header. The window background takes a hex colour since Bot
 * API 6.1, the header since 6.9; a 6.1-6.8 client gets the keyword of its own page / card colour for the header (the closest
 * it can do). Never throws: outside Telegram it is a no-op.
 */
export function paintTelegramChrome(host: ThemeHost = WebApp, scheme: ColorScheme = resolveColorScheme(host), tone: ChromeTone = chromeTone()): void {
  try {
    if (!host.isVersionAtLeast('6.1')) return
    const color = CHROME_COLORS[scheme][tone]
    host.setBackgroundColor(color)
    host.setHeaderColor(host.isVersionAtLeast('6.9') ? color : HEADER_KEYWORDS[tone])
  } catch {
    // outside Telegram — nothing to paint
  }
}

/** `<meta name="theme-color">` (the browser bar outside Telegram) in the page colour; the start script sets it first. */
function paintThemeColorMeta(scheme: ColorScheme): void {
  try {
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', CHROME_COLORS[scheme].page)
  } catch {
    // no document — nothing to paint
  }
}

/** The host and query `initTelegramTheme` was started with: a change of the tone repaints with them. */
let themeContext: { host: ThemeHost; query: SchemeQuery | null } | null = null

/** How many white screens hold the surface tone right now (normally 0 or 1; a counter, so the order of mount/unmount does not matter). */
let surfaceHolds = 0

/** The tone of the chrome right now. */
export function chromeTone(): ChromeTone {
  return surfaceHolds > 0 ? 'surface' : 'page'
}

function repaintChrome(): void {
  const host = themeContext?.host ?? WebApp
  const query = themeContext ? themeContext.query : systemSchemeQuery()
  paintTelegramChrome(host, resolveColorScheme(host, query), chromeTone())
}

/**
 * A white screen takes the surface tone for the chrome; the returned function gives it back (once; a second call does nothing).
 * The chrome is repainted only when the tone really changes. Used through `useChromeSurface`.
 */
export function holdSurfaceChrome(): () => void {
  surfaceHolds += 1
  if (surfaceHolds === 1) repaintChrome()
  let held = true
  return () => {
    if (!held) return
    held = false
    surfaceHolds = Math.max(0, surfaceHolds - 1)
    if (surfaceHolds === 0) repaintChrome()
  }
}

/**
 * Sets `<html data-theme="light|dark">` (the palette of `style.css`), `<meta name="theme-color">` and repaints the Telegram chrome
 * in the current tone. Never throws.
 */
export function applyTelegramTheme(
  host: ThemeHost = WebApp,
  root: HTMLElement = document.documentElement,
  query: SchemeQuery | null = systemSchemeQuery(),
): void {
  const scheme = resolveColorScheme(host, query)
  root.dataset.theme = scheme
  paintThemeColorMeta(scheme)
  paintTelegramChrome(host, scheme, chromeTone())
}

/**
 * Applies the scheme now and again when it changes: on `themeChanged` inside Telegram (the user switches the Telegram theme while
 * the app is open), on a change of `prefers-color-scheme` outside it. The very first `data-theme` is set before the bundle by
 * `src/boot/theme-boot.js` (no light frame for a dark theme); this takes over from it.
 */
export function initTelegramTheme(
  host: ThemeHost = WebApp,
  root: HTMLElement = document.documentElement,
  query: SchemeQuery | null = systemSchemeQuery(),
): void {
  themeContext = { host, query }
  const apply = (): void => applyTelegramTheme(host, root, query)
  apply()
  if (insideTelegram(host)) {
    try {
      host.onEvent('themeChanged', apply)
    } catch {
      // no events — keep the scheme of the start
    }
    return
  }
  try {
    query?.addEventListener('change', apply)
  } catch {
    // an old WebView without addEventListener on MediaQueryList — keep the scheme of the start
  }
}
