import { ref, watch } from 'vue'

/**
 * The tasks of a run send ONE answer per element. When the request got no reply (the screen lets go of `disabled` and the element is
 * still this one), the server may have counted that very answer, so the task keeps what was sent - read-only - and offers to send
 * the SAME again; the store repeats it under the same `request_id`.
 *
 * `mark(kind)` is called when an answer or "I don't know" goes out; `retrying` turns on when the request ends with the task still on screen.
 */
export type Sent = 'answer' | 'skip'

export function useResend(disabled: () => boolean | undefined) {
  const lastSent = ref<Sent | null>(null)
  const retrying = ref(false)

  watch(disabled, (now, before) => {
    if (before === true && now !== true && lastSent.value !== null) retrying.value = true
  })

  function mark(kind: Sent): void {
    lastSent.value = kind
    retrying.value = false
  }

  return { lastSent, retrying, mark }
}
