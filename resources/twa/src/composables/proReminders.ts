import { readValue, writeValue } from '@/composables/seenFlag'
import type { StringKey } from '@/i18n/ru'

/**
 * Reminders about Pro while the learner studies (docs/14, "Напоминания о Pro"). They live only in the natural pauses — the end of a round,
 * the streak card of Today — and next to what the learner has just done; never between the cards of a round, never a pop-up. The frequencies
 * and the terms are here and nowhere else.
 */
const DAY_MS = 86_400_000

/** The tip on the finish screen: at most once in a local day; the cross hides it for 3 days. */
export const PRO_TIP_SNOOZE_MS = 3 * DAY_MS
/** The trial's last days: "the trial ends in N days" is said from this many days left (and at most once in a local day). */
export const TRIAL_ENDING_DAYS = 2
/** "A freeze would have kept the streak" on Today, to a Free learner whose streak broke: once in 7 days. */
export const STREAK_LINE_EVERY_MS = 7 * DAY_MS

export const PRO_TIP_KEY = 'lexiflow:pro-tip-hidden'
export const PRO_TIP_DAY_KEY = 'lexiflow:pro-tip-day'
export const PRO_TIP_TURN_KEY = 'lexiflow:pro-tip-turn'
export const TRIAL_ENDING_DAY_KEY = 'lexiflow:trial-ending-day'
export const STREAK_LINE_KEY = 'lexiflow:streak-freeze-line'
/** Set by the first finished session of the device: the first one has no tip ("the first impression is not an advertisement"). */
export const FINISHED_BEFORE_KEY = 'lexiflow:finished-before'
/** The dismissal mark of the "Pro ended" banner of Today (`LapsedBanner`): the tip does not come on top of a banner that is still there. */
export const LAPSED_DISMISSED_KEY = 'lexiflow:lapsed-dismissed'

/** The exercises that are a feature of Pro (docs/13 §8.2): the settings mark their rows with the "Pro" chip, the cards of the round the same way. */
export const PRO_EXERCISES: readonly string[] = ['spelling', 'gap_fill']

/** The turn of the tip in the benefits, one at a time (`benefits.*` -> the sentence that starts "In Pro ..."). */
export const TIP_TEXTS: Partial<Record<StringKey, StringKey>> = {
  'benefits.new_words': 'protip.item.new_words',
  'benefits.freezes': 'protip.item.freezes',
  'benefits.exercises': 'protip.item.exercises',
  'benefits.practice': 'protip.item.practice_both',
  'benefits.practice_solo': 'protip.item.practice_solo',
  'benefits.game': 'protip.item.game',
}

/** The benefits the tip may name, in the turn of the rotation (the order of the list of the paywall, less the decks and the mock tests). */
export const TIP_SLOTS = ['benefits.new_words', 'benefits.freezes', 'benefits.exercises', 'practice', 'game'] as const

/** The calendar day of the learner as `YYYY-MM-DD`, in their zone (the device's one for a zone this browser does not know). */
export function localDay(now: number = Date.now(), timezone?: string | null): string {
  const options: Intl.DateTimeFormatOptions = { year: 'numeric', month: '2-digit', day: '2-digit' }
  try {
    return new Intl.DateTimeFormat('en-CA', { ...options, timeZone: timezone ?? undefined }).format(now)
  } catch {
    return new Intl.DateTimeFormat('en-CA', options).format(now)
  }
}

/** Whether a reminder that is shown once in a local day has not been shown today yet. */
export function dayFree(key: string, timezone?: string | null, now: number = Date.now()): boolean {
  return readValue(key) !== localDay(now, timezone)
}

export function markDay(key: string, timezone?: string | null, now: number = Date.now()): void {
  writeValue(key, localDay(now, timezone))
}

/** The turn of the rotation (0 when none was kept); `advanceTurn` moves it on by one. */
export function readTurn(key: string = PRO_TIP_TURN_KEY): number {
  const turn = Number(readValue(key))
  return Number.isInteger(turn) && turn > 0 ? turn : 0
}

export function advanceTurn(key: string = PRO_TIP_TURN_KEY): void {
  writeValue(key, String(readTurn(key) + 1))
}
