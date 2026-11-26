import { hasSeen, markSeen } from '@/composables/seenFlag'
import type { Lapsed } from '@/api/types'

/**
 * The one-off sheet "Trial Pro is over" (docs/05 §8c-1, docs/34 §2б.4, P7): at the first opening of the Mini App after the welcome trial
 * ended (`/me` / `/today` `entitlement.lapsed.provider === 'trial'`) the paywall opens by itself with the reason `trial_ended`, once per
 * end - "already shown" is `localStorage` `trial_ended_seen:<ended_at>`; nothing is kept on the server. The "Pro ended" banner of Today
 * stays as it is (14 days).
 */
export const TRIAL_ENDED_SEEN_PREFIX = 'trial_ended_seen:'

export function trialEndedKey(endedAt: string): string {
  return `${TRIAL_ENDED_SEEN_PREFIX}${endedAt}`
}

/** True once per ended trial: when it is true the caller opens the sheet, and the end is marked shown at once. */
export function takeTrialEnded(lapsed: Lapsed | null | undefined): boolean {
  if (!lapsed || lapsed.provider !== 'trial' || lapsed.ended_at === '') return false
  const key = trialEndedKey(lapsed.ended_at)
  if (hasSeen(key)) return false
  markSeen(key)
  return true
}
