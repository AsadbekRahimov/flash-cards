/**
 * "The learner has already seen this hint" flags kept on the device (`localStorage`; the project has no other client store).
 * A storage that is not available (private mode, a blocked WebView) must neither break the screen nor make a hint come back
 * on every visit: the flags are also kept in memory for the session.
 */
const seenInSession = new Set<string>()

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

/** For tests: forgets the in-memory flags. */
export function resetSeenSession(): void {
  seenInSession.clear()
}
