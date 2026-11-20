import WebApp from '@twa-dev/sdk'

/**
 * The app has its own palette (docs/14 §4.1, redesign 2026-11): light and dark, both in `style.css`. Telegram only says which
 * of the two (`colorScheme`); its theme colours (`themeParams`) paint nothing. This module sets `<html data-theme>` and paints the
 * window chrome Telegram draws itself (the header above the app and the window background) in the colours of the palette.
 */
export type ColorScheme = 'light' | 'dark'

/**
 * The colours of the Telegram chrome per scheme: the page colour (`--app-page` of `style.css`), so there is no seam under the
 * header. A copy of the CSS values, because the Telegram methods want a hex string; `telegramTheme.spec.ts` checks that both agree.
 */
export const CHROME_COLORS: Readonly<Record<ColorScheme, { page: `#${string}` }>> = {
  light: { page: '#f6f7f9' },
  dark: { page: '#11141c' },
}

/** The part of `Telegram.WebApp` the theme code touches; the SDK object satisfies it, tests pass a stub. */
export interface ThemeHost {
  colorScheme: ColorScheme
  /** `unknown` outside Telegram (a browser, Playwright). */
  platform: string
  isVersionAtLeast(version: string): boolean
  setHeaderColor(color: 'secondary_bg_color' | `#${string}`): void
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
 * Header and window background take the page colour of the scheme, so the Mini App has no seam under Telegram's header.
 * The window background takes a hex colour since Bot API 6.1, the header since 6.9; a 6.1-6.8 client gets the keyword of its
 * own page colour for the header (the closest it can do). Never throws: outside Telegram it is a no-op.
 */
export function paintTelegramChrome(host: ThemeHost = WebApp, scheme: ColorScheme = resolveColorScheme(host)): void {
  try {
    if (!host.isVersionAtLeast('6.1')) return
    const { page } = CHROME_COLORS[scheme]
    host.setBackgroundColor(page)
    host.setHeaderColor(host.isVersionAtLeast('6.9') ? page : 'secondary_bg_color')
  } catch {
    // outside Telegram — nothing to paint
  }
}

/** Sets `<html data-theme="light|dark">` (the palette of `style.css`) and repaints the Telegram chrome. Never throws. */
export function applyTelegramTheme(
  host: ThemeHost = WebApp,
  root: HTMLElement = document.documentElement,
  query: SchemeQuery | null = systemSchemeQuery(),
): void {
  const scheme = resolveColorScheme(host, query)
  root.dataset.theme = scheme
  paintTelegramChrome(host, scheme)
}

/**
 * Applies the scheme now and again when it changes: on `themeChanged` inside Telegram (the user switches the Telegram theme while
 * the app is open), on a change of `prefers-color-scheme` outside it.
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
