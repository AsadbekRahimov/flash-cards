import type { TodayResponse } from '@/api/types'

/**
 * Whether the learner is past the very first day, by `/today` alone: a streak that was ever there, words done today,
 * an unfinished session, words waiting for review or learned words of the course. A newcomer has none of these.
 */
export function hasLearnerProgress(data: TodayResponse): boolean {
  return data.streak.best > 0
    || data.goal.done > 0
    || data.open_session_id !== null
    || data.due_count > 0
    || (data.active_course?.progress.learned ?? 0) > 0
}
