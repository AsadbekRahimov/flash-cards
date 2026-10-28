import WebApp from '@twa-dev/sdk'

/**
 * `Telegram.WebApp.themeParams` key → the CSS variable of `style.css` it feeds (docs/14 §4.1).
 * `header_bg_color` is not mapped: the window header is painted by Telegram itself (see `paintTelegramChrome`).
 */
export const THEME_VARIABLES = {
  bg_color: '--tg-bg',
  text_color: '--tg-text',
  hint_color: '--tg-hint',
  link_color: '--tg-link',
  button_color: '--tg-button',
  button_text_color: '--tg-button-text',
  secondary_bg_color: '--tg-secondary-bg',
  section_bg_color: '--tg-section-bg',
  accent_text_color: '--tg-accent',
  destructive_text_color: '--tg-destructive',
  subtitle_text_color: '--tg-subtitle',
  section_header_text_color: '--tg-section-header',
  section_separator_color: '--tg-separator',
  bottom_bar_bg_color: '--tg-bottom-bar-bg',
} as const

export type ThemeParamKey = keyof typeof THEME_VARIABLES

/** The part of `Telegram.WebApp` the theme code touches; the SDK object satisfies it, tests pass a stub. */
export interface ThemeHost {
  themeParams: Partial<Record<ThemeParamKey, string>>
  colorScheme: 'light' | 'dark'
  isVersionAtLeast(version: string): boolean
  setHeaderColor(color: 'secondary_bg_color'): void
  setBackgroundColor(color: 'secondary_bg_color'): void
  onEvent(event: 'themeChanged', handler: () => void): void
}

/**
 * Header and window background take the page colour (`secondary_bg_color`), so the Mini App has no seam under
 * Telegram's header; both calls exist since Bot API 6.1. Never throws: outside Telegram it is a no-op.
 */
export function paintTelegramChrome(host: ThemeHost = WebApp): void {
  try {
    if (!host.isVersionAtLeast('6.1')) return
    host.setHeaderColor('secondary_bg_color')
    host.setBackgroundColor('secondary_bg_color')
  } catch {
    // outside Telegram — nothing to paint
  }
}

/**
 * Copies the current Telegram theme into the CSS variables on `<html>` (docs/14 §4.1). A parameter the client did
 * not send is removed again, so the fallback from `style.css` applies instead of a value left by the previous theme.
 * Also sets `<html data-theme="light|dark">` and repaints the Telegram header. Never throws.
 */
export function applyTelegramTheme(host: ThemeHost = WebApp, root: HTMLElement = document.documentElement): void {
  let params: Partial<Record<ThemeParamKey, string>> = {}
  let scheme: 'light' | 'dark' = 'light'
  try {
    params = host.themeParams ?? {}
    scheme = host.colorScheme === 'dark' ? 'dark' : 'light'
  } catch {
    // outside Telegram — keep the CSS fallbacks
  }

  for (const key of Object.keys(THEME_VARIABLES) as ThemeParamKey[]) {
    const value = params[key]
    if (typeof value === 'string' && value !== '') root.style.setProperty(THEME_VARIABLES[key], value)
    else root.style.removeProperty(THEME_VARIABLES[key])
  }
  root.dataset.theme = scheme

  paintTelegramChrome(host)
}

/** Applies the theme now and again on every `themeChanged` (the user switches the Telegram theme while the app is open). */
export function initTelegramTheme(host: ThemeHost = WebApp, root: HTMLElement = document.documentElement): void {
  applyTelegramTheme(host, root)
  try {
    host.onEvent('themeChanged', () => applyTelegramTheme(host, root))
  } catch {
    // outside Telegram — no events
  }
}
