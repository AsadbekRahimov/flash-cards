import WebApp from '@twa-dev/sdk'

/** The error of `shareMessageFailed` that is the learner closing the chat picker, not a failure. */
const USER_DECLINED = 'USER_DECLINED'

/**
 * How long the chat picker may stay open without an answer from Telegram. A client that says 8.0 but ignores the request would otherwise
 * keep the share button busy for good; past it the share counts as dropped (nothing is marked, no fallback).
 */
export const SHARE_MESSAGE_TIMEOUT_MS = 120_000

/** The payload of the SDK event `shareMessageFailed` (`UNSUPPORTED`, `MESSAGE_EXPIRED`, `MESSAGE_SEND_FAILED`, `USER_DECLINED`, …). */
interface ShareMessageFailed {
  error?: string
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
   * 8.0 or newer and the method of the SDK (8.x has it; its own copy of `telegram-web-apps.js` replaces `window.Telegram.WebApp`).
   */
  function canShareMessage(): boolean {
    try {
      return Boolean(webApp)
        && typeof webApp?.isVersionAtLeast === 'function'
        && webApp.isVersionAtLeast('8.0')
        && typeof webApp.shareMessage === 'function'
    } catch {
      return false
    }
  }

  /**
   * Opens the chat picker for a prepared message (`POST /share/prepare` -> `id`). Resolves `true` once it is sent, `false` when the
   * learner closed the picker (or nothing can be sent here); rejects when Telegram could not send it (expired, failed) or the SDK refused
   * the call, so the caller can take the plain share dialog instead. Call it only from a tap.
   *
   * `WebApp.shareMessage` (SDK 8.x) calls back with "sent or not" only; the reason of a "not" comes right after it, in the same turn, as
   * the event `shareMessageFailed {error}`: `USER_DECLINED` is a closed picker, anything else a failure. So the events decide, and a
   * "not sent" without an event (a script that raises none) counts as closed, as before.
   */
  function shareMessage(id: string): Promise<boolean> {
    if (!canShareMessage() || !webApp) return Promise.resolve(false)
    const app = webApp
    return new Promise((resolve, reject) => {
      let done = false
      const finish = (outcome: () => void): void => {
        if (done) return
        done = true
        clearTimeout(timer)
        app.offEvent('shareMessageSent', onSent)
        app.offEvent('shareMessageFailed', onFailed)
        outcome()
      }
      const onSent = (): void => finish(() => resolve(true))
      const onFailed = (params: ShareMessageFailed): void => {
        const error = (params as ShareMessageFailed | null | undefined)?.error
        finish(() => (error === USER_DECLINED ? resolve(false) : reject(new Error(typeof error === 'string' ? error : 'share_message_failed'))))
      }
      const timer = setTimeout(() => finish(() => resolve(false)), SHARE_MESSAGE_TIMEOUT_MS)
      app.onEvent('shareMessageSent', onSent)
      app.onEvent('shareMessageFailed', onFailed)
      try {
        app.shareMessage(id, (sent) => {
          if (sent === true) finish(() => resolve(true))
          else queueMicrotask(() => finish(() => resolve(false)))
        })
      } catch (err) {
        // `WebAppMethodUnsupported` / `WebAppShareMessageOpened` (an earlier picker never answered): the plain dialog instead.
        finish(() => reject(err instanceof Error ? err : new Error('share_message_unavailable')))
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
