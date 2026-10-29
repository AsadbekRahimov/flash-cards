import { useToast } from '@/composables/useToast'

/** A refusal stays on screen longer than "Saved": it has to be read, and the learner may be looking elsewhere. */
export const ERROR_TOAST_MS = 4000

/**
 * Tells the learner that an action failed (docs/14 §3): a toast, because the alert of a long screen sits at its top,
 * out of sight when the control that failed is further down. An in-flow `InlineAlert` stays for the failure of the
 * screen itself (loading) and for actions whose button is next to the message.
 *
 * @example showErrorToast(settings.errorMessage)
 */
export function showErrorToast(message: string): void {
  useToast().show(message, ERROR_TOAST_MS)
}
