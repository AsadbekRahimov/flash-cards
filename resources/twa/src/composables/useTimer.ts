import { onBeforeUnmount, ref, type Ref } from 'vue'

export interface Timer {
  /** Remaining seconds (reactive). */
  remaining: Ref<number>
  /** Start or restart the countdown from `seconds`. */
  start: (seconds: number) => void
  /** Stop the countdown and freeze `remaining`. */
  stop: () => void
  /** True when `remaining` hit zero during the current run. */
  expired: Ref<boolean>
}

/**
 * Lightweight countdown timer usable both for the overall exam window
 * and per-question deadlines. Ticks once per second.
 *
 * @param onExpire  Optional callback invoked exactly once when the timer
 *                  reaches zero during the current run.
 */
export function useTimer(onExpire?: () => void): Timer {
  const remaining = ref(0)
  const expired = ref(false)
  let handle: ReturnType<typeof setInterval> | null = null

  function stop(): void {
    if (handle !== null) {
      clearInterval(handle)
      handle = null
    }
  }

  function start(seconds: number): void {
    stop()
    expired.value = false
    remaining.value = Math.max(0, Math.floor(seconds))

    handle = setInterval(() => {
      if (remaining.value <= 1) {
        remaining.value = 0
        stop()
        if (!expired.value) {
          expired.value = true
          onExpire?.()
        }
        return
      }
      remaining.value -= 1
    }, 1000)
  }

  onBeforeUnmount(stop)

  return { remaining, start, stop, expired }
}
