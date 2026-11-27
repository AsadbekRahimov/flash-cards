import WebApp from '@twa-dev/sdk'

/**
 * The app has its own palette (docs/14 §4.1, kit Muhrla 2026-11-27): light and dark, both in `style.css`. Telegram only says which
 * of the two (`colorScheme`); its theme colours (`themeParams`) paint nothing. This module sets `<html data-theme>` and paints the
 * window chrome Telegram draws itself (the header above the app, the window background and the bottom bar) in the colours of the
 * palette: `bg/canvas` of the kit (docs/design/2026-11-27-muhrla-kit/docs/TELEGRAM.md).
 */
export type ColorScheme = 'light' | 'dark'

/**
 * The colours of the Telegram chrome per scheme: `--app-page` of `style.css` (`bg/canvas` of the kit; every screen, the lesson and
 * the rounds of the games too, stands on it). A copy of the CSS values, because the Telegram methods want a hex string;
 * `telegramTheme.spec.ts` checks that they agree, and that the start script `src/boot/theme-boot.js` (`<meta name="theme-color">`
 * before the bundle) uses the same colours.
 */
export const CHROME_COLORS: Readonly<Record<ColorScheme, Readonly<{ page: `#${string}` }>>> = {
  light: { page: '#f6f2ea' },
  dark: { page: '#121117' },
}

/** The part of `Telegram.WebApp` the theme code touches; the SDK object satisfies it, tests pass a stub. */
export interface ThemeHost {
  colorScheme: ColorScheme
  /** `unknown` outside Telegram (a browser, Playwright). */
  platform: string
  isVersionAtLeast(version: string): boolean
  setHeaderColor(color: 'bg_color' | 'secondary_bg_color' | `#${string}`): void
  setBackgroundColor(color: `#${string}`): void
  /** Bot API 7.10+; optional, so an older stub or SDK satisfies the type. */
  setBottomBarColor?(color: `#${string}`): void
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
 * Header, window background and bottom bar take the page colour of the scheme, so the Mini App has no seam under Telegram's header or
 * above its bottom bar. The window background takes a hex colour since Bot API 6.1, the header since 6.9, the bottom bar since 7.10;
 * a 6.1-6.8 client gets the keyword of its own page colour for the header (`secondary_bg_color`, the closest it can do). Never throws:
 * outside Telegram it is a no-op.
 */
export function paintTelegramChrome(host: ThemeHost = WebApp, scheme: ColorScheme = resolveColorScheme(host)): void {
  try {
    if (!host.isVersionAtLeast('6.1')) return
    const color = CHROME_COLORS[scheme].page
    host.setBackgroundColor(color)
    host.setHeaderColor(host.isVersionAtLeast('6.9') ? color : 'secondary_bg_color')
    if (host.isVersionAtLeast('7.10')) host.setBottomBarColor?.(color)
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

/**
 * Sets `<html data-theme="light|dark">` (the palette of `style.css`), `<meta name="theme-color">` and repaints the Telegram chrome
 * in the page colour. Never throws.
 */
export function applyTelegramTheme(
  host: ThemeHost = WebApp,
  root: HTMLElement = document.documentElement,
  query: SchemeQuery | null = systemSchemeQuery(),
): void {
  const scheme = resolveColorScheme(host, query)
  root.dataset.theme = scheme
  paintThemeColorMeta(scheme)
  paintTelegramChrome(host, scheme)
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
