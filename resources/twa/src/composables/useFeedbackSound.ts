import { ref } from 'vue'
import { isTtsPlaying } from '@/composables/ttsActivity'

/**
 * The three short sounds of a lesson — right, wrong, lesson done — synthesised with the Web Audio API (no files). Quiet, and
 * on by default; the learner switches them off in the settings. The switch lives in `localStorage` (like `useSayAloudHint`);
 * a storage that is not available leaves the sounds on for the visit. A device without `AudioContext` plays nothing, silently.
 * They are played from the answer the learner has just given (a gesture), which is what lets the browser start the audio;
 * they stay quiet while the word is being read aloud, so they never talk over it.
 */
export type FeedbackSound = 'right' | 'wrong' | 'done'

export const SOUNDS_KEY = 'lexiflow.sounds'

/** Loudness of the peak of a tone: well below the speech. */
const VOLUME = 0.05

const enabled = ref<boolean>(readEnabled())

function readEnabled(): boolean {
  try {
    return window.localStorage.getItem(SOUNDS_KEY) !== 'off'
  } catch {
    return true
  }
}

export function isSoundEnabled(): boolean {
  return enabled.value
}

export function setSoundEnabled(on: boolean): void {
  enabled.value = on
  try {
    if (on) window.localStorage.removeItem(SOUNDS_KEY)
    else window.localStorage.setItem(SOUNDS_KEY, 'off')
  } catch {
    // the in-memory switch holds for this visit
  }
}

/** For tests: the switch back to the default (on). */
export function resetSounds(): void {
  enabled.value = true
  context = null
  try {
    window.localStorage.removeItem(SOUNDS_KEY)
  } catch {
    // nothing to forget
  }
}

/** A tone: frequency (Hz), when it starts (s from now) and how long it lasts (s). */
type Tone = readonly [frequency: number, at: number, length: number]

const TONES: Record<FeedbackSound, readonly Tone[]> = {
  right: [[660, 0, 0.09], [880, 0.08, 0.12]],
  wrong: [[300, 0, 0.14], [240, 0.1, 0.16]],
  done: [[523, 0, 0.1], [659, 0.1, 0.1], [784, 0.2, 0.18]],
}

let context: AudioContext | null = null

function audioContext(): AudioContext | null {
  if (context !== null) return context
  try {
    const w = window as unknown as { AudioContext?: typeof AudioContext; webkitAudioContext?: typeof AudioContext }
    const Ctor = w.AudioContext ?? w.webkitAudioContext
    if (!Ctor) return null
    context = new Ctor()
  } catch {
    context = null
  }
  return context
}

export function playSound(kind: FeedbackSound): void {
  if (!enabled.value || isTtsPlaying()) return
  try {
    const ctx = audioContext()
    if (ctx === null) return
    if (ctx.state === 'suspended') void ctx.resume().catch(() => undefined)
    const start = ctx.currentTime
    for (const [frequency, at, length] of TONES[kind]) {
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.type = 'sine'
      osc.frequency.value = frequency
      // A short fade in and out: a bare start / stop of a wave clicks.
      gain.gain.setValueAtTime(0, start + at)
      gain.gain.linearRampToValueAtTime(VOLUME, start + at + 0.015)
      gain.gain.linearRampToValueAtTime(0, start + at + length)
      osc.connect(gain)
      gain.connect(ctx.destination)
      osc.start(start + at)
      osc.stop(start + at + length + 0.02)
    }
  } catch {
    // no sound is better than a broken screen
  }
}
