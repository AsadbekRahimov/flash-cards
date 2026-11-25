import WebApp from '@twa-dev/sdk'

/**
 * The bits of Mini Apps 8.0 the installed `@twa-dev/sdk` (7.10) does not know (docs/36 §1а): its own copy of `telegram-web-apps.js`
 * replaces `window.Telegram.WebApp` on import, so `WebApp.shareMessage` is there only once the SDK is updated. Until then the message
 * goes the way the official script sends it: the event `web_app_send_prepared_message` through `Telegram.WebView`, answered by
 * `prepared_message_sent` or `prepared_message_failed {error}`.
 */
interface WebAppWithShareMessage {
  isVersionAtLeast?: (version: string) => boolean
  shareMessage?: (id: string, callback?: (sent: boolean) => void) => void
}

type WebViewHandler = (eventType: string, eventData: unknown) => void

interface TelegramWebView {
  postEvent: (eventType: string, callback: ((error?: unknown) => void) | false, eventData?: unknown) => void
  onEvent: (eventType: string, handler: WebViewHandler) => void
  offEvent: (eventType: string, handler: WebViewHandler) => void
}

/** The error of `prepared_message_failed` that is the learner closing the chat picker, not a failure. */
const USER_DECLINED = 'USER_DECLINED'

/**
 * How long the chat picker may stay open without an answer from Telegram. A client that says 8.0 but ignores the event would otherwise
 * keep the share button busy for good; past it the share counts as dropped (nothing is marked, no fallback).
 */
export const SHARE_MESSAGE_TIMEOUT_MS = 120_000

function telegramWebView(): TelegramWebView | null {
  if (typeof window === 'undefined') return null
  const webView = (window.Telegram as { WebView?: Partial<TelegramWebView> } | undefined)?.WebView
  return webView && typeof webView.postEvent === 'function' && typeof webView.onEvent === 'function' && typeof webView.offEvent === 'function'
    ? (webView as TelegramWebView)
    : null
}

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

  /**
   * Whether this client can send a message the server prepared (`shareMessage`, Mini Apps 8.0+, docs/36 §1в): a Telegram client of
   * 8.0 or newer and a way to ask it (the SDK method, or the WebView event the SDK 7.10 lacks a method for).
   */
  function canShareMessage(): boolean {
    const app = webApp as WebAppWithShareMessage | null
    try {
      if (!app || typeof app.isVersionAtLeast !== 'function' || !app.isVersionAtLeast('8.0')) return false
    } catch {
      return false
    }
    return typeof app.shareMessage === 'function' || telegramWebView() !== null
  }

  /**
   * Opens the chat picker for a prepared message (`POST /share/prepare` -> `id`). Resolves `true` once it is sent, `false` when the
   * learner closed the picker (or nothing can be sent here); rejects when Telegram could not send it (expired, failed), so the caller
   * can take the plain share dialog instead. Call it only from a tap.
   */
  function shareMessage(id: string): Promise<boolean> {
    if (!canShareMessage()) return Promise.resolve(false)
    const app = webApp as WebAppWithShareMessage
    if (typeof app.shareMessage === 'function') {
      // An SDK (or a script) that has the method: its callback says only "sent or not", so "not" counts as closed.
      return new Promise((resolve, reject) => {
        try {
          app.shareMessage?.(id, (sent) => resolve(sent === true))
        } catch (err) {
          reject(err instanceof Error ? err : new Error('share_message_failed'))
        }
      })
    }
    const webView = telegramWebView() as TelegramWebView
    return new Promise((resolve, reject) => {
      let done = false
      const finish = (outcome: () => void): void => {
        if (done) return
        done = true
        clearTimeout(timer)
        webView.offEvent('prepared_message_sent', onSent)
        webView.offEvent('prepared_message_failed', onFailed)
        outcome()
      }
      const onSent: WebViewHandler = () => finish(() => resolve(true))
      const onFailed: WebViewHandler = (_type, data) => {
        const error = (data as { error?: unknown } | null | undefined)?.error
        finish(() => (error === USER_DECLINED ? resolve(false) : reject(new Error(typeof error === 'string' ? error : 'share_message_failed'))))
      }
      const timer = setTimeout(() => finish(() => resolve(false)), SHARE_MESSAGE_TIMEOUT_MS)
      webView.onEvent('prepared_message_sent', onSent)
      webView.onEvent('prepared_message_failed', onFailed)
      try {
        webView.postEvent('web_app_send_prepared_message', (error) => {
          if (error) finish(() => reject(new Error('share_message_unavailable')))
        }, { id })
      } catch {
        finish(() => reject(new Error('share_message_unavailable')))
      }
    })
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
    canShareMessage,
    shareMessage,
    showBackButton,
  }
}
