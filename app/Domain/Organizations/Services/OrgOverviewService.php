<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\DTO\OrgOverview;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard of the `/org` cabinet and the bot's `/org` summary (docs/13 §6.3): seats, groups,
 * members and the activity of the last 7 days from `learner_daily_stats` — aggregates only, never
 * a learner's sessions (docs/12 §4.4). Everything is read through {@see OrgTenantScope}, so a
 * teacher's dashboard counts only the learners of their groups.
 *
 * {@see cachedSummary()} is what the two dashboard widgets read (docs/19 K2): the same figures from the pure cache, per
 * organization and per kind of viewer, for {@see CACHE_TTL} seconds and dropped at once by {@see forget()} when seats,
 * members, groups or the subscription of the center change.
 *
 * @phpstan-import-type OrgOverviewArray from OrgOverview
 */
final class OrgOverviewService
{
    public const ACTIVITY_DAYS = 7;

    /** How long a summary may lag behind `learner_daily_stats` (the one input no event announces). */
    public const CACHE_TTL = 90;

    /** Format of the cached array; raise it when {@see OrgOverview::toArray()} changes shape. */
    private const CACHE_FORMAT = 'v1';

    public function __construct(
        private readonly OrgTenantScope $scope,
        private readonly OrganizationMembershipService $memberships,
    ) {}

    /**
     * The summary for the dashboard widgets: {@see summary()} through the pure cache. Key
     * `org:{id}:overview:v1:n{data version}:{viewer}`: the organization's id is in it, so one center never reads another's
     * numbers; `viewer` is `owner` (every owner sees the same) or a teacher's id with a hash of their groups (their numbers
     * are their groups' only). Someone who is not staff of the center is not cached at all (the empty answer is cheap).
     */
    public function cachedSummary(User $user, Organization $organization): OrgOverview
    {
        $viewer = $this->viewerKey($user, $organization);

        if ($viewer === null) {
            return $this->summary($user, $organization);
        }

        // The data version is read BEFORE the figures are computed: an event that lands meanwhile bumps it, and our write
        // then goes to a key nobody reads any more (the delete-then-put race of a plain forget(), as in EntitlementResolver).
        $key = self::cacheKey($organization->id, $viewer);

        /** @var OrgOverviewArray $data */
        $data = PureCache::store()->remember($key, self::CACHE_TTL, fn (): array => $this->summary($user, $organization)->toArray());

        return OrgOverview::fromArray($data);
    }

    /**
     * Drops every cached summary of the organization at once (all viewers) by moving its data version on. The version key has
     * no TTL and lives on the non-evicting default store (docs/19 §4): were it evicted, the version would fall back to a number
     * an old entry may still carry. Called after commit by the membership listener, the group observer and the subscription
     * service.
     */
    public static function forget(int $organizationId): void
    {
        Cache::increment(self::versionKey($organizationId));
    }

    public static function versionKey(int $organizationId): string
    {
        return "org:{$organizationId}:overview:ver";
    }

    public static function cacheKey(int $organizationId, string $viewer): string
    {
        $version = (int) Cache::get(self::versionKey($organizationId), 0);

        return "org:{$organizationId}:overview:".self::CACHE_FORMAT.":n{$version}:{$viewer}";
    }

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

    /** Which kind of viewer the figures are for; null = not staff of the center (nothing to cache). */
    private function viewerKey(User $user, Organization $organization): ?string
    {
        return match ($this->scope->role($user, $organization)) {
            OrganizationRole::Owner => 'owner',
            OrganizationRole::Teacher => 'u'.$user->id.'g'.substr(hash('sha256', implode(',', $this->sortedGroupIds($user, $organization))), 0, 12),
            null => null,
        };
    }

    /** @return list<int> */
    private function sortedGroupIds(User $user, Organization $organization): array
    {
        $ids = $this->scope->teacherGroupIds($user, $organization);
        sort($ids);

        return $ids;
    }
}
