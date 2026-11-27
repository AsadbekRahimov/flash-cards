import WebApp from '@twa-dev/sdk'

/**
 * What only the onboarding of the Mini App asks of Telegram (docs/40 §2.1): the first name to prefill O2 and the permission for the bot
 * to write (O7). Apart from `useTelegram`, so it goes with the lazy chunk of the onboarding, not into the main one. Safe outside Telegram.
 */

export type WriteAccessAnswer = 'granted' | 'denied' | 'unsupported'

/** The write-access request waiting for Telegram's answer: the SDK takes one at a time. */
let pendingWriteAccess: Promise<WriteAccessAnswer> | null = null

function webApp(): typeof WebApp | null {
  return typeof window !== 'undefined' && window.Telegram?.WebApp ? WebApp : null
}

/**
 * The first name of the user as Telegram gives it to the page (`initDataUnsafe.user.first_name`): only a hint to prefill a field, never
 * trusted - the server takes nothing from it. Null outside Telegram.
 */
export function telegramFirstName(): string | null {
  const name = webApp()?.initDataUnsafe?.user?.first_name
  return typeof name === 'string' && name.trim() !== '' ? name : null
}

/** Whether this client can ask for the permission to receive messages from the bot (`requestWriteAccess`, Bot API 6.9+). */
export function canRequestWriteAccess(): boolean {
  const app = webApp()
  try {
    return app !== null
      && typeof app.isVersionAtLeast === 'function'
      && app.isVersionAtLeast('6.9')
      && typeof app.requestWriteAccess === 'function'
  } catch {
    return false
  }
}

/**
 * Asks Telegram to let the bot write to the user (O7): `granted` / `denied` by the user's answer (a user who already started the bot is
 * answered `granted` at once), `unsupported` where the client cannot ask. A request still waiting for its answer is answered by that
 * one. Call it only from a tap.
 */
export function requestWriteAccess(): Promise<WriteAccessAnswer> {
  if (pendingWriteAccess !== null) return pendingWriteAccess
  const app = webApp()
  if (app === null || !canRequestWriteAccess()) return Promise.resolve('unsupported')
  const pending = new Promise<WriteAccessAnswer>((resolve) => {
    try {
      app.requestWriteAccess((granted) => resolve(granted === true ? 'granted' : 'denied'))
    } catch {
      // `WebAppMethodUnsupported` (an older client than it said) or `WebAppWriteAccessRequested` (asked outside this module).
      resolve('unsupported')
    }
  }).finally(() => {
    pendingWriteAccess = null
  })
  pendingWriteAccess = pending
  return pending
}
