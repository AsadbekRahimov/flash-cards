/**
 * The line of "Build the sentence" that the learner has put together and not sent yet (docs/15 §3.2: the answer is kept
 * when the learner leaves and comes back). It lives in the memory of the page - as long as the app is open, so ✕ and
 * "Continue" bring the same line back - and is gone with a reload: the accepted answers are the server's, a half-built line
 * is only a convenience. Keyed by the id of the element, which is unique in a snapshot.
 */

/** Drafts kept at once: the newest ones; a session has five elements, so this is a few sessions' worth. */
const MAX_DRAFTS = 20

const drafts = new Map<string, readonly string[]>()

/**
 * The saved line of the element, as far as it still fits it: ids that are not fragments of the element, and repeats, are
 * dropped, so a draft can never put a fragment into the line twice or make one up.
 */
export function readSentenceDraft(elementId: string, fragmentIds: readonly string[]): string[] {
  const known = new Set(fragmentIds)
  const line: string[] = []
  for (const id of drafts.get(elementId) ?? []) {
    if (known.has(id) && !line.includes(id)) line.push(id)
  }
  return line
}

/** Saves the line (an empty line is no draft). */
export function writeSentenceDraft(elementId: string, line: readonly string[]): void {
  // Deleted first, so that the newest draft is the last in the map and the oldest is the first to go.
  drafts.delete(elementId)
  if (line.length === 0) return

  drafts.set(elementId, [...line])
  while (drafts.size > MAX_DRAFTS) {
    const oldest = drafts.keys().next()
    if (oldest.done) break
    drafts.delete(oldest.value)
  }
}

/** The element is done (its answer went out): its line is not needed any more. */
export function clearSentenceDraft(elementId: string): void {
  drafts.delete(elementId)
}

/** All the drafts (a test starts from none). */
export function forgetSentenceDrafts(): void {
  drafts.clear()
}
