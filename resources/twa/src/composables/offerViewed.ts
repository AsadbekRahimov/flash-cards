import { api } from '@/api'
import { useBillingStore } from '@/stores/billing'
import type { PaywallReason } from '@/components/PaywallSheet.vue'

/**
 * Where a Pro offer can become visible (docs/13 §5.1): the closed list of `App\Domain\Billing\Enums\OfferPlacement`, in the same order
 * (`OfferViewedApiTest` compares the two). The purchase sheet is `paywall_<reason>`; the other places are the cards and screens that
 * offer Pro by themselves.
 */
export const OFFER_PLACEMENTS = [
  'paywall_quota',
  'paywall_info',
  'paywall_decks',
  'paywall_streak_freeze',
  'paywall_exam_simulator',
  'paywall_practice',
  'paywall_practice_limit',
  'paywall_game',
  'billing_view',
  'lapsed_banner',
  'finished_pro_tip',
  'finished_trial_ending',
] as const

export type OfferPlacement = (typeof OFFER_PLACEMENTS)[number]

/** The placement of the purchase sheet opened for `reason` (a new reason that has no placement does not compile). */
export function paywallPlacement(reason: PaywallReason): OfferPlacement {
  return `paywall_${reason}`
}

/** What was already sent in this run of the app, per device day: the server counts once a day anyway, this saves the requests. */
const sent = new Set<string>()

function deviceDay(): string {
  const now = new Date()
  return `${now.getFullYear()}-${now.getMonth() + 1}-${now.getDate()}`
}

/**
 * "The learner saw a Pro offer here" (`POST /billing/offer-viewed`), the first step of the Pro funnel. Call it where the offer becomes
 * visible. Fire-and-forget: it never waits, never throws and never shows anything - a failure is swallowed, analytics must not touch
 * the screen. Nothing is sent while the server sells nothing (`paywall.mode` of `/me` is not `purchase`) or before `/me` is known: an
 * offer that cannot be bought is not a step of the funnel, and the helper never asks for `/me` by itself.
 */
export function reportOfferViewed(placement: OfferPlacement): void {
  try {
    if (useBillingStore().paywall?.mode !== 'purchase') return
    const key = `${deviceDay()}:${placement}`
    if (sent.has(key)) return
    sent.add(key)
    void api.billing.offerViewed(placement).catch(() => {
      // analytics only: the learner never sees it
    })
  } catch {
    // no store, no client: nothing to report, nothing to break
  }
}

/** Tests: forget what was sent. */
export function resetOfferViewed(): void {
  sent.clear()
}
