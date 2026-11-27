import { watch, type WatchStopHandle } from 'vue'
import type { Router } from 'vue-router'
import { hasSeen, markSeen } from '@/composables/seenFlag'
import { trialEndedKey } from '@/composables/trialEnded'
import { needsOnboarding } from '@/composables/useOnboardingGuard'
import { readStartParam } from '@/router/startParam'
import { useAuthStore } from '@/stores/auth'
import { useTodayStore } from '@/stores/today'
import type { Streak, StreakWeekDay, TodayResponse } from '@/api/types'

/**
 * F4 "Серия спасена" / "Серия прервалась" (docs/40 §2.5): the screen `/streak/saved` is opened by itself, once per event, after `/today`
 * has come - from the fields the screen "Today" has loaded anyway (`streak.frozen_yesterday`, `streak.lost`, `streak.week`), never by a
 * request of its own. "Shown" is kept on the device (`seenFlag`): `streak_saved:<yesterday>` for a freeze, `streak_lost:<lost.on>` for a
 * loss; a saved streak goes before a lost one.
 *
 * Not shown:
 * - by an older server (no `streak.week`) - never;
 * - anywhere but on "Today" itself, and not when "Today" was opened with `?from=` (a reminder, an assignment, the end of the onboarding:
 *   the screen has its own plan for that open);
 * - while a session is open (`open_session_id`), to a learner the onboarding is still to take, and for the whole open by a deep link
 *   (`start_param`), as the guard of the onboarding;
 * - in the open that shows the one-off sheet "the trial Pro is over" on "Today" (`trialEnded.ts`): that sheet goes first, the screen of
 *   the streak waits for the next `/today` (the flag is not set).
 */

/** Which screen of the streak to show and the flag that keeps it shown once. */
export interface StreakMoment {
  kind: 'saved' | 'lost'
  key: string
}

export const STREAK_SAVED_PREFIX = 'streak_saved:'
export const STREAK_LOST_PREFIX = 'streak_lost:'

/** The day before a `Y-m-d` day, as `Y-m-d` (calendar arithmetic, no time zone involved); null for something that is no date. */
export function dayBefore(date: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date)
  if (match === null) return null
  const day = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]) - 1))
  return Number.isNaN(day.getTime()) ? null : day.toISOString().slice(0, 10)
}

/** The week of the server, when it is one: seven days and one of them today. */
export function streakWeek(streak: Streak | null | undefined): StreakWeekDay[] | null {
  const week = streak?.week
  if (!Array.isArray(week) || week.length !== 7 || !week.some((day) => day.is_today)) return null
  return week
}

/** The moment `streak` of `/today` has to show and has not shown yet; null - nothing (or an older server). */
export function streakMoment(streak: Streak | null | undefined): StreakMoment | null {
  const week = streakWeek(streak)
  if (streak === null || streak === undefined || week === null) return null

  if (streak.frozen_yesterday) {
    const today = week.find((day) => day.is_today)
    const yesterday = today ? dayBefore(today.date) : null
    if (yesterday !== null && !hasSeen(STREAK_SAVED_PREFIX + yesterday)) return { kind: 'saved', key: STREAK_SAVED_PREFIX + yesterday }
  }

  const lost = streak.lost
  if (lost && lost.days > 0 && typeof lost.on === 'string' && lost.on !== '' && !hasSeen(STREAK_LOST_PREFIX + lost.on)) {
    return { kind: 'lost', key: STREAK_LOST_PREFIX + lost.on }
  }
  return null
}

/** The one-off sheet "the trial is over" opens on "Today" in this open (`takeTrialEnded` would take it). */
function trialEndedPending(data: TodayResponse): boolean {
  const lapsed = data.entitlement.lapsed
  if (data.entitlement.plan !== 'free' || !lapsed || lapsed.provider !== 'trial' || lapsed.ended_at === '') return false
  return !hasSeen(trialEndedKey(lapsed.ended_at))
}

export interface StreakMomentsOptions {
  /** `start_param` of the launch (tests); by default the one of Telegram. */
  readStart?: () => string | null
}

/**
 * Watches the store of "Today" and opens the screen of the streak when its moment has come; returns the stop of the watch. `App.vue`
 * loads this module lazily after the start (with the store of "Today" it stays out of the main chunk), so the watch starts with the
 * answer that may already be in the store (`immediate`). Needs the active Pinia of the app.
 */
export function watchStreakMoments(router: Router, options: StreakMomentsOptions = {}): WatchStopHandle {
  const today = useTodayStore()
  const auth = useAuthStore()
  let deepLinked: boolean | null = null

  return watch(
    () => today.data,
    (data) => {
      const route = router.currentRoute.value
      if (data === null || route.name !== 'today' || route.query.from !== undefined) return
      if (data.open_session_id !== null || needsOnboarding(auth.learner)) return
      deepLinked ??= (options.readStart ?? readStartParam)() !== null
      if (deepLinked || trialEndedPending(data)) return

      const moment = streakMoment(data.streak)
      if (moment === null) return
      markSeen(moment.key)
      void router.push({ name: 'streak-saved', query: moment.kind === 'lost' ? { kind: 'lost' } : {} })
    },
    { immediate: true },
  )
}
