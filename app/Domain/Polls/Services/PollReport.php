<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Polls\Enums\PollStatus;
use App\Models\Course;
use App\Models\Poll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The results page of a poll in `/admin` (docs/35 §1.5 "Результаты", O7), test deliveries left out everywhere.
 *
 * {@see summary()} - the head (recipients by status, answered) and the votes per option with the share of the ANSWERED (several
 * answers: the shares add up to more than 100 %). An open poll counts live ({@see PollResults::totals()}, 3 statements); a closed
 * one reads its `results_snapshot` (written at closing, kept after `polls:prune`) - 0 statements.
 *
 * {@see breakdowns()} - the same by language of the delivery, Free / Pro (the learner's plan NOW, as the audience filter reads it),
 * active course and region (the learner's current ones). Two grouped statements over all four dimensions at once - the answered
 * deliveries and the votes, each joined with `learners` and grouped by (language, region, course, Pro[, option]) - then folded in
 * PHP: a few hundred groups, never a row per learner. The Pro test is the two uncorrelated "who is Pro" subqueries of
 * {@see PaidSubscriptionQuery}, hashed once. A closed poll does not change any more: its breakdowns are kept in the pure cache
 * ({@see WidgetCache}, `admin:widget:poll_breakdowns:v1:all:<poll id>`, fresh for a day); an open one is counted on every view.
 */
final class PollReport
{
    public const CACHE_WIDGET = 'poll_breakdowns';

    /** Freshness of the cached breakdowns of a closed poll (seconds). */
    public const CLOSED_FRESH_SECONDS = 86400;

    public const DIMENSIONS = ['locale', 'plan', 'course', 'region'];

    public function __construct(
        private readonly PollResults $results,
        private readonly WidgetCache $cache,
    ) {}

    /**
     * @return array{snapshot: bool, at: string, recipients: int, statuses: array<string, int>, answered: int,
     *               options: list<array{id: int, position: int, label: string, votes: int, share: float}>}
     */
    public function summary(Poll $poll): array
    {
        $snapshot = $poll->status === PollStatus::Closed && is_array($poll->results_snapshot) && isset($poll->results_snapshot['options']);
        $totals = $snapshot ? (array) $poll->results_snapshot : $this->results->totals($poll);
        $answered = (int) ($totals['answered'] ?? 0);
        $votes = is_array($totals['options'] ?? null) ? $totals['options'] : [];

        $options = [];
        foreach ($poll->options()->get(['id', 'position', 'label']) as $option) {
            $n = (int) ($votes[$option->id] ?? $votes[(string) $option->id] ?? 0);
            $options[] = [
                'id' => (int) $option->id,
                'position' => (int) $option->position,
                'label' => (string) ($option->label['ru'] ?? ''),
                'votes' => $n,
                'share' => $answered > 0 ? round($n * 100 / $answered, 1) : 0.0,
            ];
        }

        $statuses = [];
        foreach ((array) ($totals['statuses'] ?? []) as $status => $n) {
            $statuses[(string) $status] = (int) $n;
        }

        return [
            'snapshot' => $snapshot,
            'at' => (string) ($totals['at'] ?? ''),
            'recipients' => (int) ($totals['recipients'] ?? 0),
            'statuses' => $statuses,
            'answered' => $answered,
            'options' => $options,
        ];
    }

    /**
     * @return array<string, list<array{key: string, label: string, answered: int, votes: array<int, int>}>> by dimension
     *                                                                                                       ({@see DIMENSIONS}), groups by answered, descending
     */
    public function breakdowns(Poll $poll, ?CarbonImmutable $now = null): array
    {
        if ($poll->status !== PollStatus::Closed) {
            return $this->compute($poll->id, $now ?? CarbonImmutable::now());
        }

        /** @var array<string, list<array{key: string, label: string, answered: int, votes: array<int, int>}>> */
        return $this->cache->remember(
            self::CACHE_WIDGET,
            'all',
            self::CLOSED_FRESH_SECONDS,
            fn (): array => $this->compute($poll->id, $now ?? CarbonImmutable::now()),
            (string) $poll->id,
        );
    }

    /** Drops the cached breakdowns of a poll (after `polls:prune` took its rows). */
    public function forget(int $pollId): void
    {
        $this->cache->forget(self::CACHE_WIDGET, 'all', (string) $pollId);
    }

    /** @return array<string, list<array{key: string, label: string, answered: int, votes: array<int, int>}>> */
    private function compute(int $pollId, CarbonImmutable $now): array
    {
        $personal = PaidSubscriptionQuery::personalLearnerIds($now);
        $sponsored = PaidSubscriptionQuery::sponsoredLearnerIds($now);
        $pro = 'CASE WHEN d.learner_id IN ('.$personal->toSql().') OR d.learner_id IN ('.$sponsored->toSql().') THEN 1 ELSE 0 END';
        $proBindings = [...$personal->getBindings(), ...$sponsored->getBindings()];

        $answered = DB::table('poll_deliveries as d')
            ->join('learners as l', 'l.id', '=', 'd.learner_id')
            ->where('d.poll_id', $pollId)
            ->whereNotNull('d.learner_id')
            ->whereNotNull('d.answered_at')
            ->selectRaw("d.locale AS locale, l.region_code AS region, l.active_course_id AS course, {$pro} AS pro, count(*) AS n", $proBindings)
            // By position: the Pro expression carries bindings, and PostgreSQL would not match a repeated one to the select.
            ->groupByRaw('1, 2, 3, 4')
            ->get();

        $votes = DB::table('poll_votes as v')
            ->join('poll_deliveries as d', 'd.id', '=', 'v.poll_delivery_id')
            ->join('learners as l', 'l.id', '=', 'd.learner_id')
            ->where('v.poll_id', $pollId)
            ->whereNotNull('d.learner_id')
            ->selectRaw("d.locale AS locale, l.region_code AS region, l.active_course_id AS course, {$pro} AS pro, v.poll_option_id AS option_id, count(*) AS n", $proBindings)
            ->groupByRaw('1, 2, 3, 4, 5')
            ->get();

        /** @var array<string, array<string, array{answered: int, votes: array<int, int>}>> $groups */
        $groups = array_fill_keys(self::DIMENSIONS, []);
        $keys = static fn (object $row): array => [
            'locale' => (string) $row->locale,
            'plan' => (int) $row->pro === 1 ? 'pro' : 'free',
            'course' => $row->course === null ? 'none' : (string) (int) $row->course,
            'region' => $row->region === null ? 'none' : (string) $row->region,
        ];

        foreach ($answered as $row) {
            foreach ($keys($row) as $dimension => $key) {
                $groups[$dimension][$key] ??= ['answered' => 0, 'votes' => []];
                $groups[$dimension][$key]['answered'] += (int) $row->n;
            }
        }

        foreach ($votes as $row) {
            foreach ($keys($row) as $dimension => $key) {
                $groups[$dimension][$key] ??= ['answered' => 0, 'votes' => []];
                $option = (int) $row->option_id;
                $groups[$dimension][$key]['votes'][$option] = ($groups[$dimension][$key]['votes'][$option] ?? 0) + (int) $row->n;
            }
        }

        $courses = $this->courseTitles(array_keys($groups['course']));
        $out = [];

        foreach ($groups as $dimension => $byKey) {
            $rows = [];
            foreach ($byKey as $key => $group) {
                $rows[] = [
                    'key' => (string) $key,
                    'label' => $dimension === 'course' && $key !== 'none' ? ($courses[(int) $key] ?? '#'.$key) : (string) $key,
                    'answered' => $group['answered'],
                    'votes' => $group['votes'],
                ];
            }

            usort($rows, static fn (array $a, array $b): int => [$b['answered'], $a['key']] <=> [$a['answered'], $b['key']]);
            $out[$dimension] = $rows;
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<int, string>
     */
    private function courseTitles(array $keys): array
    {
        $ids = array_values(array_filter(array_map('intval', array_filter($keys, static fn ($k): bool => $k !== 'none')), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $titles = [];
        foreach (Course::query()->whereIn('id', $ids)->get(['id', 'title']) as $course) {
            $titles[(int) $course->id] = (string) ($course->title['ru'] ?? $course->title['en'] ?? reset($course->title) ?: '#'.$course->id);
        }

        return $titles;
    }
}
