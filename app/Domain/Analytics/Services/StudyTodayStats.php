<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Learners\Support\StoredDate;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * «Учились сегодня» of the dashboard of `/admin`: learners who studied today, how many of them met the daily goal, and how many
 * had studied by this very time on the same weekday a week ago, so the comparison is between equal parts of a day.
 *
 * Definitions:
 *  - today: the date in {@see RetentionReportService::REPORT_TIMEZONE} (the product's home calendar); the rows are the learners'
 *    LOCAL dates (`learner_daily_stats.date`), so a learner far from Tashkent may be a few hours off at the day's edges;
 *  - studied: a `learner_daily_stats` row of the date with `reviews_count > 0`; goal met: the same row with `goal_met_at` set;
 *  - a week ago by now: a row of the date seven days earlier with `reviews_count > 0`, created (the first activity of that day)
 *    no later than seven days before now. A day whose first activity was a game run and whose first answer came later counts from
 *    the run: a lower bound of the error, minutes on a day.
 *
 * One aggregate over the two dates on `learner_daily_stats_date_idx (date)`; the result is an array of scalars in the pure cache
 * ({@see WidgetCache}, `admin:widget:study_today:v1:all:<date>`, fresh {@see FRESH_SECONDS}); numbers are cast back on read.
 */
final class StudyTodayStats
{
    public const WIDGET = 'study_today';

    public const FRESH_SECONDS = 120;

    public function __construct(private readonly WidgetCache $cache) {}

    /** @return array{date: string, studied: int, goal_met: int, week_ago: int} */
    public function figures(): array
    {
        $now = CarbonImmutable::now();
        $today = self::today($now);

        $figures = $this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => $this->compute($now), $today);

        return [
            'date' => is_string($figures['date'] ?? null) ? $figures['date'] : $today,
            'studied' => is_numeric($figures['studied'] ?? null) ? (int) $figures['studied'] : 0,
            'goal_met' => is_numeric($figures['goal_met'] ?? null) ? (int) $figures['goal_met'] : 0,
            'week_ago' => is_numeric($figures['week_ago'] ?? null) ? (int) $figures['week_ago'] : 0,
        ];
    }

    /**
     * The figures as of `$now`, from the database (no cache).
     *
     * @return array{date: string, studied: int, goal_met: int, week_ago: int}
     */
    public function compute(CarbonImmutable $now): array
    {
        $today = self::today($now);
        $weekAgo = CarbonImmutable::parse($today)->subDays(7)->toDateString();
        [$todayDb, $weekAgoDb] = [StoredDate::of($today), StoredDate::of($weekAgo)];

        $row = DB::table('learner_daily_stats')
            ->whereIn('date', [$todayDb, $weekAgoDb])
            ->where('reviews_count', '>', 0)
            ->selectRaw(
                'SUM(CASE WHEN date = ? THEN 1 ELSE 0 END) AS studied, '
                .'SUM(CASE WHEN date = ? AND goal_met_at IS NOT NULL THEN 1 ELSE 0 END) AS goal_met, '
                .'SUM(CASE WHEN date = ? AND created_at <= ? THEN 1 ELSE 0 END) AS week_ago',
                [$todayDb, $todayDb, $weekAgoDb, $now->subDays(7)->utc()->format('Y-m-d H:i:s')],
            )
            ->first();

        return [
            'date' => $today,
            'studied' => (int) ($row->studied ?? 0),
            'goal_met' => (int) ($row->goal_met ?? 0),
            'week_ago' => (int) ($row->week_ago ?? 0),
        ];
    }

    /** Change against the same part of the day a week ago, in whole percent; null without a base. */
    public static function change(int $now, int $weekAgo): ?int
    {
        return $weekAgo > 0 ? (int) round(($now - $weekAgo) / $weekAgo * 100) : null;
    }

    private static function today(CarbonImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone(RetentionReportService::REPORT_TIMEZONE))->toDateString();
    }
}
