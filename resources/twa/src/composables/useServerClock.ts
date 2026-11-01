import { readonly, ref, type Ref } from 'vue'

/** How many of the latest readings the offset is the median of (docs/16 §11.5). */
const SAMPLES = 3

export interface ServerClock {
  /** `server time − device time` in milliseconds (the median of the last readings); 0 until the first `sync`. */
  offsetMs: Readonly<Ref<number>>
  /** Takes the `server_now` of an answer (ISO 8601); anything that is not a date is ignored. */
  sync: (serverNow: string | null | undefined) => void
  /** The server's clock now, in epoch milliseconds. */
  now: () => number
  /**
   * Milliseconds left to a deadline of the server (ISO 8601), by the server's clock: `deadline − (Date.now() + offset)`.
   * Negative once it has passed; 0 for a missing or unreadable deadline.
   */
  remaining: (deadline: string | null | undefined) => number
  /** Forgets the readings (another battle). */
  reset: () => void
}

/**
 * The clock of the server as the device sees it (docs/16 §11.5, §8.3): the deadlines of a battle round are the server's,
 * and the timer on screen only shows them, so every answer that carries `server_now` tells how far the device clock is
 * off. The offset is the median of the last three readings, which a single slow answer cannot drag far.
 *
 * Needs no component: it can live in a store as well as in a screen.
 *
 * @example
 * const clock = useServerClock()
 * clock.sync(poll.server_now)
 * const seconds = Math.ceil(clock.remaining(round.deadline_at) / 1000)
 */
export function useServerClock(): ServerClock {
  const offset = ref(0)
  let readings: number[] = []

  function median(values: number[]): number {
    const sorted = [...values].sort((a, b) => a - b)
    const middle = Math.floor(sorted.length / 2)
    return sorted.length % 2 === 1 ? sorted[middle]! : (sorted[middle - 1]! + sorted[middle]!) / 2
  }

  function sync(serverNow: string | null | undefined): void {
    const server = Date.parse(serverNow ?? '')
    if (!Number.isFinite(server)) return
    readings = [...readings, server - Date.now()].slice(-SAMPLES)
    offset.value = median(readings)
  }

  const now = (): number => Date.now() + offset.value

  function remaining(deadline: string | null | undefined): number {
    const at = Date.parse(deadline ?? '')
    return Number.isFinite(at) ? at - now() : 0
  }

  function reset(): void {
    readings = []
    offset.value = 0
  }

  return { offsetMs: readonly(offset), sync, now, remaining, reset }
}
