<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * SQL twin of {@see SubscriptionLiveness} for admin lists and aggregates: "which rows grant a PAID
 * plan at instant $now" without resolving learners one by one in PHP (the per-learner answer the
 * product enforces stays {@see EntitlementResolver}).
 * Same rule: trialing / active live until the period end (plus the grace days for an auto-renewing
 * provider; NULL end = perpetual), canceled until the period end without grace, past_due until `grace_until`.
 */
final class PaidSubscriptionQuery
{
    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function granting(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->whereIn('subscriptions.plan', self::paidPlans())
            ->where(fn (Builder $live): Builder => self::aliveAt($live, $now));
    }

    /**
     * Granting now, but no longer at now + $days: the paid plan ends inside the window.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function expiringWithin(Builder $query, CarbonImmutable $now, int $days): Builder
    {
        $deadline = $now->addDays($days);

        return self::granting($query, $now)
            ->whereNot(fn (Builder $live): Builder => self::aliveAt($live, $deadline));
    }

    /**
     * The learner's own granting subscription, correlated with `learners.id`: for the rows of one
     * page (addSelect). For filtering a whole table use {@see self::personalLearnerIds()}.
     *
     * @return Builder<Subscription>
     */
    public static function personalFor(CarbonImmutable $now): Builder
    {
        return self::granting(
            Subscription::query()
                ->where('subscriptions.subscriber_type', 'learner')
                ->whereColumn('subscriptions.subscriber_id', 'learners.id'),
            $now,
        );
    }

    /**
     * Ids of every learner with an own granting subscription right now. Uncorrelated, like
     * {@see self::sponsoredLearnerIds()}: the subscriptions table is read once and hashed, instead of
     * running {@see self::personalFor()} per learner when a whole `learners` table is filtered.
     *
     * @return Builder<Subscription>
     */
    public static function personalLearnerIds(CarbonImmutable $now): Builder
    {
        return self::granting(
            Subscription::query()->where('subscriptions.subscriber_type', 'learner'),
            $now,
        )->select('subscriptions.subscriber_id');
    }

    /**
     * A granting subscription of an active organization where the learner holds a seat
     * (`organization_members`: active, seated — the chain EntitlementResolver uses since 2B.1,
     * docs/13 §2.3), correlated with `learners.id`: for the rows of one page. For filtering a
     * whole table use {@see self::sponsoredLearnerIds()}.
     *
     * @return Builder<Subscription>
     */
    public static function sponsorFor(CarbonImmutable $now): Builder
    {
        return self::granting(
            Subscription::query()
                ->where('subscriptions.subscriber_type', 'organization')
                ->whereIn('subscriptions.subscriber_id', DB::table('organization_members as om')
                    ->join('organizations as o', 'o.id', '=', 'om.organization_id')
                    ->whereColumn('om.learner_id', 'learners.id')
                    ->where('om.status', 'active')
                    ->where('om.seat_state', 'seated')
                    ->where('o.status', 'active')
                    ->select('om.organization_id')),
            $now,
        );
    }

    /**
     * Ids of every learner sponsored right now: seated in an active organization that has a
     * granting subscription. Uncorrelated (the few sponsoring organizations are resolved once and
     * hashed), so it suits "who is Pro" over the whole table, where the correlated
     * {@see self::sponsorFor()} would run its subquery per learner.
     */
    public static function sponsoredLearnerIds(CarbonImmutable $now): QueryBuilder
    {
        return DB::table('organization_members as om')
            ->join('organizations as o', 'o.id', '=', 'om.organization_id')
            ->where('om.status', 'active')
            ->where('om.seat_state', 'seated')
            ->where('o.status', 'active')
            ->whereIn('o.id', self::granting(
                Subscription::query()->where('subscriptions.subscriber_type', 'organization'),
                $now,
            )->select('subscriptions.subscriber_id'))
            ->select('om.learner_id');
    }

    /** @return list<string> plan keys ranked above Free */
    public static function paidPlans(): array
    {
        $free = Plan::Free->rank();

        return array_values(array_map(
            static fn (Plan $plan): string => $plan->value,
            array_filter(Plan::cases(), static fn (Plan $plan): bool => $plan->rank() > $free),
        ));
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private static function aliveAt(Builder $query, CarbonImmutable $at): Builder
    {
        $withGrace = array_values(array_map(
            static fn (SubscriptionProvider $p): string => $p->value,
            array_filter(SubscriptionProvider::cases(), static fn (SubscriptionProvider $p): bool => $p->hasGrace()),
        ));
        $graceEdge = $at->subDays(SubscriptionLiveness::graceDays());

        // Every live status except past_due is judged by the period end; past_due by `grace_until`
        // (the same split as SubscriptionLiveness). The lists come from the enum. `canceled` is
        // judged by the bare period end whatever the provider: the renewal was stopped, there is
        // no grace to wait for (docs/13 §2.1).
        $byPeriod = array_values(array_diff(SubscriptionStatus::liveValues(), [SubscriptionStatus::PastDue->value]));
        $canceled = SubscriptionStatus::Canceled->value;

        return $query
            ->where(function (Builder $ongoing) use ($at, $byPeriod, $withGrace, $graceEdge, $canceled): void {
                $ongoing
                    ->whereIn('subscriptions.status', $byPeriod)
                    ->where(function (Builder $end) use ($at, $withGrace, $graceEdge, $canceled): void {
                        $end->whereNull('subscriptions.current_period_ends_at')
                            ->orWhere(fn (Builder $fixed): Builder => $fixed
                                ->where(fn (Builder $noGrace): Builder => $noGrace
                                    ->where('subscriptions.status', $canceled)
                                    ->orWhereNotIn('subscriptions.provider', $withGrace)
                                    // A one-off Stars year does not renew either (AutoRenewal::renews).
                                    ->orWhere('subscriptions.meta->stars->recurring', false))
                                ->where('subscriptions.current_period_ends_at', '>', $at))
                            ->orWhere(fn (Builder $renewing): Builder => $renewing
                                ->where('subscriptions.status', '<>', $canceled)
                                ->whereIn('subscriptions.provider', $withGrace)
                                ->where(fn (Builder $flag): Builder => $flag
                                    ->whereNull('subscriptions.meta->stars->recurring')
                                    ->orWhere('subscriptions.meta->stars->recurring', '<>', false))
                                ->where('subscriptions.current_period_ends_at', '>', $graceEdge));
                    });
            })
            ->orWhere(fn (Builder $pastDue): Builder => $pastDue
                ->where('subscriptions.status', SubscriptionStatus::PastDue->value)
                ->whereNotNull('subscriptions.grace_until')
                ->where('subscriptions.grace_until', '>', $at));
    }
}
