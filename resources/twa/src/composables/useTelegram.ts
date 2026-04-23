import WebApp from '@twa-dev/sdk'

/**
 * Thin wrapper around @twa-dev/sdk with safe fallbacks when the TWA is
 * opened outside Telegram (e.g. for Playwright E2E runs with a stub).
 */
export function useTelegram() {
  const webApp = (typeof window !== 'undefined' && window.Telegram?.WebApp) ? WebApp : null

  function initData(): string {
    return webApp?.initData ?? ''
  }

  function isInTelegram(): boolean {
    return Boolean(initData())
  }

  function close(): void {
    try { webApp?.close() } catch { /* ignore */ }
  }

  function haptic(kind: 'light' | 'medium' | 'heavy' | 'rigid' | 'soft' = 'light'): void {
    try { webApp?.HapticFeedback?.impactOccurred(kind) } catch { /* ignore */ }
  }

  function selectionHaptic(): void {
    try { webApp?.HapticFeedback?.selectionChanged() } catch { /* ignore */ }
  }

  function notificationHaptic(kind: 'error' | 'success' | 'warning'): void {
    try { webApp?.HapticFeedback?.notificationOccurred(kind) } catch { /* ignore */ }
  }

  function showBackButton(handler: () => void): () => void {
    try {
      webApp?.BackButton?.show()
      webApp?.BackButton?.onClick(handler)
    } catch { /* ignore */ }
    return () => {
      try {
        webApp?.BackButton?.offClick(handler)
        webApp?.BackButton?.hide()
      } catch { /* ignore */ }
    }
  }

  return {
    webApp,
    initData,
    isInTelegram,
    close,
    haptic,
    selectionHaptic,
    notificationHaptic,
    showBackButton,
  }
}
