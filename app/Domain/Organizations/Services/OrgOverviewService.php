<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Organizations\DTO\OrgOverview;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Dashboard of the `/org` cabinet and the bot's `/org` summary (docs/13 §6.3): seats, groups,
 * members and the activity of the last 7 days from `learner_daily_stats` — aggregates only, never
 * a learner's sessions (docs/12 §4.4). Everything is read through {@see OrgTenantScope}, so a
 * teacher's dashboard counts only the learners of their groups.
 */
final class OrgOverviewService
{
    public const ACTIVITY_DAYS = 7;

    public function __construct(
        private readonly OrgTenantScope $scope,
        private readonly OrganizationMembershipService $memberships,
    ) {}

    public function summary(User $user, Organization $organization, ?CarbonImmutable $now = null): OrgOverview
    {
        $now ??= CarbonImmutable::now();
        $learnerIds = $this->scope->learners($user, $organization)->select('learners.id');

        // Days are the learner's local dates; the organization's timezone is close enough for a window.
        $since = $now->setTimezone($organization->timezone)->subDays(self::ACTIVITY_DAYS - 1)->toDateString();

        $activity = LearnerDailyStats::query()
            ->whereIn('learner_id', $learnerIds)
            ->where('date', '>=', $since)
            ->selectRaw('COUNT(DISTINCT CASE WHEN reviews_count > 0 OR new_words_count > 0 THEN learner_id END) AS active_learners')
            ->selectRaw('COALESCE(SUM(reviews_count), 0) AS reviews')
            ->selectRaw('COALESCE(SUM(new_words_count), 0) AS new_words')
            ->first();

        $subscription = $this->scope->isOwner($user, $organization) ? $this->subscription($organization, $now) : null;

        return new OrgOverview(
            seats: $this->memberships->seatsSummary($organization, $now),
            groups: $this->scope->groups($user, $organization)->count(),
            activeMembers: $this->scope->members($user, $organization)->where('organization_members.status', MemberStatus::Active->value)->count(),
            activeLearners7d: (int) ($activity?->getAttribute('active_learners') ?? 0),
            reviews7d: (int) ($activity?->getAttribute('reviews') ?? 0),
            newWords7d: (int) ($activity?->getAttribute('new_words') ?? 0),
            subscriptionTier: $subscription?->tier,
            subscriptionStatus: $subscription?->status,
            subscriptionEndsAt: $subscription === null ? null : SubscriptionLiveness::liveUntil($subscription),
        );
    }

    /** The organization's live subscription (same rule as the seats: docs/13 §6.1). */
    public function subscription(Organization $organization, ?CarbonImmutable $now = null): ?Subscription
    {
        $now ??= CarbonImmutable::now();

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('subscriber_type', 'organization')
            ->where('subscriber_id', $organization->id)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->orderBy('id')
            ->first();

        return $subscription !== null && SubscriptionLiveness::isLive($subscription, $now) ? $subscription : null;
    }
}
