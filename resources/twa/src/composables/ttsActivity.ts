/**
 * Whether a word is being read aloud right now, for the short sounds of a lesson (`useFeedbackSound`) that must not talk
 * over it. `useTts` hands over the mp3 it plays; the speech synthesis of the browser tells for itself.
 */
let audio: HTMLAudioElement | null = null

export function trackTtsAudio(element: HTMLAudioElement | null): void {
  audio = element
}

export function isTtsPlaying(): boolean {
  try {
    if (audio !== null && !audio.paused && !audio.ended) return true
    return typeof window !== 'undefined' && window.speechSynthesis?.speaking === true
  } catch {
    return false
  }
}
