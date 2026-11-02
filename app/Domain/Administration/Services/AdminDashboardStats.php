<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Billing\Services\BillingReportService;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Engagement\Services\LeagueService;
use App\Models\LeagueGroup;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the dashboard widgets of `/admin` (docs/19 K1, docs/20 F5 / A12), every one an array of scalars read
 * through {@see WidgetCache}: the widgets render it and run no query of their own, so a second open of the dashboard
 * costs no SQL until the entry goes stale (60-300 s, recomputed after the response) or is dropped by an event.
 *
 * Whose figures they are is the viewer's scope ({@see PanelDataScope}): the widgets a teacher also sees are keyed
 * by `PanelDataScope::fingerprint()`, the admin-only ones by `all`. The queries themselves are the ones the widgets
 * used to run; only the place moved.
 *
 * Table widgets get the page the viewer asked for (`$page`, `$perPage`): each page is an entry of its own.
 */
final class AdminDashboardStats
{
    public function __construct(
        private readonly WidgetCache $cache,
        private readonly PanelDataScope $scope,
        private readonly BillingReportService $billing,
        private readonly LeagueService $leagues,
    ) {}

    /** @return array{total: int, recent: int} learners in the viewer's scope; `recent` = seen in the last 7 days */
    public function totalLearners(): array
    {
        /** @var array{total: int, recent: int} */
        return $this->scoped('total_learners', 120, function (): array {
            $learners = $this->scope->learners();

            return [
                'total' => (clone $learners)->count(),
                'recent' => (clone $learners)->where('last_seen_at', '>=', now()->subDays(7))->count(),
            ];
        });
    }

    /** @return array{total: int, closed: int, open: int} exams started in the last 30 days (`open` = open right now) */
    public function exams30d(): array
    {
        /** @var array{total: int, closed: int, open: int} */
        return $this->scoped('exams_30d', 120, function (): array {
            $since = now()->subDays(30);
            $exams = $this->scope->exams();

            return [
                'total' => (clone $exams)->where('started_at', '>=', $since)->count(),
                'closed' => (clone $exams)->where('started_at', '>=', $since)->where('status', 'closed')->count(),
                'open' => (clone $exams)->where('status', 'open')->count(),
            ];
        });
    }

    /**
     * Training reviews of the last 30 days, one number per day from `Carbon::today()->subDays(29)` to today. The day is
     * in the key, so after midnight the window is a new entry and never the shifted old one.
     *
     * @return list<int>
     */
    public function activity30d(): array
    {
        $today = Carbon::today();

        /** @var list<int> */
        return $this->scoped('activity_chart', 300, function () use ($today): array {
            $since = $today->copy()->subDays(29);

            /** @var array<string, int> $counts */
            $counts = $this->scope->trainingReviews()
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
                ->groupBy('d')
                ->pluck('c', 'd')
                ->map(fn ($v): int => (int) $v)
                ->all();

            $values = [];
            for ($i = 0; $i < 30; $i++) {
                $values[] = $counts[$since->copy()->addDays($i)->toDateString()] ?? 0;
            }

            return $values;
        }, $today->toDateString());
    }

    /**
     * The ten learners with the most reviews in the last 30 days, with the groups of the viewer they study in. Reviews are
     * counted only inside sessions the viewer may see, so a teacher's ranking never includes a learner's personal practice
     * or other groups' work.
     *
     * @return list<array{id: int, name: string, groups: string, reviews_count: int}>
     */
    public function topLearners(): array
    {
        /** @var list<array{id: int, name: string, groups: string, reviews_count: int}> */
        return $this->scoped('top_learners', 300, function (): array {
            $since = now()->subDays(30);
            $sessionIds = $this->scope->trainingSessionIds();

            $reviewsInScope = fn (Builder $q): Builder => $q
                ->where('created_at', '>=', $since)
                ->whereIn('training_session_id', clone $sessionIds);

            return $this->scope->learners()
                ->with(['groups' => fn ($groups) => $groups
                    ->wherePivot('status', 'active')
                    ->whereIn('telegram_groups.id', $this->scope->groups()->select('telegram_groups.id'))])
                ->withCount(['trainingReviews as reviews_count' => $reviewsInScope])
                ->whereHas('trainingReviews', $reviewsInScope)
                ->orderByDesc('reviews_count')
                ->limit(10)
                ->get()
                ->map(fn (Learner $learner): array => [
                    'id' => (int) $learner->id,
                    'name' => trim(($learner->first_name ?? '—').' '.($learner->last_name ?? '')),
                    'groups' => $learner->groups->pluck('title')->implode(', '),
                    'reviews_count' => (int) $learner->getAttribute('reviews_count'),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * The ten words learners miss most (a grade below 3) over the last 30 days, with at least five attempts in the
     * viewer's sessions. The heaviest query of the dashboard (docs/20 §4), hence the longest freshness.
     *
     * @return list<array{id: int, word: string, translation: string, attempts: int, hard_ratio: float|null}>
     */
    public function hardestWords(): array
    {
        /** @var list<array{id: int, word: string, translation: string, attempts: int, hard_ratio: float|null}> */
        return $this->scoped('hardest_words', 300, function (): array {
            $since = now()->subDays(30);
            $sessionIds = $this->scope->trainingSessionIds();

            return Word::query()
                ->select('words.id', 'words.word', 'words.translation')
                ->selectSub(
                    fn (QueryBuilder $q) => $q->from('training_reviews')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('training_reviews.word_id', 'words.id')
                        ->whereIn('training_reviews.training_session_id', clone $sessionIds)
                        ->where('training_reviews.created_at', '>=', $since),
                    'attempts',
                )
                ->selectSub(
                    fn (QueryBuilder $q) => $q->from('training_reviews')
                        ->selectRaw('CAST(SUM(CASE WHEN quality < ? THEN 1 ELSE 0 END) AS FLOAT) / NULLIF(COUNT(*), 0)', [3])
                        ->whereColumn('training_reviews.word_id', 'words.id')
                        ->whereIn('training_reviews.training_session_id', clone $sessionIds)
                        ->where('training_reviews.created_at', '>=', $since),
                    'hard_ratio',
                )
                ->whereHas(
                    'trainingReviews',
                    fn (Builder $q): Builder => $q
                        ->whereIn('training_session_id', clone $sessionIds)
                        ->where('created_at', '>=', $since),
                    '>=',
                    5,
                )
                ->orderByDesc('hard_ratio')
                ->limit(10)
                ->get()
                ->map(fn (Word $word): array => [
                    'id' => (int) $word->id,
                    'word' => (string) $word->word,
                    'translation' => (string) $word->translation,
                    'attempts' => (int) $word->getAttribute('attempts'),
                    'hard_ratio' => $word->getAttribute('hard_ratio') === null ? null : (float) $word->getAttribute('hard_ratio'),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * The league week the heading of the league widget speaks about: whether leagues are on, the current week (null until
     * the first placement of the week) with its dates, status and the totals of its groups.
     *
     * @return array{enabled: bool, week: array{id: int, from: string, to: string, status: string, groups: int, members: int}|null}
     */
    public function leagueWeek(): array
    {
        /** @var array{enabled: bool, week: array{id: int, from: string, to: string, status: string, groups: int, members: int}|null} */
        return $this->cache->remember('league_week', 'all', 60, function (): array {
            $week = $this->leagues->findWeek(CarbonImmutable::now());

            return [
                'enabled' => $this->leagues->enabled(),
                'week' => $week === null ? null : [
                    'id' => (int) $week->id,
                    'from' => $week->starts_on->toDateString(),
                    'to' => $week->ends_on->toDateString(),
                    'status' => (string) $week->status,
                    'groups' => LeagueGroup::query()->where('league_week_id', $week->id)->count(),
                    'members' => (int) LeagueGroup::query()->where('league_week_id', $week->id)->sum('members_count'),
                ],
            ];
        });
    }

    /**
     * One page of the groups of a league week, highest tier first.
     *
     * @return array{total: int, rows: list<array{id: int, tier: int, members_count: int, capacity: int, created_at: string|null}>}
     */
    public function leagueGroups(int $weekId, int $page, int $perPage): array
    {
        [$page, $perPage] = [max(1, $page), max(1, $perPage)];

        /** @var array{total: int, rows: list<array{id: int, tier: int, members_count: int, capacity: int, created_at: string|null}>} */
        return $this->cache->remember("league_groups:w{$weekId}:p{$page}:n{$perPage}", 'all', 60, function () use ($weekId, $page, $perPage): array {
            $query = LeagueGroup::query()->where('league_week_id', $weekId);

            return [
                'total' => (clone $query)->count(),
                'rows' => $query
                    ->orderByDesc('tier')
                    ->orderBy('id')
                    ->forPage($page, $perPage)
                    ->get()
                    ->map(fn (LeagueGroup $group): array => [
                        'id' => (int) $group->id,
                        'tier' => (int) $group->tier,
                        'members_count' => (int) $group->members_count,
                        'capacity' => (int) $group->capacity,
                        'created_at' => $group->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * One page of "seats / waiting" per organization (docs/13 §1.3, §6.1). Seated = `organization_members` rows
     * `active + seated` of a learner who is not soft-deleted; waiting = `active + waitlisted`. Seats = `seats_limit` of the
     * organization's granting subscription (null = unlimited or none); `sponsoring` = it has one and is active.
     *
     * @return array{total: int, rows: list<array{id: int, name: string, status: string, active_groups_count: int, members_count: int, waitlisted_count: int, seats_limit: int|null, sponsoring: bool}>}
     */
    public function organizationSeats(int $page, int $perPage): array
    {
        [$page, $perPage] = [max(1, $page), max(1, $perPage)];

        /** @var array{total: int, rows: list<array{id: int, name: string, status: string, active_groups_count: int, members_count: int, waitlisted_count: int, seats_limit: int|null, sponsoring: bool}>} */
        return $this->cache->remember("organization_seats:p{$page}:n{$perPage}", 'all', 120, function () use ($page, $perPage): array {
            $now = CarbonImmutable::now();

            $members = fn (string $seatState) => DB::table('organization_members as om')
                // A deleted learner (soft delete) is not a member any more, though the membership row stays.
                ->join('learners as l', 'l.id', '=', 'om.learner_id')
                ->whereNull('l.deleted_at')
                ->whereColumn('om.organization_id', 'organizations.id')
                ->where('om.status', 'active')
                ->where('om.seat_state', $seatState)
                ->selectRaw('count(*)');

            $sponsoring = PaidSubscriptionQuery::granting(
                Subscription::query()
                    ->where('subscriptions.subscriber_type', 'organization')
                    ->whereColumn('subscriptions.subscriber_id', 'organizations.id'),
                $now,
            )->orderByDesc('subscriptions.id')->limit(1);

            return [
                'total' => Organization::query()->count(),
                'rows' => Organization::query()
                    ->select('organizations.id', 'organizations.name', 'organizations.status')
                    ->withCount(['telegramGroups as active_groups_count' => fn ($groups) => $groups->where('status', 'active')])
                    ->addSelect([
                        'members_count' => $members('seated'),
                        'waitlisted_count' => $members('waitlisted'),
                        'sponsor_subscription_id' => (clone $sponsoring)->select('subscriptions.id'),
                        'seats_limit' => (clone $sponsoring)->select('subscriptions.seats_limit'),
                    ])
                    ->orderByDesc('members_count')
                    ->orderBy('name')
                    ->orderBy('organizations.id')
                    ->forPage($page, $perPage)
                    ->get()
                    ->map(fn (Organization $organization): array => [
                        'id' => (int) $organization->id,
                        'name' => (string) $organization->name,
                        'status' => (string) $organization->status,
                        'active_groups_count' => (int) $organization->getAttribute('active_groups_count'),
                        'members_count' => (int) $organization->getAttribute('members_count'),
                        'waitlisted_count' => (int) $organization->getAttribute('waitlisted_count'),
                        'seats_limit' => $organization->getAttribute('seats_limit') === null ? null : (int) $organization->getAttribute('seats_limit'),
                        'sponsoring' => $organization->getAttribute('sponsor_subscription_id') !== null && $organization->status === 'active',
                    ])
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * Money of the last 30 days: the cash flow per currency and the cohort per currency ({@see BillingReportService}).
     * Dropped by `PaymentObserver` after every payment and refund, so a refund shows at once.
     *
     * @return array{cash: array<string, array{gross: int, refunds: int, net: int, charge_count: int, refund_count: int}>, cohort: array<string, array{gross: int, refunded: int, net: int, count: int}>}
     */
    public function revenue30d(): array
    {
        /** @var array{cash: array<string, array{gross: int, refunds: int, net: int, charge_count: int, refund_count: int}>, cohort: array<string, array{gross: int, refunded: int, net: int, count: int}>} */
        return $this->cache->remember('revenue_30d', 'all', 120, fn (): array => [
            'cash' => $this->billing->cashFlowLast30Days(),
            'cohort' => $this->billing->cohortRevenueLast30Days(),
        ]);
    }

    /**
     * Webhook trouble of the last 24 hours. Fresh for a minute and dropped with the money widgets after a payment.
     *
     * @return array{failed_payments: int, failed_by_provider: array<string, int>, canceled_transactions: int, double_discounts: int, tier_mismatches: int, stars_double_subscriptions: int}
     */
    public function webhookErrors24h(): array
    {
        /** @var array{failed_payments: int, failed_by_provider: array<string, int>, canceled_transactions: int, double_discounts: int, tier_mismatches: int, stars_double_subscriptions: int} */
        return $this->cache->remember('webhook_errors', 'all', 60, fn (): array => $this->billing->webhookErrorsLast24Hours());
    }

    /**
     * An entry of the widgets a teacher sees too: keyed by what the viewer sees. Nobody signed in is not cached.
     *
     * @param  Closure(): array<array-key, mixed>  $compute
     * @return array<array-key, mixed>
     */
    private function scoped(string $widget, int $fresh, Closure $compute, string $suffix = ''): array
    {
        $scope = $this->scope->fingerprint();

        if ($scope === 'none') {
            return $compute();
        }

        return $this->cache->remember($widget, $suffix === '' ? $scope : "{$scope}:{$suffix}", $fresh, $compute);
    }
}
