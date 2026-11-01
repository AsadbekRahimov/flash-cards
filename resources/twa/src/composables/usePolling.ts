import { getCurrentScope, onScopeDispose, watch, type Ref } from 'vue'

export interface PollingOptions {
  /** Polling runs only while this is true (and the page is visible): the owner turns it off at a terminal state. */
  enabled: Ref<boolean>
  /** The beat, in milliseconds (docs/16 §11.5: two seconds). */
  intervalMs?: number
  /** The longest pause the backoff reaches after failures. */
  maxIntervalMs?: number
  /** Take the first reading as soon as polling is turned on, instead of after one beat. */
  immediate?: boolean
}

export interface Polling {
  /** Stops for good (a component or scope that ends does it by itself). */
  stop: () => void
}

/**
 * Calls `tick` every `intervalMs` while `enabled` is true and the page is in front (docs/16 §11.5, §8.4: the poll of a
 * battle is also its heartbeat).
 *
 * - The page goes to the background (`visibilitychange`, `document.visibilityState`): the beat stops; back in front: a
 *   reading at once, then the beat again.
 * - Readings never overlap: a beat that finds the previous one still in flight is skipped.
 * - A `tick` that throws (network, 429, 5xx) doubles the pause up to `maxIntervalMs`; the first success returns it to
 *   `intervalMs`. A tick that is done with a refusal that polling cannot cure (404, a closed battle) turns `enabled` off.
 * - The beat ends with the component / effect scope that called it, and with `stop()`.
 *
 * @example
 * usePolling(() => battle.tick(), { enabled: computed(() => battle.polling) })
 */
export function usePolling(tick: () => Promise<void>, options: PollingOptions): Polling {
  const base = options.intervalMs ?? 2000
  const ceiling = Math.max(base, options.maxIntervalMs ?? 10_000)

  let timer: ReturnType<typeof setTimeout> | null = null
  let inFlight = false
  let pause = base
  /** Bumped by every stop: a reading that comes back after it has nothing to schedule. */
  let epoch = 0
  let stopped = false
  let unwatch: (() => void) | null = null

  const visible = (): boolean => typeof document === 'undefined' || document.visibilityState === 'visible'
  const shouldRun = (): boolean => !stopped && options.enabled.value && visible()

  function clear(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function schedule(): void {
    clear()
    if (shouldRun()) timer = setTimeout(() => void beat(), pause)
  }

  async function beat(): Promise<void> {
    timer = null
    if (!shouldRun()) return
    if (inFlight) {
      schedule()
      return
    }

    inFlight = true
    const mine = epoch
    try {
      await tick()
      pause = base
    } catch {
      pause = Math.min(ceiling, pause * 2)
    } finally {
      inFlight = false
    }
    if (mine === epoch) schedule()
  }

  /** No beat is pending and a reading that is still on its way will not schedule the next one. */
  function halt(): void {
    epoch += 1
    clear()
  }

  function restart(immediate: boolean): void {
    halt()
    pause = base
    if (!shouldRun()) return
    if (immediate) void beat()
    else schedule()
  }

  function onVisibilityChange(): void {
    if (visible()) restart(true)
    else halt()
  }

  function stop(): void {
    stopped = true
    halt()
    unwatch?.()
    if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisibilityChange)
  }

  if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisibilityChange)
  unwatch = watch(
    options.enabled,
    (on) => {
      if (on) restart(options.immediate ?? false)
      else halt()
    },
    { immediate: true },
  )

  if (getCurrentScope()) onScopeDispose(stop)

  return { stop }
}
