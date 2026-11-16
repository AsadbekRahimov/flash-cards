import type { Entitlement } from '@/api/types'

/**
 * The welcome trial of a new learner (docs/04 A.2, 2C.9) has no field of its own: it is the live subscription with
 * `renewal.provider === 'trial'` while it runs and `lapsed.provider === 'trial'` once it is over.
 */
export const TRIAL_PROVIDER = 'trial'

const DAY_MS = 86_400_000

/** The end of the trial that is running now; null for no trial (or a trial with no end, which the server never sends). */
export function trialEndsAt(entitlement: Pick<Entitlement, 'plan' | 'renewal'> | null | undefined): string | null {
  if (!entitlement || entitlement.plan !== 'pro') return null
  const renewal = entitlement.renewal ?? null
  return renewal?.provider === TRIAL_PROVIDER ? renewal.ends_at : null
}

/** Whole days left, rounded up and never below 1 (the last day is "1"): the line says "ends today" for 1. */
export function trialDaysLeft(endsAt: string, now: number = Date.now()): number {
  const left = Math.ceil((new Date(endsAt).getTime() - now) / DAY_MS)
  return Number.isFinite(left) ? Math.max(1, left) : 1
}
