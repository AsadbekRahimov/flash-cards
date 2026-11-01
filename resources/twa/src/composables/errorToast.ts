import { useToast } from '@/composables/useToast'

/** A refusal stays on screen longer than "Saved": it has to be read, and the learner may be looking elsewhere. */
export const ERROR_TOAST_MS = 4000

/** `id` of the error toast that is (or was) on screen: `hideErrorToast` takes off this one and no other message. */
let shown: number | null = null

/**
 * Tells the learner that an action failed (docs/14 §3): a toast, because the alert of a long screen sits at its top,
 * out of sight when the control that failed is further down. An in-flow `InlineAlert` stays for the failure of the
 * screen itself (loading) and for actions whose button is next to the message.
 *
 * @example showErrorToast(settings.errorMessage)
 */
export function showErrorToast(message: string): void {
  const { show, toast } = useToast()
  show(message, ERROR_TOAST_MS)
  shown = toast.value?.id ?? null
}

/**
 * Takes the toast of a refusal off the screen when the learner has moved on: the next try of the action (it goes through, or it fails
 * again and the toast comes back with its own words), "Next" after a verdict. A refusal that has been dealt with must not stand for
 * the rest of its four seconds over the review of the next task. Only the error toast goes: if another message has taken its place
 * ("Saved", "Copied"), it stays.
 */
export function hideErrorToast(): void {
  const { toast, hide } = useToast()
  if (shown !== null && toast.value?.id === shown) hide()
  shown = null
}
