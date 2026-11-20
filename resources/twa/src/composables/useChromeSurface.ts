import { onScopeDispose, toValue, watch, type MaybeRefOrGetter } from 'vue'
import { holdSurfaceChrome } from '@/composables/telegramTheme'

/**
 * A white lesson screen (`bg-surface`: a training, exam, battle, practice or game task) paints the Telegram header and window
 * background in the surface colour of the current palette, so there is no seam between Telegram's header and the white screen
 * (docs/14 §4.1). While `active` is false - and when the screen goes away - the chrome is back in the page colour. A theme change
 * while the screen is open repaints in the surface colour of the new palette (`applyTelegramTheme` keeps the tone).
 *
 * @param active whether the screen is white right now (default: always; a screen that turns to the page colour for its summary
 *   passes a getter)
 */
export function useChromeSurface(active: MaybeRefOrGetter<boolean> = true): void {
  let release: (() => void) | null = null

  function set(on: boolean): void {
    if (on && release === null) {
      release = holdSurfaceChrome()
    } else if (!on && release !== null) {
      release()
      release = null
    }
  }

  watch(() => toValue(active), set, { immediate: true })
  onScopeDispose(() => set(false))
}
