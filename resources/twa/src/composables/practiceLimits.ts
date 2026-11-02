import { calendarDay, formatDate } from '@/composables/format'
import { t } from '@/i18n'
import type { PracticeLimit, PracticeLimitHit, PracticeLimitKind, PracticeLimits } from '@/api/types'

/**
 * The Free limits of practice (docs/18 §3, G1): two counters of the learner's "today" - the sessions of practice and the battles they
 * start. The server owns them; these are the small pure rules the screens share:
 * - `limitHitFrom` reads the `details` of 403 `practice_limit_reached`;
 * - `liveLimit` and `reachedLimit` tell what a counter says while its day lasts;
 * - `resetWhen` says in words when the counters are full again.
 */

const KINDS: readonly PracticeLimitKind[] = ['sessions', 'battles']

const isCount = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value) && value >= 0

/** `details` of 403 `practice_limit_reached` as the paywall needs them; null when the server's fields do not add up (the message of the code is told then). */
export function limitHitFrom(details: Record<string, unknown> | null): PracticeLimitHit | null {
  if (details === null) return null
  const { kind, limit, used, resets_at: resetsAt } = details
  if (!KINDS.includes(kind as PracticeLimitKind) || !isCount(limit) || !isCount(used)) return null
  if (typeof resetsAt !== 'string' || !Number.isFinite(Date.parse(resetsAt))) return null
  return { kind: kind as PracticeLimitKind, limit, used, resets_at: resetsAt }
}

/**
 * A counter while it still describes today. Past its `resets_at` a new day has begun: the number is out of date, it says nothing any more
 * (null), and the server is asked as usual - an app that stayed open overnight must not keep the learner behind yesterday's last session.
 */
export function liveLimit(limit: PracticeLimit | null | undefined, now: number = Date.now()): PracticeLimit | null {
  if (!limit) return null
  const resetsAt = Date.parse(limit.resets_at)
  return Number.isFinite(resetsAt) && resetsAt <= now ? null : limit
}

/**
 * The wall of `kind` when the counter says today's free allowance is spent: what the paywall needs, without asking the server. Null when
 * there is some left, when nothing is known (Pro, no limit, `/me` not read yet) and when the counter's day is over - the server decides then.
 */
export function reachedLimit(limits: PracticeLimits | null | undefined, kind: PracticeLimitKind, now: number = Date.now()): PracticeLimitHit | null {
  const counter = liveLimit(limits?.[kind], now)
  if (counter === null || counter.remaining > 0) return null
  return { kind, limit: counter.limit, used: counter.used, resets_at: counter.resets_at }
}

/**
 * When the counters are full again, in words, in the learner's zone (`/today` names it): "tomorrow at 00:00" when `resetsAt` is the start of
 * the learner's next day - what the server sends, the end of their "today" - else the time alone ("at 00:00"). The reset is always at that time
 * of the learner's day; a device clock that is off must not make the text claim a day it cannot vouch for.
 */
export function resetWhen(resetsAt: string, locale: string, timeZone?: string, now: number = Date.now()): string {
  const time = formatDate(resetsAt, 'time', locale, timeZone)
  if (time === '') return ''
  // The minute before the reset is the last minute of the day that ends there. The reset is "tomorrow" when it is the very turn of that day
  // (not just some hour of it) and that day is today.
  const at = Date.parse(resetsAt)
  const lastDay = calendarDay(at - 60_000, timeZone)
  const tomorrow = calendarDay(at, timeZone) !== lastDay && calendarDay(now, timeZone) === lastDay
  return t(tomorrow ? 'paywall.reset_tomorrow' : 'paywall.reset_at', { time })
}
