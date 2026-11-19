/**
 * The word of "Word form" that the learner has typed and not sent yet: kept in the memory of the page like the line of "Build the
 * sentence" (`sentenceDraft.ts`), so ✕ and "Continue" bring it back; gone with a reload. Keyed by the id of the element.
 */

/** Drafts kept at once: the newest ones; a session has five elements, so this is a few sessions' worth. */
const MAX_DRAFTS = 20

const drafts = new Map<string, string>()

/** The saved text of the element ('' when there is none). */
export function readWordFormDraft(elementId: string): string {
  return drafts.get(elementId) ?? ''
}

/** Saves the text (an empty one is no draft). */
export function writeWordFormDraft(elementId: string, text: string): void {
  // Deleted first, so that the newest draft is the last in the map and the oldest is the first to go.
  drafts.delete(elementId)
  if (text === '') return

  drafts.set(elementId, text)
  while (drafts.size > MAX_DRAFTS) {
    const oldest = drafts.keys().next()
    if (oldest.done) break
    drafts.delete(oldest.value)
  }
}

/** The element is done (its answer went out): its text is not needed any more. */
export function clearWordFormDraft(elementId: string): void {
  drafts.delete(elementId)
}

/** All the drafts (a test starts from none). */
export function forgetWordFormDrafts(): void {
  drafts.clear()
}
