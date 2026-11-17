import { getCurrentScope, onScopeDispose, ref } from 'vue'
import { useTts } from '@/composables/useTts'

/**
 * "Auto-speak the words": the word is read aloud by itself when the card shows it to the learner — a new word, the
 * reveal of a review card, the screen after an answer (docs/05 §6.2). Never before the answer where the word IS the answer
 * (reverse / spelling / gap-fill / audio): the screens call {@see useAutoSpeak} only at the moment the word is on show.
 * On by default; the learner switches it off in the settings. Client-only, kept in `localStorage` like `useSayAloudHint`
 * and `useFeedbackSound`; a storage that is not available leaves it on for the visit.
 *
 * A browser may refuse a sound that no gesture asked for (the first card of a visit): that is silent, nothing is shown.
 * A hidden tab is not spoken to. When a right / wrong tone is about to play (it starts with the answer, or with the card
 * that follows the answer), the word waits {@see AFTER_FEEDBACK_MS} so it never lands on top of the tone.
 */
export const AUTOSPEAK_KEY = 'lexiflow.autospeak'

/** The right / wrong tones last 0.26 s at most; the word follows right after them (a right pick moves on after 0.7 s, `CHOICE_FEEDBACK_MS`). */
export const AFTER_FEEDBACK_MS = 280

const enabled = ref<boolean>(readEnabled())

function readEnabled(): boolean {
  try {
    return window.localStorage.getItem(AUTOSPEAK_KEY) !== 'off'
  } catch {
    return true
  }
}

export function isAutoSpeakEnabled(): boolean {
  return enabled.value
}

export function setAutoSpeakEnabled(on: boolean): void {
  enabled.value = on
  try {
    if (on) window.localStorage.removeItem(AUTOSPEAK_KEY)
    else window.localStorage.setItem(AUTOSPEAK_KEY, 'off')
  } catch {
    // the in-memory switch holds for this visit
  }
}

/** For tests: the switch back to the default (on). */
export function resetAutoSpeak(): void {
  enabled.value = true
  try {
    window.localStorage.removeItem(AUTOSPEAK_KEY)
  } catch {
    // nothing to forget
  }
}

export function useAutoSpeak(): {
  /** Reads the word aloud (if the switch is on and the tab is visible); `afterFeedback`: wait for the tone of the answer. */
  speakWord: (word: string, audioUrl?: string | null, afterFeedback?: boolean) => void
} {
  const { speak } = useTts()
  let timer: ReturnType<typeof setTimeout> | null = null

  function cancel(): void {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }

  async function run(word: string, audioUrl?: string | null): Promise<void> {
    timer = null
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return
    try {
      await speak(word, 'en-US', audioUrl)
    } catch {
      // best-effort: a blocked sound is not an error for the learner
    }
  }

  function speakWord(word: string, audioUrl?: string | null, afterFeedback = false): void {
    cancel()
    if (!enabled.value || !word) return
    if (afterFeedback) {
      timer = setTimeout(() => void run(word, audioUrl), AFTER_FEEDBACK_MS)
      return
    }
    void run(word, audioUrl)
  }

  // A card that leaves before the pause is over must not speak for the next one.
  if (getCurrentScope()) onScopeDispose(cancel)

  return { speakWord }
}
