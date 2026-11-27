import type { Router } from 'vue-router'

/** What of `Telegram.WebApp` the guard needs (the SDK object, or a stub in a test). */
export interface VerticalSwipesApi {
  isVersionAtLeast(version: string): boolean
  disableVerticalSwipes(): void
  enableVerticalSwipes(): void
}

/** Bot API of `disableVerticalSwipes` / `enableVerticalSwipes`. */
export const VERTICAL_SWIPES_VERSION = '7.7'

/**
 * A swipe down must not close the app in the middle of a lesson (kit TELEGRAM.md), but everywhere else it is the usual way out of a Mini
 * App. So the swipe is switched off only on the immersive screens (`meta.immersive` of the route: the lesson, the exam, a practice
 * session, the rounds of a battle / a cup / a duel, a run of "Karvon") and on again when the learner leaves them. Telegram is asked only
 * when the state changes; a client older than 7.7 or no Telegram at all - nothing.
 */
export function installVerticalSwipeGuard(router: Router, webApp: VerticalSwipesApi | null): void {
  if (webApp === null) return
  let supported = false
  try {
    supported = webApp.isVersionAtLeast(VERTICAL_SWIPES_VERSION)
  } catch {
    supported = false
  }
  if (!supported) return

  // Telegram starts with the swipe on.
  let disabled = false
  router.afterEach((to, _from, failure) => {
    if (failure) return
    const immersive = to.meta.immersive === true
    if (immersive === disabled) return
    try {
      if (immersive) webApp.disableVerticalSwipes()
      else webApp.enableVerticalSwipes()
      disabled = immersive
    } catch {
      // an odd client: the swipe stays as it was
    }
  })
}
