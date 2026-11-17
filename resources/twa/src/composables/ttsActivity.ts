/**
 * Whether a word is being read aloud right now, for the short sounds of a lesson (`useFeedbackSound`) that must not talk
 * over it. `useTts` hands over the mp3 it plays; the speech synthesis of the browser tells for itself.
 */
let audio: HTMLAudioElement | null = null
const endWaiters = new Set<() => void>()

export function trackTtsAudio(element: HTMLAudioElement | null): void {
  audio = element
  if (!element) return
  // Ended, failed or stopped by the next sound (`pause`): whoever waits for the end of the word is let go.
  for (const type of ['ended', 'error', 'pause']) element.addEventListener?.(type, notifyEnded)
}

/** The utterance of the Web Speech API: its end (or failure, or a `cancel()` by the next sound) is told to the waiters. */
export function trackTtsUtterance(utterance: SpeechSynthesisUtterance): void {
  utterance.onend = notifyEnded
  utterance.onerror = notifyEnded
}

function notifyEnded(): void {
  const waiters = [...endWaiters]
  endWaiters.clear()
  for (const done of waiters) done()
}

/**
 * Resolves when the word that is being read aloud ends; at once when nothing is playing. `maxMs` is the ceiling: a sound
 * that never ends must not hold the learner back.
 */
export function whenTtsEnds(maxMs: number): Promise<void> {
  if (!isTtsPlaying()) return Promise.resolve()
  return new Promise((resolve) => {
    let timer: ReturnType<typeof setTimeout> | null = null
    const done = (): void => {
      if (timer !== null) clearTimeout(timer)
      endWaiters.delete(done)
      resolve()
    }
    endWaiters.add(done)
    timer = setTimeout(done, Math.max(0, maxMs))
  })
}

export function isTtsPlaying(): boolean {
  try {
    if (audio !== null && !audio.paused && !audio.ended) return true
    return typeof window !== 'undefined' && window.speechSynthesis?.speaking === true
  } catch {
    return false
  }
}
