/**
 * "The learner has already seen this hint" flags kept on the device (`localStorage`; the project has no other client store).
 * A storage that is not available (private mode, a blocked WebView) must neither break the screen nor make a hint come back
 * on every visit: the flags are also kept in memory for the session.
 */
const seenInSession = new Set<string>()
/** Snoozed hints: the moment (`Date.now()`) until which a hint stays hidden, kept in memory too. */
const snoozedInSession = new Map<string, number>()
/** Short values (`readValue` / `writeValue`), kept in memory too. */
const valuesInSession = new Map<string, string>()

export function hasSeen(key: string): boolean {
  if (seenInSession.has(key)) return true
  try {
    return window.localStorage.getItem(key) === '1'
  } catch {
    return false
  }
}

export function markSeen(key: string): void {
  seenInSession.add(key)
  try {
    window.localStorage.setItem(key, '1')
  } catch {
    // the memory flag above holds for this session
  }
}

/** Whether a hint was hidden for a period that has not passed yet ("close for 14 days"). */
export function isSnoozed(key: string, now: number = Date.now()): boolean {
  let until = snoozedInSession.get(key) ?? 0
  try {
    until = Math.max(until, Number(window.localStorage.getItem(key)) || 0)
  } catch {
    // no storage: the memory value above is all there is
  }
  return until > now
}

/** A short text kept on the device (the day a hint was last shown, the turn of a rotation); `null` when none was kept. */
export function readValue(key: string): string | null {
  try {
    const stored = window.localStorage.getItem(key)
    if (stored !== null) return stored
  } catch {
    // no storage: the memory value below is all there is
  }
  return valuesInSession.get(key) ?? null
}

export function writeValue(key: string, value: string): void {
  valuesInSession.set(key, value)
  try {
    window.localStorage.setItem(key, value)
  } catch {
    // the memory value above holds for this session
  }
}

/** Hides a hint for `ms` from now. */
export function snooze(key: string, ms: number, now: number = Date.now()): void {
  const until = now + ms
  snoozedInSession.set(key, until)
  try {
    window.localStorage.setItem(key, String(until))
  } catch {
    // the memory value above holds for this session
  }
}

/** For tests: forgets the in-memory flags. */
export function resetSeenSession(): void {
  seenInSession.clear()
  snoozedInSession.clear()
  valuesInSession.clear()
}
