import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useTelegram } from '@/composables/useTelegram'

/**
 * Where Telegram's system "Back" button leads (docs/14 §5.3):
 * - a route location (`{ name: 'courses' }`) — `router.push`, or `router.back()` when the screen before this one in the
 *   history is that very place (the usual case: the learner came from it), so the screen comes back where it was
 *   scrolled to (`scrollBehavior`) and the history does not grow with every "Back";
 * - a function — called on press (e.g. close the Mini App, or decide by state at press time);
 * - `null` — the screen is a root (a tab or an immersive screen): the button is hidden.
 */
export type BackButtonTarget = RouteLocationRaw | (() => void) | null

/**
 * Shows Telegram's system "Back" button for the screen and sends it to `target`; hides it for `null`.
 * Call it once in `setup()`: the button is shown on mount, the handler is removed (and the button hidden)
 * on unmount. Outside Telegram there is no button, so it does nothing.
 *
 * Replaces the per-screen `showBackButton` + `onBeforeUnmount` pairs and the text links "← To courses".
 *
 * @example
 * useBackButton({ name: 'courses' })   // course -> courses
 * useBackButton(null)                  // tab root: no Back button
 */
export function useBackButton(target: BackButtonTarget): void {
  const router = useRouter()
  const { showBackButton, webApp } = useTelegram()
  let release: (() => void) | null = null

  onMounted(() => {
    if (target === null) {
      try {
        webApp?.BackButton?.hide()
      } catch {
        // outside Telegram
      }
      return
    }

    release = showBackButton(() => {
      if (typeof target === 'function') target()
      else if (router.options.history.state.back === router.resolve(target).fullPath) router.back()
      else void router.push(target)
    })
  })

  onBeforeUnmount(() => {
    release?.()
    release = null
  })
}
