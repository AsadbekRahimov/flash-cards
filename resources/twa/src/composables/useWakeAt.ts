import { getCurrentScope, onScopeDispose, watch } from 'vue'

export interface WakeAtOptions {
  /** The moment, in the server's time (epoch ms), at which the server is due to change something; `null`: nothing is due. */
  dueAt: () => number | null
  /** `server time − device time` in milliseconds (the battle store keeps it). */
  offsetMs: () => number
  /** Called once when the moment has come. */
  wake: () => void
  /** How long after the moment to call `wake`, so that the server is surely past it (default 80 ms). */
  slackMs?: number
}

/**
 * Wakes the screen at a moment of the server (docs/16 §8.3). A battle moves on lazily: the server changes the state
 * of the countdown, of the answering time and of the pause between rounds on the first read after the moment, and the
 * regular read comes every two seconds, so a round would start up to two seconds late. This calls `wake` (a poll) right
 * at the moment instead.
 *
 * A moment that is already past is not waited for (the read that just came would have moved the server on). A new
 * `dueAt` replaces the old one; the timer ends with the component or effect scope that called it.
 */
export function useWakeAt({ dueAt, offsetMs, wake, slackMs = 80 }: WakeAtOptions): void {
  let timer: ReturnType<typeof setTimeout> | null = null

  function clear(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  watch(
    dueAt,
    (at) => {
      clear()
      if (at === null) return
      const untilDue = at - (Date.now() + offsetMs())
      if (untilDue <= 0) return
      timer = setTimeout(() => {
        timer = null
        wake()
      }, untilDue + slackMs)
    },
    { immediate: true },
  )

  if (getCurrentScope()) onScopeDispose(clear)
}
