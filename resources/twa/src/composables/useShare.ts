import { readonly, ref } from 'vue'
import { api } from '@/api'
import type { ShareEventRequest, ShareKind } from '@/api/shareTypes'
import { useTelegram } from './useTelegram'

export type { ShareKind } from '@/api/shareTypes'

/** The plain share dialog (`t.me/share/url`) - what every share opened before, and still the way out of any failure. */
export interface ShareFallback {
  link: string
  text: string
}

/**
 * How a share ended: `sent` - the message of the bot went out; `fallback` - the plain dialog was opened instead; `cancelled` - the learner
 * closed the chat picker; `busy` - another share of this screen is still on its way (the tap is ignored).
 */
export type ShareOutcome = 'sent' | 'fallback' | 'cancelled' | 'busy'

/**
 * "Share" through the bot (docs/36 §1в, docs/04 A.22): on a client of Mini Apps 8.0+ the server prepares a message with the card and the
 * button (`POST /share/prepare`), Telegram's chat picker sends it (`shareMessage`); `onShared` (the "shared" mark) runs once it is sent.
 * A client below 8.0 opens the plain dialog at once, without asking the server; any refusal of prepare (403/404/422/429/503, the network)
 * or a failed send opens the plain dialog with the same link and text, once, without a retry - `onShared` then runs as it always did,
 * when the dialog opens. Closing the picker is neither: nothing is marked, nothing reported. Each way reports itself
 * (`POST /share/events`: `sent`, `fallback {old_client | error}`) without waiting; a failed report changes nothing.
 *
 * `pending` is true while a share is on its way: a second tap meanwhile does nothing (the button shows it busy).
 */
export function useShare() {
  const { canShareMessage, shareMessage, shareUrl } = useTelegram()
  const pending = ref(false)

  function report(body: ShareEventRequest): void {
    try {
      void api.share.event(body).catch(() => undefined)
    } catch {
      /* analytics never breaks a share */
    }
  }

  function openFallback(kind: ShareKind, fallback: ShareFallback, reason: 'old_client' | 'error', onShared?: () => void): ShareOutcome {
    shareUrl(fallback.link, fallback.text)
    onShared?.()
    report({ event: 'fallback', kind, reason })
    return 'fallback'
  }

  async function share(kind: ShareKind, subject: string | null, fallback: ShareFallback, onShared?: () => void): Promise<ShareOutcome> {
    if (pending.value) return 'busy'
    pending.value = true
    try {
      if (!canShareMessage()) return openFallback(kind, fallback, 'old_client', onShared)

      let id: string
      try {
        id = (await api.share.prepare(kind, subject)).id
      } catch {
        return openFallback(kind, fallback, 'error', onShared)
      }

      let sent: boolean
      try {
        sent = await shareMessage(id)
      } catch {
        return openFallback(kind, fallback, 'error', onShared)
      }
      if (!sent) return 'cancelled'

      onShared?.()
      report({ event: 'sent', kind })
      return 'sent'
    } finally {
      pending.value = false
    }
  }

  return { pending: readonly(pending), share }
}
