/**
 * Time of a battle (docs/16 §11.5): every deadline is the server's (ISO 8601, `server_now` tells how far the device
 * clock is off), the screen only shows how much is left.
 */

/**
 * Milliseconds from now to a deadline of the server: `deadline − (now + offset)`. `nowMs` is the device clock
 * (`Date.now()` or `useNow()`), `offsetMs` is `server time − device time` of the battle store. Negative once the
 * deadline has passed; 0 for a missing or unreadable one.
 */
export function remainingMs(deadline: string | null | undefined, nowMs: number, offsetMs: number): number {
  const at = Date.parse(deadline ?? '')
  return Number.isFinite(at) ? at - (nowMs + offsetMs) : 0
}

/** Whole seconds left, rounded up: a countdown shows "1" until the very last moment and "0" only when it is over. */
export const secondsLeft = (ms: number): number => Math.max(0, Math.ceil(ms / 1000))

/** "4:12" of a number of seconds (minutes are not capped: "62:05" is possible, a battle has none of that). */
export function clockText(seconds: number): string {
  const whole = Math.max(0, Math.floor(seconds))
  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`
}
