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

/** The write-access request waiting for Telegram's answer: the SDK takes one at a time. */
let pendingWriteAccess: Promise<'granted' | 'denied' | 'unsupported'> | null = null

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
      // The button is freed, but the SDK keeps its `WebAppShareMessageOpened` until the client answers - it has no public reset, so
      // every later call in this session throws and takes the plain dialog (docs/36 §1в: a soft degradation, the SDK is not patched).
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

  /**
   * The first name of the user as Telegram gives it to the page (`initDataUnsafe.user.first_name`): only a hint to prefill a field
   * (the onboarding's O2), never trusted - the server takes nothing from it. Null outside Telegram.
   */
  function firstName(): string | null {
    const name = webApp?.initDataUnsafe?.user?.first_name
    return typeof name === 'string' && name.trim() !== '' ? name : null
  }

  /** Whether this client can ask for the permission to receive messages from the bot (`requestWriteAccess`, Bot API 6.9+). */
  function canRequestWriteAccess(): boolean {
    try {
      return Boolean(webApp)
        && typeof webApp?.isVersionAtLeast === 'function'
        && webApp.isVersionAtLeast('6.9')
        && typeof webApp.requestWriteAccess === 'function'
    } catch {
      return false
    }
  }

  /**
   * Asks Telegram to let the bot write to the user (the onboarding's O7): `granted` / `denied` by the user's answer (a user who already
   * started the bot is answered `granted` at once), `unsupported` where the client cannot ask. A request still waiting for its answer
   * (`WebAppWriteAccessRequested`) is answered by that one. Call it only from a tap.
   */
  function requestWriteAccess(): Promise<'granted' | 'denied' | 'unsupported'> {
    if (pendingWriteAccess !== null) return pendingWriteAccess
    if (!canRequestWriteAccess() || !webApp) return Promise.resolve('unsupported')
    const app = webApp
    const pending = new Promise<'granted' | 'denied' | 'unsupported'>((resolve) => {
      try {
        app.requestWriteAccess((granted) => resolve(granted === true ? 'granted' : 'denied'))
      } catch {
        // `WebAppMethodUnsupported` (an older client than it said) or `WebAppWriteAccessRequested` (asked outside this wrapper).
        resolve('unsupported')
      }
    }).finally(() => {
      pendingWriteAccess = null
    })
    pendingWriteAccess = pending
    return pending
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
    firstName,
    canRequestWriteAccess,
    requestWriteAccess,
    showBackButton,
  }
}
