<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\DTO\DateRange;
use App\Domain\Organizations\DTO\GroupActivityRow;
use App\Domain\Organizations\DTO\LearnerActivityRow;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Activity report of a center (docs/13 §2.3, §6.4): per learner and per group over a period.
 *
 * The ONLY sources are the daily aggregates `learner_daily_stats` (reviews, new words, active days,
 * days the goal was met) and the result snapshots of the center's assignments — never
 * `training_sessions` / `training_reviews` (a teacher does not see a learner's sessions one by one,
 * docs/12 §4.4). Who is in the report is decided by {@see OrgTenantScope} for the acting user:
 * the whole center for an owner, the learners of their own groups for a teacher; a learner of
 * another organization cannot get in, a group the user may not see yields an empty report.
 *
 * Every figure is one grouped query (no per-learner queries). Definitions:
 *  - `days_active`  days of the period with `reviews_count > 0 OR new_words_count > 0` (as the dashboard);
 *  - `goal_met_days` days of the period with `goal_met_at`;
 *  - `streak_current` the stored streak, read as zero when its last credited day is older than
 *    yesterday (organization's timezone) — a pure read, unlike StreakService::current();
 *  - assignments  the center's assignments whose `due_at` falls into the period, for the learners who
 *    are still active in the assignment's group: `total` = result rows, `done` = completed ones.
 */
final class OrgActivityReport
{
    public function __construct(private readonly OrgTenantScope $scope) {}

    /**
     * @return list<LearnerActivityRow> sorted by reviews (desc), then name
     */
    public function perLearner(User $user, Organization $organization, ?TelegramGroup $group, DateRange $range, ?CarbonImmutable $now = null): array
    {
        $learners = $this->learnerQuery($user, $organization, $group)
            ->get(['learners.id', 'first_name', 'last_name', 'username', 'streak_current', 'streak_last_date', 'last_seen_at']);

        if ($learners->isEmpty()) {
            return [];
        }

        $ids = $learners->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $stats = [];
        $statRows = LearnerDailyStats::query()
            ->whereIn('learner_id', $ids)
            ->where('date', '>=', $this->storedDate($range->from))
            ->where('date', '<=', $this->storedDate($range->to))
            ->groupBy('learner_id')
            ->select('learner_id')
            ->selectRaw('COALESCE(SUM(reviews_count), 0) AS reviews')
            ->selectRaw('COALESCE(SUM(new_words_count), 0) AS new_words')
            ->selectRaw('COUNT(CASE WHEN reviews_count > 0 OR new_words_count > 0 THEN 1 END) AS days_active')
            ->selectRaw('COUNT(goal_met_at) AS goal_met_days')
            ->get();

        foreach ($statRows as $row) {
            $stats[(int) $row->getAttribute('learner_id')] = $row;
        }

        $assignments = $this->assignmentTotals($user, $organization, $range, $group, 'ar.learner_id', fn ($q) => $q->whereIn('ar.learner_id', $ids));

        $today = ($now ?? CarbonImmutable::now())->setTimezone($organization->timezone)->startOfDay();
        $yesterday = $today->subDay()->toDateString();

        $rows = [];
        foreach ($learners as $learner) {
            $stat = $stats[$learner->id] ?? null;
            $last = $learner->streak_last_date?->toDateString();
            $assignment = $assignments[$learner->id] ?? ['done' => 0, 'total' => 0];

            $rows[] = new LearnerActivityRow(
                learnerId: $learner->id,
                name: $this->displayName($learner),
                username: $learner->username,
                reviews: (int) ($stat?->getAttribute('reviews') ?? 0),
                newWords: (int) ($stat?->getAttribute('new_words') ?? 0),
                daysActive: (int) ($stat?->getAttribute('days_active') ?? 0),
                goalMetDays: (int) ($stat?->getAttribute('goal_met_days') ?? 0),
                streakCurrent: $last !== null && $last >= $yesterday ? (int) $learner->streak_current : 0,
                assignmentsDone: $assignment['done'],
                assignmentsTotal: $assignment['total'],
                lastSeenAt: $learner->last_seen_at === null ? null : CarbonImmutable::instance($learner->last_seen_at),
            );
        }

        usort($rows, fn (LearnerActivityRow $a, LearnerActivityRow $b): int => [$b->reviews, $a->name, $a->learnerId] <=> [$a->reviews, $b->name, $b->learnerId]);

        return $rows;
    }

    /**
     * One row per group visible to the user (sorted by title); a learner in two groups counts in both.
     *
     * @return list<GroupActivityRow>
     */
    public function perGroup(User $user, Organization $organization, DateRange $range): array
    {
        $groups = $this->scope->groups($user, $organization)->orderBy('telegram_groups.title')->orderBy('telegram_groups.id')->get(['telegram_groups.id', 'telegram_groups.title']);

        if ($groups->isEmpty()) {
            return [];
        }

        $groupIds = $groups->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $scoped = $this->scope->learners($user, $organization)->select('learners.id');

        $members = [];
        foreach (DB::table('group_members as gm')
            ->whereIn('gm.telegram_group_id', $groupIds)
            ->where('gm.status', 'active')
            ->whereIn('gm.learner_id', $scoped)
            ->groupBy('gm.telegram_group_id')
            ->select('gm.telegram_group_id as group_id')
            ->selectRaw('COUNT(*) AS learners')
            ->get() as $row) {
            $members[(int) $row->group_id] = (int) $row->learners;
        }

        $stats = [];
        foreach (DB::table('group_members as gm')
            ->join('learner_daily_stats as s', 's.learner_id', '=', 'gm.learner_id')
            ->whereIn('gm.telegram_group_id', $groupIds)
            ->where('gm.status', 'active')
            ->whereIn('gm.learner_id', $scoped)
            ->where('s.date', '>=', $this->storedDate($range->from))
            ->where('s.date', '<=', $this->storedDate($range->to))
            ->groupBy('gm.telegram_group_id')
            ->select('gm.telegram_group_id as group_id')
            ->selectRaw('COALESCE(SUM(s.reviews_count), 0) AS reviews')
            ->selectRaw('COALESCE(SUM(s.new_words_count), 0) AS new_words')
            ->selectRaw('COUNT(DISTINCT CASE WHEN s.reviews_count > 0 OR s.new_words_count > 0 THEN s.learner_id END) AS active_learners')
            ->selectRaw('COUNT(s.goal_met_at) AS goal_met_days')
            ->get() as $row) {
            $stats[(int) $row->group_id] = $row;
        }

        $assignments = $this->assignmentTotals($user, $organization, $range, null, 'a.telegram_group_id', fn ($q) => $q->whereIn('ar.learner_id', $scoped));

        $rows = [];
        foreach ($groups as $group) {
            $stat = $stats[$group->id] ?? null;
            $assignment = $assignments[$group->id] ?? ['done' => 0, 'total' => 0];

            $rows[] = new GroupActivityRow(
                groupId: $group->id,
                title: (string) $group->title,
                learners: $members[$group->id] ?? 0,
                activeLearners: (int) ($stat->active_learners ?? 0),
                reviews: (int) ($stat->reviews ?? 0),
                newWords: (int) ($stat->new_words ?? 0),
                goalMetDays: (int) ($stat->goal_met_days ?? 0),
                assignmentsDone: $assignment['done'],
                assignmentsTotal: $assignment['total'],
            );
        }

        return $rows;
    }

    /**
     * Learners of the report: what the user may see, optionally only the active members of one group
     * (a group the user cannot see matches nothing).
     *
     * @return Builder<Learner>
     */
    private function learnerQuery(User $user, Organization $organization, ?TelegramGroup $group): Builder
    {
        $query = $this->scope->learners($user, $organization);

        if ($group === null) {
            return $query;
        }

        if (! $this->scope->canSeeGroup($user, $organization, $group)) {
            return $query->whereKey([]);
        }

        return $query->whereIn('learners.id', fn ($sub) => $sub
            ->select('learner_id')
            ->from('group_members')
            ->where('telegram_group_id', $group->id)
            ->where('status', 'active'));
    }

    /**
     * Assignment result rows grouped by `$key` (`ar.learner_id` or `a.telegram_group_id`):
     * assignments of the organization, in the groups the user sees (or the one `$group`), due within the
     * period, for learners still active in the assignment's group.
     *
     * @param  \Closure(\Illuminate\Database\Query\Builder): mixed  $restrict  limits the learners
     * @return array<int, array{done: int, total: int}>
     */
    private function assignmentTotals(User $user, Organization $organization, DateRange $range, ?TelegramGroup $group, string $key, \Closure $restrict): array
    {
        $groupIds = $group !== null
            ? ($this->scope->canSeeGroup($user, $organization, $group) ? [$group->id] : [])
            : $this->scope->groups($user, $organization)->pluck('telegram_groups.id')->map(fn ($id): int => (int) $id)->all();

        if ($groupIds === []) {
            return [];
        }

        $tz = (string) config('app.timezone');

        $query = DB::table('assignment_results as ar')
            ->join('assignments as a', 'a.id', '=', 'ar.assignment_id')
            ->join('group_members as gm', function ($join): void {
                $join->on('gm.learner_id', '=', 'ar.learner_id')->on('gm.telegram_group_id', '=', 'a.telegram_group_id');
            })
            ->where('gm.status', 'active')
            ->where('a.organization_id', $organization->id)
            ->whereIn('a.telegram_group_id', $groupIds)
            ->where('a.due_at', '>=', $range->startsAt($organization)->setTimezone($tz))
            ->where('a.due_at', '<=', $range->endsAt($organization)->setTimezone($tz))
            ->groupBy($key)
            ->select($key.' as k')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(ar.completed_at) AS done');
        $restrict($query);

        $totals = [];
        foreach ($query->get() as $row) {
            $totals[(int) $row->k] = ['done' => (int) $row->done, 'total' => (int) $row->total];
        }

        return $totals;
    }

    /** The `date` column as Eloquent stores it (sqlite keeps text, so bounds must be in the same format). */
    private function storedDate(string $localDate): string
    {
        return (string) (new LearnerDailyStats)->fromDateTime($localDate);
    }

    private function displayName(Learner $learner): string
    {
        $name = trim(($learner->first_name ?? '').' '.($learner->last_name ?? ''));

        return $name !== '' ? $name : ($learner->username !== null ? '@'.$learner->username : '#'.$learner->id);
    }
}
