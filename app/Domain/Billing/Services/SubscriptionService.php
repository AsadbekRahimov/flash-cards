<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Events\SubscriptionRenewalDue;
use App\Domain\Billing\Exceptions\PromoCodeException;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\PaymentOrder;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle of `subscriptions` (docs/12 §3.4, §5.5, §5.6, state machine §6.2; docs/13 §2.1).
 *
 * Invariant: at most one live (trialing / active / past_due / canceled) subscription
 * per subscriber. It is enforced twice: by the partial unique index
 * `subscriptions_one_live_per_subscriber` (PG / sqlite) and, for every driver,
 * by taking a row lock on the subscriber (learners / organizations) before
 * reading its live subscription — grant, promo, charge, cancel and revoke of the
 * same subject are serialized, so a "live" check cannot be stale when the row is written.
 *
 * LOCK ORDER (docs/13 §3) — every write path takes its row locks in this order
 * and never in another:
 *
 *   [promo_codes] -> [payment_orders -> provider_transactions] -> subject row (learners / organizations)
 *                 -> subscriptions -> FK inserts (payments, promo_redemptions, product_events, audit_logs)
 *
 * The last step matters: inserting into `product_events` / `promo_redemptions` /
 * `audit_logs` takes `FOR KEY SHARE` on the referenced learner / promo / user,
 * which conflicts with another transaction's `FOR UPDATE` on that row. That is
 * why `revoke()`, `expireOne()`, `cancel()`, `resume()` and `endNow()` lock the
 * subject row *before* the subscription even though they do not need it for the
 * invariant: locking the subscription first and the learner last (implicitly,
 * through the FK) deadlocked against `grantManual` / `grantFromPromo`, which hold
 * the learner and wait for the subscription. The subject is read without a lock
 * first (`subscriber_*` never change after insert), locked, and only then the
 * subscription row is locked and re-checked. None of these paths ever touches
 * `payment_orders` (rule 1 of docs/13 §3).
 *
 * Every transition writes an audit record (when a human did it), a product
 * event and schedules the entitlement cache invalidation after commit.
 */
final class SubscriptionService
{
    public const ACTION_GRANTED = 'subscription.granted';

    public const ACTION_REVOKED = 'subscription.revoked';

    public const ACTION_CANCELED = 'subscription.canceled';

    public const ACTION_RESUMED = 'subscription.resumed';

    public const ACTION_ENDED = 'subscription.ended';

    /** A paid organization order whose tier differs from the live one: the tier was kept, the platform must look (2B.8). */
    public const ACTION_TIER_MISMATCH = 'subscription.tier_mismatch';

    /** `meta.renewal_reminders`: list of "<daysLeft>@<period end ISO>" markers already raised. */
    public const META_RENEWAL_REMINDERS = 'renewal_reminders';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly ProductEventRecorder $events,
        private readonly OrganizationMembershipService $memberships,
    ) {}

    /**
     * Manual grant by a superadmin (Filament "Grant Pro"). If the subscriber
     * already has a live subscription it is extended / replaced in place (same
     * row id, `current_period_ends_at = $until`, provider becomes `manual`), so
     * the one-live-row invariant and `promo_redemptions.subscription_id` hold.
     *
     * @param  CarbonImmutable|null  $until  end of the grant; null = perpetual
     * @param  string  $note  reason (stored on the row and in the audit record)
     * @param  string|null  $tier  organization tariff (docs/13 §6.1): sets `tier` and `seats_limit` from config; ignored for learners
     * @param  int|null  $seatsLimit  seats for a tier WITHOUT fixed seats (center_l, "by agreement"); null = by tariff; ignored when the tariff fixes the seats
     *
     * @throws SubscriptionException plan_not_grantable | until_in_past | subscriber_not_found | tier_unknown
     */
    public function grantManual(
        Learner|Organization $subscriber,
        Plan $plan,
        ?CarbonImmutable $until,
        User $by,
        string $note,
        ?string $ip = null,
        ?CarbonImmutable $now = null,
        ?string $tier = null,
        ?int $seatsLimit = null,
    ): Subscription {
        $now ??= CarbonImmutable::now();

        if ($plan->rank() <= Plan::Free->rank()) {
            throw SubscriptionException::planNotGrantable($plan->value);
        }

        if ($until !== null && $until->lessThanOrEqualTo($now)) {
            throw SubscriptionException::untilInPast();
        }

        $tier = $subscriber instanceof Organization ? $tier : null;
        if ($tier !== null && ! OrgTiers::exists($tier)) {
            throw SubscriptionException::tierUnknown($tier);
        }

        return DB::transaction(function () use ($subscriber, $plan, $until, $by, $note, $ip, $now, $tier, $seatsLimit): Subscription {
            $this->lockSubscriber($subscriber);

            $live = $this->lockedLive($subscriber);
            $extended = $live !== null;

            $attributes = [
                'plan' => $plan->value,
                'status' => SubscriptionStatus::Active->value,
                'provider' => SubscriptionProvider::Manual->value,
                'provider_ref' => null,
                'current_period_ends_at' => $until,
                'grace_until' => null,
                'canceled_at' => null,
                'ended_at' => null,
                'granted_by_user_id' => $by->id,
                'note' => $note,
            ];

            if ($tier !== null) {
                $attributes['tier'] = $tier;
                $attributes['seats_limit'] = OrgTiers::seats($tier) ?? ($seatsLimit === null ? null : max(1, $seatsLimit));
            }

            if ($live === null) {
                $subscription = Subscription::query()->create([
                    'subscriber_type' => self::subscriberType($subscriber),
                    'subscriber_id' => $subscriber->id,
                    'starts_at' => $now,
                    'meta' => [],
                    ...$attributes,
                ]);
            } else {
                $meta = $live->meta;
                $meta['history'][] = [
                    'at' => $now->toIso8601String(),
                    'action' => 'manual_grant',
                    'from' => $this->snapshot($live),
                    'by_user_id' => $by->id,
                ];
                $live->fill([...$attributes, 'meta' => $meta])->save();
                $subscription = $live;
            }

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::ACTION_GRANTED,
                'entity_type' => Subscription::class,
                'entity_id' => $subscription->id,
                'ip' => $ip,
                'meta' => [
                    'subscriber_type' => $subscription->subscriber_type,
                    'subscriber_id' => $subscription->subscriber_id,
                    'plan' => $plan->value,
                    'tier' => $subscription->tier,
                    'until' => $until?->toIso8601String(),
                    'note' => $note,
                    'extended' => $extended,
                ],
            ]);

            $this->events->record(
                ProductEventName::SubscriptionGranted,
                ['plan' => $plan->value, 'provider' => SubscriptionProvider::Manual->value, 'subscriber_type' => $subscription->subscriber_type, 'extended' => $extended],
                learner: $subscriber instanceof Learner ? $subscriber : null,
                user: $by,
            );

            $this->forgetAfterCommit($subscription);

            return $subscription;
        });
    }

    /**
     * Money received for an order (docs/13 §2.1). Runs inside PaymentApplier's transaction;
     * the order is locked and the subject row is already locked by the caller — this method
     * only locks the live subscription.
     *
     *   no live row : `active`, provider = event provider, period from the order (or the
     *                 provider's `periodEndsAt`), tier / seats from the order (organizations);
     *   live row    : the period end becomes max(current end, periodEndsAt ?? max(end, now) + period) —
     *                 a payment never shortens Pro; status -> active (back from past_due /
     *                 canceled when Stars renews); provider = the provider of the last payment.
     *                 A perpetual grant keeps its NULL end (nothing to extend; the ledger still
     *                 records the money and a warning is logged). For an organization the order's tier
     *                 is applied ONLY when it equals the live tier (or the live row has no tier / its
     *                 period has run out); otherwise the tier and seats stay, the order gets
     *                 `meta.tier_mismatch = true`, `billing.org_tier_mismatch` is logged and audited
     *                 (`subscription.tier_mismatch`) — docs/13 §6.5, 2B.8.
     *
     * `provider_ref` holds the last charge id; for Stars the charge id of the *first* payment of
     * the subscription (the one `editUserStarSubscription` needs) is kept in `meta.stars.charge_id`.
     *
     * @internal for PaymentApplier
     */
    public function applyCharge(Learner|Organization $subscriber, PaymentOrder $order, ChargeEvent $e, CarbonImmutable $now): Subscription
    {
        $plan = Plan::fromKey($order->plan);
        $days = self::periodDays($order->period);
        $live = $this->lockedLive($subscriber);
        $kind = $e->isRenewal ? 'renewal' : 'charge';

        if ($live === null) {
            $meta = ['history' => [[
                'at' => $now->toIso8601String(),
                'action' => $kind,
                'payment_order_id' => $order->id,
            ]]];
            if ($e->provider === SubscriptionProvider::TelegramStars && $e->providerChargeId !== null) {
                $meta['stars'] = ['charge_id' => $e->providerChargeId];
            }

            $attributes = [
                'subscriber_type' => self::subscriberType($subscriber),
                'subscriber_id' => $subscriber->id,
                'plan' => $plan->value,
                'status' => SubscriptionStatus::Active->value,
                'provider' => $e->provider->value,
                'provider_ref' => $e->providerChargeId,
                'starts_at' => $now,
                'current_period_ends_at' => $e->periodEndsAt ?? $now->addDays($days),
                'grace_until' => null,
                'canceled_at' => null,
                'ended_at' => null,
                'note' => null,
                'meta' => $meta,
            ];

            if ($subscriber instanceof Organization && $order->tier !== null) {
                $attributes['tier'] = $order->tier;
                $attributes['seats_limit'] = OrgTiers::seats($order->tier);
            }

            $subscription = Subscription::query()->create($attributes);
        } else {
            $ends = null;

            if ($live->current_period_ends_at === null) {
                Log::warning('billing.charge_on_perpetual_subscription', [
                    'subscription_id' => $live->id,
                    'payment_order_id' => $order->id,
                    'provider' => $e->provider->value,
                ]);
            } else {
                $currentEnd = CarbonImmutable::instance($live->current_period_ends_at);
                $from = $currentEnd->greaterThan($now) ? $currentEnd : $now;
                $proposed = $e->periodEndsAt ?? $from->addDays($days);
                $ends = $proposed->greaterThan($currentEnd) ? $proposed : $currentEnd;
            }

            $orderedTier = $subscriber instanceof Organization ? $order->tier : null;
            $tierMismatch = $orderedTier !== null && $this->tierMismatch($live, $orderedTier, $now);

            $meta = $live->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => $kind,
                'payment_order_id' => $order->id,
                'from' => $this->snapshot($live),
                ...($tierMismatch ? ['tier_mismatch' => ['ordered' => $orderedTier, 'kept' => $live->tier]] : []),
            ];
            if ($e->provider === SubscriptionProvider::TelegramStars && $e->providerChargeId !== null
                && (! $e->isRenewal || ! isset($meta['stars']['charge_id']))) {
                $meta['stars'] = ['charge_id' => $e->providerChargeId];
            }

            $attributes = [
                'plan' => $plan->outranks(Plan::fromKey($live->plan)) ? $plan->value : $live->plan,
                'status' => SubscriptionStatus::Active->value,
                'provider' => $e->provider->value,
                'provider_ref' => $e->providerChargeId ?? $live->provider_ref,
                'current_period_ends_at' => $live->current_period_ends_at === null ? null : $ends,
                'grace_until' => null,
                'canceled_at' => null,
                'ended_at' => null,
                'meta' => $meta,
            ];

            if ($orderedTier !== null && ! $tierMismatch) {
                $attributes['tier'] = $orderedTier;
                $attributes['seats_limit'] = OrgTiers::seats($orderedTier);
            }

            $live->fill($attributes)->save();
            $subscription = $live;

            if ($tierMismatch) {
                $this->flagTierMismatch($live, $order, $orderedTier, $e, $now);
            }
        }

        $this->events->record(
            ProductEventName::SubscriptionGranted,
            ['plan' => $subscription->plan, 'provider' => $e->provider->value, 'subscriber_type' => $subscription->subscriber_type, 'extended' => $live !== null, 'kind' => $kind],
            learner: $subscriber instanceof Learner ? $subscriber : null,
        );

        $this->forgetAfterCommit($subscription);

        return $subscription;
    }

    /**
     * The user stops the auto-renewal: `active` -> `canceled` (`canceled_at = now`), the paid
     * period is untouched and stays live until its end (docs/13 §2.1). Only for an auto-renewing
     * provider; the caller has already asked the provider to stop (`cancelAutoRenew`) OUTSIDE
     * this transaction (docs/13 §3 rule 2, §4.1).
     *
     * @throws SubscriptionException not_cancelable
     */
    public function cancel(Subscription $subscription, Learner|User $by, ?string $ip = null, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $by, $ip, $now): Subscription {
            $locked = $this->lockForTransition($subscription->id);

            if ($locked === null) {
                throw SubscriptionException::notCancelable($subscription->id, 'it does not exist');
            }

            if ($locked->status !== SubscriptionStatus::Active->value) {
                throw SubscriptionException::notCancelable($locked->id, "status is {$locked->status}");
            }

            if (! (SubscriptionProvider::tryFrom($locked->provider)?->isAutoRenewing() ?? false)) {
                throw SubscriptionException::notCancelable($locked->id, "provider {$locked->provider} does not renew");
            }

            $meta = $locked->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'canceled',
                'from' => $this->snapshot($locked),
                'by_user_id' => $by instanceof User ? $by->id : null,
            ];

            $locked->fill([
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => $now,
                'grace_until' => null,
                'meta' => $meta,
            ])->save();

            if ($by instanceof User) {
                $this->audit($by, self::ACTION_CANCELED, $locked, $ip, []);
            }

            $this->events->record(
                ProductEventName::SubscriptionCanceled,
                ['plan' => $locked->plan, 'provider' => $locked->provider, 'subscriber_type' => $locked->subscriber_type, 'by' => $by instanceof User ? 'admin' : 'learner'],
                learner: $this->learnerOf($locked),
                user: $by instanceof User ? $by : null,
            );

            $this->forgetAfterCommit($locked);
            $subscription->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * `canceled` -> `active` while the period has not ended (the provider's `resumeAutoRenew`
     * was called by the caller outside this transaction).
     *
     * @throws SubscriptionException not_resumable
     */
    public function resume(Subscription $subscription, Learner|User $by, ?string $ip = null, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $by, $ip, $now): Subscription {
            $locked = $this->lockForTransition($subscription->id);

            if ($locked === null) {
                throw SubscriptionException::notResumable($subscription->id, 'it does not exist');
            }

            if ($locked->status !== SubscriptionStatus::Canceled->value) {
                throw SubscriptionException::notResumable($locked->id, "status is {$locked->status}");
            }

            if ($locked->current_period_ends_at !== null && ! $locked->current_period_ends_at->greaterThan($now)) {
                throw SubscriptionException::notResumable($locked->id, 'the period has ended');
            }

            $meta = $locked->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'resumed',
                'from' => $this->snapshot($locked),
                'by_user_id' => $by instanceof User ? $by->id : null,
            ];

            $locked->fill([
                'status' => SubscriptionStatus::Active->value,
                'canceled_at' => null,
                'meta' => $meta,
            ])->save();

            if ($by instanceof User) {
                $this->audit($by, self::ACTION_RESUMED, $locked, $ip, []);
            }

            $this->events->record(
                ProductEventName::SubscriptionResumed,
                ['plan' => $locked->plan, 'provider' => $locked->provider, 'subscriber_type' => $locked->subscriber_type],
                learner: $this->learnerOf($locked),
                user: $by instanceof User ? $by : null,
            );

            $this->forgetAfterCommit($locked);
            $subscription->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Ends a live subscription right now (`expired`, `ended_at = now`) for a refund / chargeback
     * (docs/13 §2.1). Lock order: subject -> subscription -> inserts; may run inside
     * PaymentApplier::applyRefund's transaction (the subject lock is then already held).
     *
     * @param  string  $reason  `refund` | `chargeback` | ... (product event `subscription.expired {reason}`)
     *
     * @throws SubscriptionException subscription_not_live
     */
    public function endNow(Subscription $subscription, string $reason, ?User $by = null, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $reason, $by, $now): Subscription {
            $locked = $this->lockForTransition($subscription->id);

            if ($locked === null || ! SubscriptionStatus::from($locked->status)->isLive()) {
                throw SubscriptionException::notLive($subscription->id);
            }

            $meta = $locked->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'ended',
                'reason' => $reason,
                'from' => $this->snapshot($locked),
                'by_user_id' => $by?->id,
            ];

            $locked->fill([
                'status' => SubscriptionStatus::Expired->value,
                'ended_at' => $now,
                'grace_until' => null,
                'meta' => $meta,
            ])->save();

            if ($by !== null) {
                $this->audit($by, self::ACTION_ENDED, $locked, null, ['reason' => $reason]);
            }

            $this->recordExpired($locked, $reason, $by);
            $this->forgetAfterCommit($locked);
            $subscription->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Takes `$seconds` off (null = the whole period, i.e. end now) the paid period of a live subscription (a refund of one of several paid
     * periods: the refunded days are removed, the rest stays). When the new end is not after `$now`
     * (or the subscription has no end to shorten, or `$seconds` is null) the subscription ends now like {@see endNow()}.
     * Returns true when it ended, false when it was only shortened.
     *
     * Lock order: subject row -> subscription (the caller may already hold both).
     *
     * @throws SubscriptionException subscription_not_live
     */
    public function shortenPeriod(Subscription $subscription, ?int $seconds, string $reason, ?User $by = null, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $seconds, $reason, $by, $now): bool {
            $locked = $this->lockForTransition($subscription->id);

            if ($locked === null || ! SubscriptionStatus::from($locked->status)->isLive()) {
                throw SubscriptionException::notLive($subscription->id);
            }

            $end = $seconds === null || $locked->current_period_ends_at === null ? null : CarbonImmutable::instance($locked->current_period_ends_at)->subSeconds(max(0, $seconds));

            if ($end === null || $end->lessThanOrEqualTo($now)) {
                $this->endNow($locked, $reason, $by, $now);
                $subscription->setRawAttributes($locked->getAttributes(), true);

                return true;
            }

            $meta = $locked->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'shortened',
                'reason' => $reason,
                'from' => $this->snapshot($locked),
                'by_user_id' => $by?->id,
            ];

            $locked->fill(['current_period_ends_at' => $end, 'meta' => $meta])->save();

            $this->forgetAfterCommit($locked);
            $subscription->setRawAttributes($locked->getAttributes(), true);

            return false;
        });
    }

    /**
     * Ends a live subscription now (status `expired`, `ended_at = now`).
     *
     * Lock order: subject row (if it still exists) -> subscription -> inserts.
     *
     * @throws SubscriptionException subscription_not_live
     */
    public function revoke(Subscription $subscription, User $by, string $note, ?string $ip = null, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $by, $note, $ip, $now): Subscription {
            $locked = $this->lockForTransition($subscription->id);

            if ($locked === null || ! SubscriptionStatus::from($locked->status)->isLive()) {
                throw SubscriptionException::notLive($subscription->id);
            }

            $meta = $locked->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'revoked',
                'from' => $this->snapshot($locked),
                'by_user_id' => $by->id,
                'note' => $note,
            ];

            $locked->fill([
                'status' => SubscriptionStatus::Expired->value,
                'ended_at' => $now,
                'grace_until' => null,
                'meta' => $meta,
            ])->save();

            $this->audit($by, self::ACTION_REVOKED, $locked, $ip, ['note' => $note]);

            $this->recordExpired($locked, 'revoked', $by);
            $this->forgetAfterCommit($locked);

            $subscription->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Scheduler (`subscriptions:expire`, hourly): applies the time-based
     * transitions of docs/12 §5.6 / §6.2 and returns how many rows changed.
     *
     *   trialing / active with `current_period_ends_at <= now`
     *     -> past_due with `grace_until = ends + grace_days` (auto-renewing provider)
     *     -> expired                                        (otherwise, or grace already over)
     *   canceled with `current_period_ends_at <= now`      -> expired (never grace)
     *   past_due with `grace_until <= now` (or NULL)       -> expired
     *
     * Each row is re-checked under `lockForUpdate` in its own transaction, so a
     * concurrent run or a grant racing the scheduler cannot double-apply.
     */
    public function expireDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = Subscription::query()
            ->where(function ($q) use ($now): void {
                $q->where(function ($ended) use ($now): void {
                    $ended->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::Canceled->value])
                        ->whereNotNull('current_period_ends_at')
                        ->where('current_period_ends_at', '<=', $now);
                })->orWhere(function ($graceOver) use ($now): void {
                    $graceOver->where('status', SubscriptionStatus::PastDue->value)
                        ->where(fn ($g) => $g->whereNull('grace_until')->orWhere('grace_until', '<=', $now));
                });
            })
            ->orderBy('id')
            ->pluck('id');

        $changed = 0;

        foreach ($ids as $id) {
            if ($this->expireOne((int) $id, $now)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Scheduler (`subscriptions:remind-expiring`, daily; docs/13 §4.4): for every live paid
     * subscription that will NOT renew by itself — a Payme / Click period, or a `canceled`
     * auto-renewal — whose period ends within the next N days (N from
     * `billing.checkout.renewal_reminder_days`, e.g. 3 and 1), raises
     * {@see SubscriptionRenewalDue} once per (subscription, period end, N). Grants (`manual`,
     * `promo`, `reward`) are never reminded. Returns how many events were raised.
     *
     * Idempotency: the marker "<N>@<period end>" is written to `meta.renewal_reminders` under
     * the subject + subscription locks before the event is dispatched (after commit); the
     * period end is part of the key, so a renewal naturally re-arms the reminders.
     */
    public function remindExpiring(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $days = $this->reminderDays();

        if ($days === []) {
            return 0;
        }

        $autoRenewing = array_values(array_map(
            static fn (SubscriptionProvider $p): string => $p->value,
            array_filter(SubscriptionProvider::cases(), static fn (SubscriptionProvider $p): bool => $p->isAutoRenewing()),
        ));
        $grants = array_values(array_map(
            static fn (SubscriptionProvider $p): string => $p->value,
            array_filter(SubscriptionProvider::cases(), static fn (SubscriptionProvider $p): bool => ! $p->isPaid()),
        ));

        $ids = Subscription::query()
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->whereIn('plan', PaidSubscriptionQuery::paidPlans())
            ->whereNotIn('provider', $grants)
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '>', $now)
            ->where('current_period_ends_at', '<=', $now->addDays(max($days)))
            ->where(fn (Builder $q) => $q
                ->where('status', SubscriptionStatus::Canceled->value)
                ->orWhereNotIn('provider', $autoRenewing))
            ->orderBy('id')
            ->pluck('id');

        $raised = 0;

        foreach ($ids as $id) {
            if ($this->remindOne((int) $id, $days, $now)) {
                $raised++;
            }
        }

        return $raised;
    }

    /** The subscriber's live subscription, if any (not locked; for display). */
    public function liveFor(Learner|Organization $subscriber): ?Subscription
    {
        /** @var Subscription|null $live */
        $live = $this->liveQuery($subscriber)->first();

        return $live;
    }

    /**
     * Promo redemption: `now + duration_days` of the code's plan. An existing
     * live personal subscription with an end date is extended by the duration
     * (from its end or from now, whichever is later) and re-activated if it was
     * in grace or canceled; a perpetual one cannot be extended (promo_not_applicable).
     * Must run inside the caller's transaction (PromoCodeService holds the promo
     * row lock); locks the learner row itself.
     *
     * @internal called by PromoCodeService::redeem
     *
     * @throws PromoCodeException promo_not_applicable
     */
    public function grantFromPromo(Learner $learner, PromoCode $promo, CarbonImmutable $now): Subscription
    {
        $plan = Plan::fromKey($promo->plan);
        $days = max(1, $promo->duration_days);

        $this->lockSubscriber($learner);
        $live = $this->lockedLive($learner);

        if ($live !== null && $live->current_period_ends_at === null) {
            throw PromoCodeException::notApplicable();
        }

        $subscription = $this->grantDays($learner, $live, $plan, $days, SubscriptionProvider::Promo, (string) $promo->id, 'promo_extension', ['promo_code_id' => $promo->id, 'days' => $days], $now);

        $this->events->record(
            ProductEventName::SubscriptionGranted,
            ['plan' => $subscription->plan, 'provider' => SubscriptionProvider::Promo->value, 'subscriber_type' => 'learner', 'extended' => $live !== null],
            learner: $learner,
        );

        $this->forgetAfterCommit($subscription);

        return $subscription;
    }

    /**
     * Reward (referrals, docs/13 §2.4): `$days` of Pro, provider `reward`, `provider_ref = $source`.
     * Extends a live subscription like a promo does; a perpetual grant is returned untouched
     * (a reward must never fail the caller). Must run inside the caller's transaction; locks
     * the learner row itself.
     *
     * @internal for ReferralService (its own transaction per learner — never two learners in one)
     */
    public function grantReward(Learner $learner, int $days, string $source, CarbonImmutable $now): Subscription
    {
        $days = max(1, $days);

        $this->lockSubscriber($learner);
        $live = $this->lockedLive($learner);

        if ($live !== null && $live->current_period_ends_at === null) {
            return $live;
        }

        $subscription = $this->grantDays($learner, $live, Plan::Pro, $days, SubscriptionProvider::Reward, $source, 'reward', ['source' => $source, 'days' => $days], $now);

        $this->events->record(
            ProductEventName::SubscriptionGranted,
            ['plan' => $subscription->plan, 'provider' => SubscriptionProvider::Reward->value, 'subscriber_type' => 'learner', 'extended' => $live !== null, 'source' => $source, 'days' => $days],
            learner: $learner,
        );

        $this->forgetAfterCommit($subscription);

        return $subscription;
    }

    /**
     * docs/13 §6.5 (2B.8): a paid organization order may only RENEW the tier of a live subscription.
     * The order carries the tier it was created for; when another tier is live by the time the money
     * arrives (a manual grant or another payment got in between — CheckoutService refuses such an
     * order up front, this is the race), the tier and the seats are NOT touched: a tier change applied
     * to the whole prepaid period would be an upgrade or a downgrade of months that were paid at
     * another price. Nothing to protect when the live row has no tier or its period has already run out.
     */
    private function tierMismatch(Subscription $live, string $orderedTier, CarbonImmutable $now): bool
    {
        return $live->tier !== null
            && $live->tier !== $orderedTier
            && SubscriptionLiveness::isLive($live, $now);
    }

    /**
     * The money is taken and the period was extended, but the tier stayed: mark the order
     * (`meta.tier_mismatch`), log and audit so the platform settles it by hand (ADMIN_GUIDE).
     * The order row is locked by PaymentApplier, which saves `meta` again after this call.
     */
    private function flagTierMismatch(Subscription $live, PaymentOrder $order, string $orderedTier, ChargeEvent $e, CarbonImmutable $now): void
    {
        $context = [
            'subscription_id' => $live->id,
            'payment_order_id' => $order->id,
            'organization_id' => $live->subscriber_id,
            'ordered_tier' => $orderedTier,
            'live_tier' => $live->tier,
            'provider' => $e->provider->value,
            'provider_event_id' => $e->providerEventId,
        ];

        $order->fill(['meta' => [...$order->meta, 'tier_mismatch' => true, 'tier_mismatch_live_tier' => $live->tier]])->save();

        Log::warning('billing.org_tier_mismatch', $context);

        AuditLog::create([
            'user_id' => null,
            'action' => self::ACTION_TIER_MISMATCH,
            'entity_type' => Subscription::class,
            'entity_id' => $live->id,
            'ip' => null,
            'meta' => [...$context, 'at' => $now->toIso8601String()],
        ]);
    }

    public static function subscriberType(Learner|Organization $subscriber): string
    {
        return $subscriber instanceof Learner ? 'learner' : 'organization';
    }

    /** Days of a billing period from config('billing.periods'); unknown period = 30 days. */
    public static function periodDays(string $period): int
    {
        return max(1, (int) config("billing.periods.{$period}", 30));
    }

    /**
     * Shared body of grantFromPromo / grantReward: a new active row, or the live row extended
     * from max(its end, now) by $days and re-activated.
     *
     * @param  array<string, mixed>  $historyExtra
     */
    private function grantDays(
        Learner $learner,
        ?Subscription $live,
        Plan $plan,
        int $days,
        SubscriptionProvider $provider,
        string $providerRef,
        string $historyAction,
        array $historyExtra,
        CarbonImmutable $now,
    ): Subscription {
        if ($live === null) {
            return Subscription::query()->create([
                'subscriber_type' => 'learner',
                'subscriber_id' => $learner->id,
                'plan' => $plan->value,
                'status' => SubscriptionStatus::Active->value,
                'provider' => $provider->value,
                'provider_ref' => $providerRef,
                'starts_at' => $now,
                'current_period_ends_at' => $now->addDays($days),
                'meta' => [],
            ]);
        }

        /** @var Carbon $currentEnd */
        $currentEnd = $live->current_period_ends_at;
        $from = CarbonImmutable::instance($currentEnd);
        $from = $from->greaterThan($now) ? $from : $now;

        $meta = $live->meta;
        $meta['history'][] = [
            'at' => $now->toIso8601String(),
            'action' => $historyAction,
            ...$historyExtra,
            'from' => $this->snapshot($live),
        ];

        $live->fill([
            'plan' => $plan->outranks(Plan::fromKey($live->plan)) ? $plan->value : $live->plan,
            'status' => SubscriptionStatus::Active->value,
            'current_period_ends_at' => $from->addDays($days),
            'grace_until' => null,
            'canceled_at' => null,
            'meta' => $meta,
        ])->save();

        return $live;
    }

    /**
     * One due row in its own transaction. Lock order: subject row (a missing
     * subject is not an error — the row is expired all the same) -> subscription
     * (re-checked: it may have been extended or already expired since the
     * candidate list was built) -> `product_events` insert.
     */
    private function expireOne(int $id, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($id, $now): bool {
            $s = $this->lockForTransition($id);
            if ($s === null) {
                return false;
            }

            $status = SubscriptionStatus::tryFrom($s->status);
            if ($status === null || $status === SubscriptionStatus::Expired) {
                return false;
            }

            if ($status === SubscriptionStatus::PastDue) {
                if ($s->grace_until !== null && $s->grace_until->greaterThan($now)) {
                    return false; // re-activated / extended since the candidate list was built
                }

                $this->markExpired($s, $now, 'grace_over');

                return true;
            }

            if ($s->current_period_ends_at === null || $s->current_period_ends_at->greaterThan($now)) {
                return false; // extended since the candidate list was built
            }

            $ends = CarbonImmutable::instance($s->current_period_ends_at);

            if ($status !== SubscriptionStatus::Canceled && SubscriptionLiveness::providerHasGrace($s)) {
                $graceUntil = $ends->addDays(SubscriptionLiveness::graceDays());

                if ($graceUntil->greaterThan($now)) {
                    $s->fill(['status' => SubscriptionStatus::PastDue->value, 'grace_until' => $graceUntil])->save();
                    $this->forgetAfterCommit($s);

                    return true;
                }
            }

            $this->markExpired($s, $now, 'period_ended');

            return true;
        });
    }

    /**
     * One reminder candidate in its own transaction (subject -> subscription): re-checks the
     * row, writes the marker and raises the event after commit.
     *
     * @param  non-empty-list<int>  $days  reminder thresholds, descending
     */
    private function remindOne(int $id, array $days, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($id, $days, $now): bool {
            $s = $this->lockForTransition($id);

            if ($s === null || $s->current_period_ends_at === null || ! SubscriptionLiveness::isLive($s, $now)) {
                return false;
            }

            $ends = CarbonImmutable::instance($s->current_period_ends_at);
            $daysLeft = null;
            foreach (array_reverse($days) as $d) { // smallest threshold first
                if (! $ends->greaterThan($now->addDays($d))) {
                    $daysLeft = $d;
                    break;
                }
            }

            if ($daysLeft === null) {
                return false; // extended since the candidate list was built
            }

            $marker = $daysLeft.'@'.$ends->toIso8601String();
            $meta = $s->meta;
            /** @var list<string> $markers */
            $markers = is_array($meta[self::META_RENEWAL_REMINDERS] ?? null) ? array_values($meta[self::META_RENEWAL_REMINDERS]) : [];

            if (in_array($marker, $markers, true)) {
                return false;
            }

            $markers[] = $marker;
            $meta[self::META_RENEWAL_REMINDERS] = $markers;
            $s->fill(['meta' => $meta])->save();

            $event = new SubscriptionRenewalDue($s->id, $s->subscriber_type, $s->subscriber_id, $s->provider, $daysLeft, $ends);
            DB::afterCommit(static function () use ($event): void {
                event($event);
            });

            return true;
        });
    }

    /** @return list<int> distinct positive thresholds, descending */
    private function reminderDays(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config('billing.checkout.renewal_reminder_days', [3, 1]);
        $days = array_values(array_unique(array_filter(array_map('intval', $configured), static fn (int $d): bool => $d > 0)));
        rsort($days);

        return $days;
    }

    private function markExpired(Subscription $s, CarbonImmutable $now, string $reason): void
    {
        $s->fill([
            'status' => SubscriptionStatus::Expired->value,
            'ended_at' => $now,
            'grace_until' => null,
        ])->save();

        $this->recordExpired($s, $reason, null);
        $this->forgetAfterCommit($s);
    }

    private function recordExpired(Subscription $s, string $reason, ?User $by): void
    {
        $this->events->record(
            ProductEventName::SubscriptionExpired,
            ['plan' => $s->plan, 'provider' => $s->provider, 'subscriber_type' => $s->subscriber_type, 'reason' => $reason],
            learner: $this->learnerOf($s),
            user: $by,
        );
    }

    /** @param array<string, mixed> $extra */
    private function audit(User $by, string $action, Subscription $s, ?string $ip, array $extra): void
    {
        AuditLog::create([
            'user_id' => $by->id,
            'action' => $action,
            'entity_type' => Subscription::class,
            'entity_id' => $s->id,
            'ip' => $ip,
            'meta' => [
                'subscriber_type' => $s->subscriber_type,
                'subscriber_id' => $s->subscriber_id,
                'plan' => $s->plan,
                ...$extra,
            ],
        ]);
    }

    /** @return array{plan: string, status: string, provider: string, until: string|null} */
    private function snapshot(Subscription $s): array
    {
        return ['plan' => $s->plan, 'status' => $s->status, 'provider' => $s->provider, 'until' => $s->current_period_ends_at?->toIso8601String()];
    }

    private function learnerOf(Subscription $s): ?Learner
    {
        return $s->subscriber_type === 'learner' ? Learner::withTrashed()->find($s->subscriber_id) : null;
    }

    /**
     * Row lock on the subscriber: serializes every write to its subscriptions.
     *
     * @throws SubscriptionException subscriber_not_found
     */
    private function lockSubscriber(Learner|Organization $subscriber): void
    {
        if (! $this->lockSubjectRow(self::subscriberType($subscriber), $subscriber->id)) {
            throw SubscriptionException::subscriberNotFound(self::subscriberType($subscriber), $subscriber->id);
        }
    }

    /**
     * `SELECT ... FOR UPDATE` on the learners / organizations row (soft-deleted
     * learners included — their subscriptions still exist). False when the row
     * is gone; the caller decides whether that is an error.
     */
    private function lockSubjectRow(string $type, int $id): bool
    {
        $query = $type === 'organization'
            ? Organization::query()->whereKey($id)
            : Learner::withTrashed()->whereKey($id);

        return $query->lockForUpdate()->value('id') !== null;
    }

    /**
     * Subject row -> subscription row, both FOR UPDATE, for a transition of an existing
     * subscription. `(subscriber_type, subscriber_id)` is read without a lock first: both
     * columns are immutable after insert. A hard-deleted subject is not an error (there is
     * no row to lock and no FK insert can wait on it). Null when the subscription is gone.
     */
    private function lockForTransition(int $subscriptionId): ?Subscription
    {
        /** @var Subscription|null $row */
        $row = Subscription::query()->whereKey($subscriptionId)->first(['id', 'subscriber_type', 'subscriber_id']);

        if ($row === null) {
            return null;
        }

        $this->lockSubjectRow($row->subscriber_type, $row->subscriber_id);

        /** @var Subscription|null $locked */
        $locked = Subscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();

        return $locked;
    }

    private function lockedLive(Learner|Organization $subscriber): ?Subscription
    {
        /** @var Subscription|null $live */
        $live = $this->liveQuery($subscriber)->lockForUpdate()->first();

        return $live;
    }

    /** @return Builder<Subscription> */
    private function liveQuery(Learner|Organization $subscriber)
    {
        return Subscription::query()
            ->where('subscriber_type', self::subscriberType($subscriber))
            ->where('subscriber_id', $subscriber->id)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->orderBy('id');
    }

    /**
     * Cache invalidation after commit. For an organization the waitlist is also re-seated
     * (docs/13 §2.3, §3 rule 3): a new / upgraded subscription may have freed seats. That runs
     * in its own transaction (`organizations` lock again, never inside ours) and is idempotent —
     * with no capacity it is a no-op — so it is safe after a revoke / expiry too. Its failure
     * is logged, not thrown: the subscription change is already committed.
     */
    private function forgetAfterCommit(Subscription $subscription): void
    {
        $type = $subscription->subscriber_type;
        $id = $subscription->subscriber_id;

        DB::afterCommit(function () use ($type, $id): void {
            if ($type === 'organization') {
                $this->entitlements->forgetOrganization($id);

                try {
                    $this->memberships->reseat($id);
                } catch (\Throwable $e) {
                    Log::error('organizations.reseat_failed', ['organization_id' => $id, 'exception' => $e::class, 'error' => $e->getMessage()]);
                }
            } else {
                $this->entitlements->forget($id);
            }
        });
    }
}
