<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide aggregates for the admin dashboard (docs/12 §9, task 1.8). Read-only and NOT scoped
 * by {@see PanelDataScope}: the callers (admin-only widgets) are the access check. Every figure is a
 * single aggregate query and is cached briefly, so opening the dashboard does not rescan
 * `product_events` / `subscriptions` on every Livewire poll.
 */
final class PlatformStatsService
{
    public const CACHE_TTL = 120;

    private const CACHE_PREFIX = 'platform_stats:';

    public const EXPIRING_DAYS = 7;

    public const FUNNEL_DAYS = 7;

    /**
     * Paid-plan subscriptions that grant right now.
     *
     * - `pro_learners`: learners whose EFFECTIVE plan is paid (own or sponsored by an organization);
     * - `personal`: granting subscriptions of learners;
     * - `organizations`: granting subscriptions of ACTIVE organizations (sponsor Pro);
     * - `expiring`: granting subscriptions (learners + organizations) that end within 7 days.
     *
     * @return array{pro_learners:int, personal:int, organizations:int, expiring:int}
     */
    public function subscriptions(): array
    {
        /** @var array{pro_learners:int, personal:int, organizations:int, expiring:int} */
        return Cache::remember(self::CACHE_PREFIX.'subscriptions', self::CACHE_TTL, function (): array {
            $now = CarbonImmutable::now();
            // Only an ACTIVE organization sponsors (a suspended one keeps its row but grants nothing).
            $learnerSubscriptions = fn (): Builder => Subscription::query()->where('subscriptions.subscriber_type', 'learner');
            $organizationSubscriptions = fn (): Builder => Subscription::query()
                ->where('subscriptions.subscriber_type', 'organization')
                ->whereIn('subscriptions.subscriber_id', Organization::query()->where('status', 'active')->select('organizations.id'));

            return [
                'pro_learners' => Learner::query()
                    ->where(fn ($q) => $q
                        ->whereExists(PaidSubscriptionQuery::personalFor($now)->select('subscriptions.id'))
                        ->orWhereIn('learners.id', PaidSubscriptionQuery::sponsoredLearnerIds($now)))
                    ->count(),
                'personal' => PaidSubscriptionQuery::granting($learnerSubscriptions(), $now)->count(),
                'organizations' => PaidSubscriptionQuery::granting($organizationSubscriptions(), $now)->count(),
                'expiring' => PaidSubscriptionQuery::expiringWithin($learnerSubscriptions(), $now, self::EXPIRING_DAYS)->count()
                    + PaidSubscriptionQuery::expiringWithin($organizationSubscriptions(), $now, self::EXPIRING_DAYS)->count(),
            ];
        });
    }

    /**
     * Onboarding funnel of the last 7 days, a cohort: learners who STARTED in the window and how many
     * of them have COMPLETED since (`onboarding.started` -> `onboarding.completed`, docs/12 §7.7).
     * Counts distinct learners, so a retried event never inflates it. `conversion` is a percentage
     * with one decimal, null while nobody started.
     *
     * @return array{started:int, completed:int, conversion:float|null}
     */
    public function onboardingFunnel(): array
    {
        /** @var array{started:int, completed:int, conversion:float|null} */
        return Cache::remember(self::CACHE_PREFIX.'funnel', self::CACHE_TTL, function (): array {
            $since = CarbonImmutable::now()->subDays(self::FUNNEL_DAYS);
            $started = ProductEvent::query()
                ->where('name', ProductEventName::OnboardingStarted->value)
                ->where('occurred_at', '>=', $since)
                ->whereNotNull('learner_id');

            $startedCount = (clone $started)->distinct()->count('learner_id');
            $completedCount = (clone $started)
                ->whereExists(DB::table('product_events as done')
                    ->select('done.id')
                    ->where('done.name', ProductEventName::OnboardingCompleted->value)
                    // A completion counts only after its start, so it is inside the window too: bounds the scan.
                    ->where('done.occurred_at', '>=', $since)
                    ->whereColumn('done.learner_id', 'product_events.learner_id')
                    ->whereColumn('done.occurred_at', '>=', 'product_events.occurred_at'))
                ->distinct()
                ->count('learner_id');

            return [
                'started' => $startedCount,
                'completed' => $completedCount,
                'conversion' => $startedCount === 0 ? null : round($completedCount / $startedCount * 100, 1),
            ];
        });
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_PREFIX.'subscriptions');
        Cache::forget(self::CACHE_PREFIX.'funnel');
    }
}
