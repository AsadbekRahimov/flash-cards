import WebApp from '@twa-dev/sdk'

/**
 * Telegram's story editor (`WebApp.shareToStory`, Mini Apps 7.8+, docs/40 §2.4) for the screen F3 and the button "В сторис" of
 * Achievements. A module of its own, not a part of `useTelegram`: only those two lazy screens need it, so the main chunk does not carry it.
 */

/** The SDK while the app runs inside Telegram; null outside it (the e2e stub, a browser). */
function webApp(): typeof WebApp | null {
  return typeof window !== 'undefined' && window.Telegram?.WebApp ? WebApp : null
}

/** Whether this client can open the story editor: a Telegram client of 7.8 or newer and the method of the SDK. */
export function canShareToStory(): boolean {
  const app = webApp()
  try {
    return app !== null
      && typeof app.isVersionAtLeast === 'function'
      && app.isVersionAtLeast('7.8')
      && typeof app.shareToStory === 'function'
  } catch {
    return false
  }
}

/**
 * Opens Telegram's story editor with a picture (a public `http(s)` URL, 9:16), a caption and the link of the widget (Premium only).
 * Telegram calls nothing back - not even whether the story was posted - so `opened` means only "the editor was asked to open".
 * `unsupported` - below 7.8 or outside Telegram (nothing was called); `failed` - the SDK refused the call (`WebAppMethodUnsupported`,
 * `WebAppMediaUrlInvalid`, a caption too long). Call it only from a tap.
 */
export function shareToStory(mediaUrl: string, params: { text: string; widget_link: { url: string; name: string } | null }): 'opened' | 'unsupported' | 'failed' {
  const app = webApp()
  if (app === null || !canShareToStory()) return 'unsupported'
  try {
    app.shareToStory(mediaUrl, params.widget_link === null ? { text: params.text } : { text: params.text, widget_link: params.widget_link })
    return 'opened'
  } catch {
    return 'failed'
  }
}
