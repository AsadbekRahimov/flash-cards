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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the dashboard widgets of `/admin` (docs/19 K1, docs/20 F5 / A12), every one an array of scalars read
 * through {@see WidgetCache}: the widgets render it and run no query of their own, so a second open of the dashboard
 * costs no SQL until the entry goes stale (60-300 s, recomputed after the response) or is dropped by an event.
 *
 * Whose figures they are is the viewer's scope ({@see PanelDataScope}): the widgets a teacher also sees are keyed
 * by `PanelDataScope::fingerprint()`, the admin-only ones by `all`.
 *
 * The cache sits on top of FAST queries (docs/20 §4 "Индексы F2": the heavy widgets are not cured by indexes): the first show
 * after an entry has expired must not cost seconds either. The rankings are ONE aggregate over the reviews of the window
 * (`group by word_id` / `group by learner_id`, the best ten) joined to the ten rows they name, not a correlated subquery per
 * word or per learner; an admin's scope adds no filter by sessions (it would be an empty one, and PostgreSQL still hash-joins
 * every session for it) and leaves the deleted learners out with an anti-join against the few of them. The results are the
 * ones of the queries they replace, for an admin and for a teacher (`AdminDashboardQueriesTest` runs both on one data set).
 *
 * Table widgets get the page the viewer asked for (`$page`, `$perPage`): each page is an entry of its own - and the number of
 * entries is bounded: `$perPage` is one of the sizes the widget offers, `$page` is one of the first {@see MAX_PAGE}. Both are
 * public Livewire properties, so what arrives is not what the select shows; a forged size or page number would otherwise be a
 * `limit 1000001` and a key of its own for every number tried.
 */
final class AdminDashboardStats
{
    /** Rows per page of the league widget, the first one is the default (the same list the table offers and enforces). */
    public const LEAGUE_GROUPS_PAGE_SIZES = [10, 25, 50];

    /** Rows per page of the organization seats widget, the first one is the default. */
    public const ORGANIZATION_SEATS_PAGE_SIZES = [10, 25];

    /** The deepest page a table widget serves: a page number past it is this page, not a key of its own. */
    public const MAX_PAGE = 1000;

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
     * or other groups' work; only learners the viewer may see are ranked (an admin: everybody not deleted; a teacher: the active
     * members of their groups). Ties are broken by the learner's id, so the list is the same at every computation.
     *
     * @return list<array{id: int, name: string, groups: string, reviews_count: int}>
     */
    public function topLearners(): array
    {
        /** @var list<array{id: int, name: string, groups: string, reviews_count: int}> */
        return $this->scoped('top_learners', 300, function (): array {
            $reviews = DB::table('training_reviews as tr')
                ->where('tr.created_at', '>=', now()->subDays(30))
                ->groupBy('tr.learner_id')
                ->selectRaw('tr.learner_id, COUNT(*) AS reviews_count')
                ->orderByDesc('reviews_count')
                ->orderBy('tr.learner_id')
                ->limit(10);

            if ($this->scope->isAdmin()) {
                $reviews->whereNotExists(fn (QueryBuilder $gone) => $gone->from('learners as gone')
                    ->selectRaw('1')
                    ->whereColumn('gone.id', 'tr.learner_id')
                    ->whereNotNull('gone.deleted_at'));
            } else {
                $reviews
                    ->whereIn('tr.training_session_id', $this->scope->trainingSessionIds())
                    ->whereIn('tr.learner_id', $this->scope->learnerIds());
            }

            /** @var array<int, int> $counts learner id => reviews in the window, the best first */
            $counts = [];
            foreach ($reviews->get() as $row) {
                $counts[(int) $row->learner_id] = (int) $row->reviews_count;
            }

            if ($counts === []) {
                return [];
            }

            $learners = Learner::query()
                ->whereIn('id', array_keys($counts))
                ->with(['groups' => fn ($groups) => $groups
                    ->wherePivot('status', 'active')
                    ->whereIn('telegram_groups.id', $this->scope->groups()->select('telegram_groups.id'))])
                ->get()
                ->keyBy('id');

            $rows = [];
            foreach ($counts as $id => $count) {
                $learner = $learners->get($id);

                // Deleted between the two reads: not ranked (the ranking leaves the deleted out as well).
                if ($learner === null) {
                    continue;
                }

                $rows[] = [
                    'id' => $id,
                    'name' => trim(($learner->first_name ?? '—').' '.($learner->last_name ?? '')),
                    'groups' => $learner->groups->pluck('title')->implode(', '),
                    'reviews_count' => $count,
                ];
            }

            return $rows;
        });
    }

    /**
     * The ten words learners miss most (a grade below 3) over the last 30 days, with at least five attempts in the
     * viewer's sessions: one aggregate over the reviews of the window (`group by word_id having count(*) >= 5`, the ten with the
     * highest share of misses) joined to the ten words it names. The heaviest widget of the dashboard before the rewrite (2.6 s
     * at 200 000 learners, docs/20 §4). Ties are broken by more attempts, then the word's id, so the list is the same at every
     * computation.
     *
     * @return list<array{id: int, word: string, translation: string, attempts: int, hard_ratio: float|null}>
     */
    public function hardestWords(): array
    {
        /** @var list<array{id: int, word: string, translation: string, attempts: int, hard_ratio: float|null}> */
        return $this->scoped('hardest_words', 300, function (): array {
            $ranked = DB::table('training_reviews as tr')
                ->where('tr.created_at', '>=', now()->subDays(30))
                ->groupBy('tr.word_id')
                ->havingRaw('COUNT(*) >= ?', [5])
                ->selectRaw('tr.word_id, COUNT(*) AS attempts, CAST(SUM(CASE WHEN tr.quality < ? THEN 1 ELSE 0 END) AS FLOAT) / COUNT(*) AS hard_ratio', [3])
                ->orderByDesc('hard_ratio')
                ->orderByDesc('attempts')
                ->orderBy('tr.word_id')
                ->limit(10);

            // An admin sees every session: no filter (a filter by every session id is empty, and PostgreSQL still hash-joins them all).
            if (! $this->scope->isAdmin()) {
                $ranked->whereIn('tr.training_session_id', $this->scope->trainingSessionIds());
            }

            return Word::query()
                ->joinSub($ranked, 'hard', 'hard.word_id', '=', 'words.id')
                ->select('words.id', 'words.word', 'words.translation', 'hard.attempts', 'hard.hard_ratio')
                ->orderByDesc('hard.hard_ratio')
                ->orderByDesc('hard.attempts')
                ->orderBy('words.id')
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
     * One page of the groups of a league week, highest tier first: up to `$perPage + 1` rows, the extra one only tells a simple
     * paginator (the pagination of a Filament table widget, no COUNT) that there is a next page.
     *
     * @return list<array{id: int, tier: int, members_count: int, capacity: int, created_at: string|null}>
     */
    public function leagueGroups(int $weekId, int $page, int $perPage): array
    {
        [$page, $perPage] = [self::pageNumber($page), self::pageSize($perPage, self::LEAGUE_GROUPS_PAGE_SIZES)];

        /** @var list<array{id: int, tier: int, members_count: int, capacity: int, created_at: string|null}> */
        return $this->cache->remember('league_groups', 'all', 60, fn (): array => LeagueGroup::query()
            ->where('league_week_id', $weekId)
            ->orderByDesc('tier')
            ->orderBy('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get()
            ->map(fn (LeagueGroup $group): array => [
                'id' => (int) $group->id,
                'tier' => (int) $group->tier,
                'members_count' => (int) $group->members_count,
                'capacity' => (int) $group->capacity,
                'created_at' => $group->created_at?->toIso8601String(),
            ])
            ->values()
            ->all(), "w{$weekId}:p{$page}:n{$perPage}");
    }

    /**
     * One page of "seats / waiting" per organization (docs/13 §1.3, §6.1): up to `$perPage + 1` rows, the extra one only tells a simple
     * paginator that there is a next page. Seated = `organization_members` rows `active + seated` of a learner who is not
     * soft-deleted; waiting = `active + waitlisted`. Seats = `seats_limit` of the organization's granting subscription (null =
     * unlimited or none); `sponsoring` = it has one and is active.
     *
     * @return list<array{id: int, name: string, status: string, active_groups_count: int, members_count: int, waitlisted_count: int, seats_limit: int|null, sponsoring: bool}>
     */
    public function organizationSeats(int $page, int $perPage): array
    {
        [$page, $perPage] = [self::pageNumber($page), self::pageSize($perPage, self::ORGANIZATION_SEATS_PAGE_SIZES)];

        /** @var list<array{id: int, name: string, status: string, active_groups_count: int, members_count: int, waitlisted_count: int, seats_limit: int|null, sponsoring: bool}> */
        return $this->cache->remember('organization_seats', 'all', 120, function () use ($page, $perPage): array {
            $now = CarbonImmutable::now();

            $members = fn (string $seatState) => DB::table('organization_members as om')
                ->whereColumn('om.organization_id', 'organizations.id')
                ->where('om.status', 'active')
                ->where('om.seat_state', $seatState)
                // A deleted learner (soft delete) is not a member any more, though the membership row stays. Asked as an anti-join
                // against the few deleted ones (partial index learners_deleted_idx), not as a primary-key lookup of every member
                // through a join of `learners` (32 000 lookups for 100 organizations at 200 000 learners, docs/20 §4).
                ->whereNotExists(fn (QueryBuilder $gone) => $gone->from('learners as gone')
                    ->selectRaw('1')
                    ->whereColumn('gone.id', 'om.learner_id')
                    ->whereNotNull('gone.deleted_at'))
                ->selectRaw('count(*)');

            $sponsoring = PaidSubscriptionQuery::granting(
                Subscription::query()
                    ->where('subscriptions.subscriber_type', 'organization')
                    ->whereColumn('subscriptions.subscriber_id', 'organizations.id'),
                $now,
            )->orderByDesc('subscriptions.id')->limit(1);

            return Organization::query()
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
                ->offset(($page - 1) * $perPage)
                ->limit($perPage + 1)
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
                ->all();
        }, "p{$page}:n{$perPage}");
    }

    /**
     * Money of the last 30 days: the cash flow per currency and the cohort per currency ({@see BillingReportService}).
     * Read at the data version of the ledger ({@see WidgetCache::moneyScope()}): `MoneyWidgetsObserver` moves it on after every
     * payment and refund, so a refund shows at once and a figure computed from the ledger before it can no longer be served
     * after it.
     *
     * @return array{cash: array<string, array{gross: int, refunds: int, net: int, charge_count: int, refund_count: int}>, cohort: array<string, array{gross: int, refunded: int, net: int, count: int}>}
     */
    public function revenue30d(): array
    {
        /** @var array{cash: array<string, array{gross: int, refunds: int, net: int, charge_count: int, refund_count: int}>, cohort: array<string, array{gross: int, refunded: int, net: int, count: int}>} */
        return $this->cache->remember('revenue_30d', $this->cache->moneyScope(), 120, fn (): array => [
            'cash' => $this->billing->cashFlowLast30Days(),
            'cohort' => $this->billing->cohortRevenueLast30Days(),
        ]);
    }

    /**
     * Webhook trouble of the last 24 hours. Fresh for a minute and read at the data version of the ledger like the revenue: a failed
     * or a canceled payment shows at once. The three anomalies of `payment_orders` (double discounts, tier mismatches, Stars double
     * subscriptions) are not written by a payment of the ledger, so they only change with the freshness window.
     *
     * @return array{failed_payments: int, failed_by_provider: array<string, int>, canceled_transactions: int, double_discounts: int, tier_mismatches: int, stars_double_subscriptions: int}
     */
    public function webhookErrors24h(): array
    {
        /** @var array{failed_payments: int, failed_by_provider: array<string, int>, canceled_transactions: int, double_discounts: int, tier_mismatches: int, stars_double_subscriptions: int} */
        return $this->cache->remember('webhook_errors', $this->cache->moneyScope(), 60, fn (): array => $this->billing->webhookErrorsLast24Hours());
    }

    /** A page number of a table widget: 1 up to {@see MAX_PAGE}. */
    public static function pageNumber(int $page): int
    {
        return min(max(1, $page), self::MAX_PAGE);
    }

    /**
     * A page size of a table widget: one of the sizes the widget offers, the default (the first) for anything else.
     *
     * @param  list<int>  $sizes
     */
    public static function pageSize(int $perPage, array $sizes): int
    {
        return in_array($perPage, $sizes, true) ? $perPage : $sizes[0];
    }

    /**
     * An entry of the widgets a teacher sees too: keyed by what the viewer sees. Nobody signed in is not cached.
     *
     * @param  Closure(): array<array-key, mixed>  $compute
     * @return array<array-key, mixed>
     */
    private function scoped(string $widget, int $fresh, Closure $compute, string $params = ''): array
    {
        $scope = $this->scope->fingerprint();

        if ($scope === 'none') {
            return $compute();
        }

        return $this->cache->remember($widget, $scope, $fresh, $compute, $params);
    }
}
