import { readonly, ref } from 'vue'

export interface ToastMessage {
  /** Grows with every `show()`, so a repeated identical message restarts the transition. */
  id: number
  text: string
}

const current = ref<ToastMessage | null>(null)
let sequence = 0
let timer: ReturnType<typeof setTimeout> | null = null

/** Hides the toast now (e.g. when leaving a screen). */
function hide(): void {
  if (timer !== null) clearTimeout(timer)
  timer = null
  current.value = null
}

/**
 * Shows a short message above the tab bar and hides it after `ms` (default 1.8 s). A new message replaces
 * the one on screen. Nothing is shown until `AppToast` is mounted (App.vue does it once).
 */
function show(message: string, ms = 1800): void {
  if (timer !== null) clearTimeout(timer)
  current.value = { id: ++sequence, text: message }
  timer = setTimeout(hide, ms)
}

/**
 * App-wide toast ("Saved", "Copied"): a singleton, the same state in every component.
 *
 * @example const toast = useToast(); toast.show(t('common.saved'))
 */
export function useToast() {
  return { toast: readonly(current), show, hide }
}
