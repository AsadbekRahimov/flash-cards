<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Learners\Support\StoredDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The product event `score.credited` (docs/32 S16, the architect's decision): ONE summary per rating day, not an event per learner or
 * per answer - the nightly `leagues:report-score-day` (00:30 of the league zone) sums yesterday from `learner_score_days` (the index
 * `(rating_day)`, the day's rows only):
 *
 *   learners, credits                          - the rows of the day and their credited events (`new_count + review_count`: a credit
 *                                                is journaled even when the limits gave it 0), also per category of the month;
 *   {week|month}_{free|pro}_{p50|p90}          - the points of the count per learner (nearest rank), only rows of a count on v2;
 *   {week|month}_{free|pro}_capped_share       - the share of those rows that reached the cap (0..1, 3 digits).
 *
 * Idempotent: the row is written by ONE `INSERT … SELECT … WHERE NOT EXISTS` (an event of the same name and `rating_day`), so a second
 * run - by hand, after a crash, a parallel one - writes nothing. The check reads `product_events` by the index `(name, occurred_at)`:
 * one row of this name a day, no index of its own is needed (EXPLAIN in docs/32 §12.8).
 */
final class ScoreDayReport
{
    public function __construct(
        private readonly ScoreRules $rules,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The summary of the rating day `$day` (`Y-m-d`), written once.
     *
     * @return array<string, bool|int|float|string|null>|null the props written; null when the day is already reported or the points are off
     */
    public function record(string $day, CarbonImmutable $now): ?array
    {
        if (! $this->rules->records()) {
            return null;
        }

        $props = $this->events->sanitize($this->summarize($day));
        $name = ProductEventName::ScoreCredited->value;
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        // The values of a SELECT without a table: PostgreSQL types them by an explicit cast (a bare parameter would be text).
        $row = DB::query()->selectRaw(
            $pgsql ? '?, cast(? as jsonb), cast(? as timestamp)' : '?, ?, ?',
            [$name, json_encode($props, JSON_THROW_ON_ERROR), $now->utc()->format('Y-m-d H:i:s')],
        )->whereNotExists(static fn (Builder $q): Builder => $q->from('product_events')->where('name', $name)->where('props->rating_day', $day));

        return DB::table('product_events')->insertUsing(['name', 'props', 'occurred_at'], $row) > 0 ? $props : null;
    }

    /** @return array<string, int|float|string> */
    public function summarize(string $day): array
    {
        $rows = DB::table('learner_score_days')
            ->where('rating_day', StoredDate::of($day))
            ->select(['week_version', 'week_category', 'week_points', 'week_capped_at', 'month_version', 'month_category', 'month_points', 'month_capped_at', 'new_count', 'review_count'])
            ->cursor();

        $learners = ['free' => 0, 'pro' => 0];
        $credits = ['free' => 0, 'pro' => 0];
        /** @var array<string, array<string, list<int>>> $points */
        $points = ['week' => ['free' => [], 'pro' => []], 'month' => ['free' => [], 'pro' => []]];
        $capped = ['week' => ['free' => 0, 'pro' => 0], 'month' => ['free' => 0, 'pro' => 0]];

        foreach ($rows as $row) {
            $category = self::category($row->month_category);
            $learners[$category]++;
            $credits[$category] += (int) $row->new_count + (int) $row->review_count;
            foreach (['week', 'month'] as $count) {
                if ((int) $row->{$count.'_version'} < 2) {
                    continue;
                }
                $c = self::category($row->{$count.'_category'});
                $points[$count][$c][] = (int) $row->{$count.'_points'};
                $capped[$count][$c] += $row->{$count.'_capped_at'} !== null ? 1 : 0;
            }
        }

        $props = [
            'rating_day' => $day,
            'learners' => $learners['free'] + $learners['pro'],
            'credits' => $credits['free'] + $credits['pro'],
            'free_learners' => $learners['free'],
            'free_credits' => $credits['free'],
            'pro_learners' => $learners['pro'],
            'pro_credits' => $credits['pro'],
        ];
        foreach ($points as $count => $byCategory) {
            foreach ($byCategory as $category => $list) {
                sort($list);
                $n = count($list);
                $props["{$count}_{$category}_p50"] = self::percentile($list, 0.5);
                $props["{$count}_{$category}_p90"] = self::percentile($list, 0.9);
                $props["{$count}_{$category}_capped_share"] = $n === 0 ? 0 : round($capped[$count][$category] / $n, 3);
            }
        }

        return $props;
    }

    /** @param  list<int>  $sorted  ascending */
    private static function percentile(array $sorted, float $p): int
    {
        $n = count($sorted);

        return $n === 0 ? 0 : $sorted[max(0, (int) ceil($p * $n) - 1)];
    }

    private static function category(mixed $value): string
    {
        return $value === ScoreCategory::Pro->value ? 'pro' : 'free';
    }
}
