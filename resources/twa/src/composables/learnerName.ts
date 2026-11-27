import type { Learner } from '@/api/types'

/**
 * The name of the learner on the learner's own screens - the greeting of "Today" and the profile (docs/40 §2.1 and §3, В1): the name
 * chosen in the onboarding (`display_name`) or else the Telegram one (`first_name`, overwritten by Telegram at every open), trimmed;
 * '' - none. Other people's lists (league, friends, battles) keep `first_name` of their own answers.
 */
export function shownName(learner: Pick<Learner, 'first_name' | 'display_name'> | null | undefined): string {
  return (learner?.display_name ?? learner?.first_name ?? '').trim()
}
