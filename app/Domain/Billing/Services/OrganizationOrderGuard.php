<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\AutoRenewal;
use App\Models\Organization;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a pending organization order honest about the tier it was created for (docs/13 §6.5,
 * "Реализация 2B.8"). `CheckoutService` refuses an order of another tier while a subscription is live,
 * but orders are created up to `max_pending_per_subscriber` at a time and live for `order_ttl_minutes`:
 * two Tutor orders opened before a Center M payment would, paid afterwards, each extend Center M by a
 * month at the Tutor price (`SubscriptionService::applyCharge` keeps the live tier and flags the order
 * `tier_mismatch`). Two guards close that:
 *
 *   refusal()         Payme CheckPerformTransaction / CreateTransaction and Click Prepare — BEFORE the money
 *                     is taken — refuse an organization order whose tier would not match the live one
 *                     (`tier_changed`) or whose organization is not `active` (`organization_suspended`);
 *   cancelCompeting() after a payment set / kept a tier on the organization's subscription (PaymentApplier,
 *                     after commit, own short transaction on `payment_orders` only — docs/13 §3 rule 1):
 *                     every other pending order of another tier is canceled with
 *                     `meta.canceled_reason = tier_changed`, unless the provider is mid-payment on it
 *                     (open transaction younger than the provider's timeout — {@see PaymentOrder::isBeingPaidAt}).
 *
 * Learner orders get one refusal of their own (`already_subscribed`, "Реализация 2A.8"): an order created
 * before the learner subscribed with Stars (or in a parallel checkout) must not be paid by Payme / Click on top
 * of a Stars subscription that keeps charging — the provider could not be told to stop it afterwards.
 *
 * The `tier_mismatch` flag stays as the backstop for what slips through both (a Perform / Complete that
 * was already in flight): the money is credited, the tier is kept, the platform looks at the order.
 */
final class OrganizationOrderGuard
{
    public const REFUSAL_TIER_CHANGED = 'tier_changed';

    public const REFUSAL_ORGANIZATION_SUSPENDED = 'organization_suspended';

    /** A learner's order while their Stars subscription still renews (docs/13 "Реализация 2A.8"). */
    public const REFUSAL_ALREADY_SUBSCRIBED = 'already_subscribed';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Why a provider must not open a payment on this order right now, or null when it may: an organization
     * order whose tier / status no longer fits, a learner order while their Stars subscription renews.
     * Plain reads — call it outside a transaction (pre-check) or under the order lock
     * (`ProviderTransactionService::open`).
     *
     * @return self::REFUSAL_*|null
     */
    public function refusal(PaymentOrder $order, CarbonImmutable $now): ?string
    {
        if ($order->subscriber_type !== 'organization') {
            return $order->subscriber_type === 'learner' && AutoRenewal::forLearner($order->subscriber_id, $now) !== null
                ? self::REFUSAL_ALREADY_SUBSCRIBED
                : null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($order->subscriber_id)->first();

        if ($organization === null || ! $organization->isActive()) {
            return self::REFUSAL_ORGANIZATION_SUSPENDED;
        }

        if ($order->tier === null) {
            return null;
        }

        $live = $this->subscriptions->liveFor($organization);

        if ($live !== null && CheckoutService::changesLiveTier($live, $order->tier, $now)) {
            return self::REFUSAL_TIER_CHANGED;
        }

        return null;
    }

    /**
     * After the first payment of a learner's Stars subscription: closes the learner's other pending Stars `month`
     * orders (`meta.canceled_reason = already_subscribed`) — paying one of them would open a second Telegram
     * subscription. Their tokens die at every pre-check anyway; this keeps the order list and the pending
     * limit honest. A charge already in flight on such an order is still credited (and flagged) by PaymentApplier.
     * Locks `payment_orders` only.
     *
     * @return int how many orders were canceled
     */
    public function cancelCompetingStarsSubscriptions(int $learnerId, int $paidOrderId, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($learnerId, $paidOrderId, $now): int {
            /** @var Collection<int, PaymentOrder> $competing */
            $competing = PaymentOrder::query()
                ->where('subscriber_type', 'learner')
                ->where('subscriber_id', $learnerId)
                ->where('provider', SubscriptionProvider::TelegramStars->value)
                ->where('period', 'month')
                ->where('status', PaymentOrder::STATUS_PENDING)
                ->whereKeyNot($paidOrderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($competing as $order) {
                $order->fill([
                    'status' => PaymentOrder::STATUS_CANCELED,
                    'meta' => [
                        ...$order->meta,
                        'canceled_reason' => PaymentOrder::CANCEL_REASON_ALREADY_SUBSCRIBED,
                        'canceled_at' => $now->toIso8601String(),
                        'already_subscribed_by_order_id' => $paidOrderId,
                    ],
                ])->save();

                Log::info('billing.stars_order_canceled', [
                    'payment_order_id' => $order->id,
                    'learner_id' => $learnerId,
                    'paid_order_id' => $paidOrderId,
                    'reason' => PaymentOrder::CANCEL_REASON_ALREADY_SUBSCRIBED,
                ]);
            }

            return $competing->count();
        });
    }

    /**
     * Cancels the organization's other pending orders whose tier is not `$liveTier` — the ones that could
     * only ever be paid into a `tier_mismatch`. An order the provider is mid-payment on is left alone: its
     * Perform / Complete lands in the `tier_mismatch` backstop. Locks `payment_orders` only.
     *
     * @return int how many orders were canceled
     */
    public function cancelCompeting(int $organizationId, string $liveTier, int $paidOrderId, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($organizationId, $liveTier, $paidOrderId, $now): int {
            /** @var Collection<int, PaymentOrder> $competing */
            $competing = PaymentOrder::query()
                ->where('subscriber_type', 'organization')
                ->where('subscriber_id', $organizationId)
                ->where('status', PaymentOrder::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('tier')->orWhere('tier', '!=', $liveTier))
                ->whereKeyNot($paidOrderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $canceled = 0;

            foreach ($competing as $order) {
                // Re-read under the lock: a CreateTransaction / Prepare that committed while we waited counts.
                if (! $order->isPending() || $order->isBeingPaidAt($now)) {
                    continue;
                }

                $order->fill([
                    'status' => PaymentOrder::STATUS_CANCELED,
                    'meta' => [
                        ...$order->meta,
                        'canceled_reason' => PaymentOrder::CANCEL_REASON_TIER_CHANGED,
                        'canceled_at' => $now->toIso8601String(),
                        'tier_changed_by_order_id' => $paidOrderId,
                        'tier_changed_to' => $liveTier,
                    ],
                ])->save();

                Log::info('billing.org_order_canceled', [
                    'payment_order_id' => $order->id,
                    'organization_id' => $organizationId,
                    'ordered_tier' => $order->tier,
                    'live_tier' => $liveTier,
                    'paid_order_id' => $paidOrderId,
                    'reason' => PaymentOrder::CANCEL_REASON_TIER_CHANGED,
                ]);

                $canceled++;
            }

            return $canceled;
        });
    }
}
