import { hasSeen, markSeen } from '@/composables/seenFlag'

/**
 * The one-off sheet "Pro for N days - until :date" (docs/36 §4б, W3): the learner who came only through the Mini App gets the welcome of the
 * trial here, since the bot cannot write first. Once per trial - "already shown" is `localStorage` `trial_welcome_seen:<ends_at>` (the same
 * scheme as `trial_ended_seen:`, `composables/trialEnded.ts`); nothing is kept on the server and the bot's own mark is not taken.
 */
export const TRIAL_WELCOME_SEEN_PREFIX = 'trial_welcome_seen:'

export function trialWelcomeKey(endsAt: string): string {
  return `${TRIAL_WELCOME_SEEN_PREFIX}${endsAt}`
}

/**
 * True once per end of the running trial: when it is true the caller opens the sheet, and the end is marked shown at once. A storage that
 * is not there keeps the mark in memory (`seenFlag`): the sheet is not shown twice in the session and the screen does not break.
 */
export function takeTrialWelcome(endsAt: string | null): boolean {
  if (endsAt === null || endsAt === '') return false
  const key = trialWelcomeKey(endsAt)
  if (hasSeen(key)) return false
  markSeen(key)
  return true
}
