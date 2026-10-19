<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PromoCodeException;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of `subscriptions` (docs/12 §3.4, §5.5, §5.6, state machine §6.2).
 *
 * Invariant: at most one live (trialing / active / past_due) subscription per
 * subscriber. It is enforced twice: by the partial unique index
 * `subscriptions_one_live_per_subscriber` (PG / sqlite) and, for every driver,
 * by taking a row lock on the subscriber (learners / organizations) before
 * reading its live subscription — grant, promo and revoke of the same subject
 * are serialized, so a "live" check cannot be stale when the row is written.
 *
 * LOCK ORDER (docs/12 §3.4) — every write path takes its row locks in this order
 * and never in another:
 *
 *   [promo_codes] -> subject row (learners / organizations) -> subscriptions -> FK inserts
 *
 * The last step matters: inserting into `product_events` / `promo_redemptions` /
 * `audit_logs` takes `FOR KEY SHARE` on the referenced learner / promo / user,
 * which conflicts with another transaction's `FOR UPDATE` on that row. That is
 * why `revoke()` and `expireOne()` lock the subject row *before* the subscription
 * even though they do not need it for the invariant: locking the subscription
 * first and the learner last (implicitly, through the FK) deadlocked against
 * `grantManual` / `grantFromPromo`, which hold the learner and wait for the
 * subscription. The subject is read without a lock first (`subscriber_*` never
 * change after insert), locked, and only then the subscription row is locked and
 * re-checked.
 *
 * Every transition writes an audit record (when a human did it), a product
 * event and schedules the entitlement cache invalidation after commit.
 */
final class SubscriptionService
{
    public const ACTION_GRANTED = 'subscription.granted';

    public const ACTION_REVOKED = 'subscription.revoked';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Manual grant by a superadmin (Filament "Grant Pro"). If the subscriber
     * already has a live subscription it is extended / replaced in place (same
     * row id, `current_period_ends_at = $until`, provider becomes `manual`), so
     * the one-live-row invariant and `promo_redemptions.subscription_id` hold.
     *
     * @param  CarbonImmutable|null  $until  end of the grant; null = perpetual
     * @param  string  $note  reason (stored on the row and in the audit record)
     *
     * @throws SubscriptionException plan_not_grantable | until_in_past | subscriber_not_found
     */
    public function grantManual(
        Learner|Organization $subscriber,
        Plan $plan,
        ?CarbonImmutable $until,
        User $by,
        string $note,
        ?string $ip = null,
        ?CarbonImmutable $now = null,
    ): Subscription {
        $now ??= CarbonImmutable::now();

        if ($plan->rank() <= Plan::Free->rank()) {
            throw SubscriptionException::planNotGrantable($plan->value);
        }

        if ($until !== null && $until->lessThanOrEqualTo($now)) {
            throw SubscriptionException::untilInPast();
        }

        return DB::transaction(function () use ($subscriber, $plan, $until, $by, $note, $ip, $now): Subscription {
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
                    'from' => ['plan' => $live->plan, 'status' => $live->status, 'provider' => $live->provider, 'until' => $live->current_period_ends_at?->toIso8601String()],
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
            $subject = $this->subjectOf($subscription->id);

            if ($subject === null) {
                throw SubscriptionException::notLive($subscription->id);
            }

            // A hard-deleted subject is not an error here: there is no row to
            // lock and no FK insert can wait on it either.
            $this->lockSubjectRow(...$subject);

            /** @var Subscription|null $locked */
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();

            if ($locked === null || ! SubscriptionStatus::from($locked->status)->isLive()) {
                throw SubscriptionException::notLive($subscription->id);
            }

            $meta = $locked->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'revoked',
                'from' => ['status' => $locked->status, 'until' => $locked->current_period_ends_at?->toIso8601String()],
                'by_user_id' => $by->id,
                'note' => $note,
            ];

            $locked->fill([
                'status' => SubscriptionStatus::Expired->value,
                'ended_at' => $now,
                'grace_until' => null,
                'meta' => $meta,
            ])->save();

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::ACTION_REVOKED,
                'entity_type' => Subscription::class,
                'entity_id' => $locked->id,
                'ip' => $ip,
                'meta' => [
                    'subscriber_type' => $locked->subscriber_type,
                    'subscriber_id' => $locked->subscriber_id,
                    'plan' => $locked->plan,
                    'note' => $note,
                ],
            ]);

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
     *   trialing / active / canceled with `current_period_ends_at <= now`
     *     -> past_due with `grace_until = ends + grace_days` (auto-renewing providers)
     *     -> expired                                        (manual / promo, or grace already over)
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
     * in grace; a perpetual one cannot be extended (promo_not_applicable).
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

        if ($live === null) {
            $subscription = Subscription::query()->create([
                'subscriber_type' => 'learner',
                'subscriber_id' => $learner->id,
                'plan' => $plan->value,
                'status' => SubscriptionStatus::Active->value,
                'provider' => SubscriptionProvider::Promo->value,
                'provider_ref' => (string) $promo->id,
                'starts_at' => $now,
                'current_period_ends_at' => $now->addDays($days),
                'meta' => [],
            ]);
        } else {
            if ($live->current_period_ends_at === null) {
                throw PromoCodeException::notApplicable();
            }

            $from = CarbonImmutable::instance($live->current_period_ends_at);
            $from = $from->greaterThan($now) ? $from : $now;

            $meta = $live->meta;
            $meta['history'][] = [
                'at' => $now->toIso8601String(),
                'action' => 'promo_extension',
                'promo_code_id' => $promo->id,
                'days' => $days,
                'from' => ['plan' => $live->plan, 'status' => $live->status, 'until' => $live->current_period_ends_at->toIso8601String()],
            ];

            $live->fill([
                'plan' => $plan->outranks(Plan::from($live->plan)) ? $plan->value : $live->plan,
                'status' => SubscriptionStatus::Active->value,
                'current_period_ends_at' => $from->addDays($days),
                'grace_until' => null,
                'canceled_at' => null,
                'meta' => $meta,
            ])->save();

            $subscription = $live;
        }

        $this->events->record(
            ProductEventName::SubscriptionGranted,
            ['plan' => $subscription->plan, 'provider' => SubscriptionProvider::Promo->value, 'subscriber_type' => 'learner', 'extended' => $live !== null],
            learner: $learner,
        );

        $this->forgetAfterCommit($subscription);

        return $subscription;
    }

    public static function subscriberType(Learner|Organization $subscriber): string
    {
        return $subscriber instanceof Learner ? 'learner' : 'organization';
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
            $subject = $this->subjectOf($id);
            if ($subject === null) {
                return false;
            }

            $this->lockSubjectRow(...$subject);

            /** @var Subscription|null $s */
            $s = Subscription::query()->whereKey($id)->lockForUpdate()->first();
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
            learner: $s->subscriber_type === 'learner' ? Learner::withTrashed()->find($s->subscriber_id) : null,
            user: $by,
        );
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
     * `(subscriber_type, subscriber_id)` of a subscription, read without a lock:
     * both columns are immutable after insert, so this is safe to read before
     * the subject row is locked.
     *
     * @return array{string, int}|null
     */
    private function subjectOf(int $subscriptionId): ?array
    {
        /** @var Subscription|null $row */
        $row = Subscription::query()->whereKey($subscriptionId)->first(['id', 'subscriber_type', 'subscriber_id']);

        return $row === null ? null : [$row->subscriber_type, $row->subscriber_id];
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

    private function forgetAfterCommit(Subscription $subscription): void
    {
        $type = $subscription->subscriber_type;
        $id = $subscription->subscriber_id;

        DB::afterCommit(function () use ($type, $id): void {
            if ($type === 'organization') {
                $this->entitlements->forgetOrganization($id);
            } else {
                $this->entitlements->forget($id);
            }
        });
    }
}
