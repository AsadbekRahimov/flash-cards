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

  /** Telegram UI language of the current user (`initDataUnsafe.user.language_code`), if any. */
  function languageCode(): string | null {
    return webApp?.initDataUnsafe?.user?.language_code ?? null
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

  /**
   * Opens a Stars invoice inside Telegram and resolves with its final status. Outside Telegram (or
   * on a client without invoices) nothing can be paid, so it resolves `failed`.
   */
  function openInvoice(url: string): Promise<'paid' | 'cancelled' | 'failed' | 'pending'> {
    return new Promise((resolve) => {
      if (!webApp?.openInvoice) {
        resolve('failed')
        return
      }
      try {
        webApp.openInvoice(url, (status) => resolve(status))
      } catch {
        resolve('failed')
      }
    })
  }

  /** Opens a payment page (Payme, Click) in the external browser. */
  function openLink(url: string): void {
    try {
      if (webApp) webApp.openLink(url)
      else if (typeof window !== 'undefined') window.open(url, '_blank', 'noopener')
    } catch { /* ignore */ }
  }

  /** Opens a `t.me/...` link inside Telegram (a share dialog, a bot); outside Telegram it opens a tab. */
  function openTelegramLink(url: string): void {
    try {
      if (webApp?.openTelegramLink) webApp.openTelegramLink(url)
      else if (typeof window !== 'undefined') window.open(url, '_blank', 'noopener')
    } catch { /* ignore */ }
  }

  /** Telegram's "share" dialog for a link with a prefilled message (`t.me/share/url`). */
  function shareUrl(url: string, text: string): void {
    const params = new URLSearchParams({ url, text })
    openTelegramLink(`https://t.me/share/url?${params.toString()}`)
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
    languageCode,
    isInTelegram,
    close,
    haptic,
    selectionHaptic,
    notificationHaptic,
    openInvoice,
    openLink,
    openTelegramLink,
    shareUrl,
    showBackButton,
  }
}
