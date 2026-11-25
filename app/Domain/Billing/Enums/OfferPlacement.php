<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Where the Mini App showed a Pro offer (`billing.offer_viewed.placement`, `checkout.started.placement`; docs/13 §5.1). A closed list
 * of the real places, mirrored by `OfferPlacement` of `resources/twa/src/composables/offerViewed.ts`:
 *
 *  - `paywall_<reason>`: the purchase sheet (`PaywallSheet`, prices and pay buttons) by the reason it was opened for. The practice
 *    and the battle walls are `paywall_practice` / `paywall_practice_limit`, every wall of the game is `paywall_game`, the end of the
 *    Free new words is `paywall_quota`, "what Pro gives" from Today, the Subscription screen, the finish tip and the trial card is
 *    `paywall_info`. A checkout is only ever started from the sheet, so `checkout.started.placement` is one of these;
 *  - `billing_view`: the Subscription screen with its "Buy / Renew" button;
 *  - `lapsed_banner`: "Pro ended — renew" (a paid Pro or the welcome trial ran out) with its renew button;
 *  - `finished_pro_tip`: the Pro tip of the finish screen;
 *  - `finished_trial_ending`: the "the trial ends in N days" card of the finish screen.
 *
 * A new place gets a case here and in the client together; the report reads unknown values as they are, never fails on them.
 */
enum OfferPlacement: string
{
    case PaywallQuota = 'paywall_quota';
    case PaywallInfo = 'paywall_info';
    case PaywallDecks = 'paywall_decks';
    case PaywallStreakFreeze = 'paywall_streak_freeze';
    case PaywallExamSimulator = 'paywall_exam_simulator';
    case PaywallPractice = 'paywall_practice';
    case PaywallPracticeLimit = 'paywall_practice_limit';
    case PaywallGame = 'paywall_game';
    /** The sheet opened by a locked daily goal above the Free limit, or by `403 daily_goal_pro_required` (docs/05 §8c-1). */
    case PaywallDailyGoal = 'paywall_daily_goal';
    /** The one-off sheet "the trial is over" at the first opening after the end of the welcome trial (docs/05 §8c-1, P7). */
    case PaywallTrialEnded = 'paywall_trial_ended';
    case BillingView = 'billing_view';
    case LapsedBanner = 'lapsed_banner';
    case FinishedProTip = 'finished_pro_tip';
    case FinishedTrialEnding = 'finished_trial_ending';
}
