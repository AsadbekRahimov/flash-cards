<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Learners\Support\StoredDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * «Занятия по неделям» of the dashboard of `/admin`: the answers of all learners per Monday-Sunday week of the last {@see WEEKS}
 * weeks (the current one included, partial), split into repetitions and new words.
 *
 * Definitions: the week is a range of the learners' LOCAL dates (`learner_daily_stats.date`), Monday to Sunday, the weeks of
 * {@see RetentionReportService::REPORT_TIMEZONE}; answers = `reviews_count` (every graded card, the game and the practice are not
 * in it), new words = `new_words_count` (the first answer to a card), repetitions = the difference.
 *
 * One aggregate per date over `learner_daily_stats_date_idx (date)` (at most 7 × WEEKS rows), the weeks are added up in PHP so
 * sqlite and PostgreSQL agree. An array of scalars in the pure cache ({@see WidgetCache},
 * `admin:widget:weekly_activity:v1:all:<monday>`, fresh {@see FRESH_SECONDS}); numbers are cast back on read.
 */
final class WeeklyActivityStats
{
    public const WIDGET = 'weekly_activity';

    public const FRESH_SECONDS = 900;

    public const WEEKS = 12;

    public function __construct(private readonly WidgetCache $cache) {}

    /** @return list<array{week_start: string, reviews: int, new_words: int}> the oldest week first */
    public function weeks(): array
    {
        $now = CarbonImmutable::now();
        $monday = self::monday($now)->toDateString();

        $weeks = [];
        foreach ($this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => $this->compute($now), $monday) as $week) {
            if (! is_array($week)) {
                continue;
            }
            $weeks[] = [
                'week_start' => is_string($week['week_start'] ?? null) ? $week['week_start'] : '',
                'reviews' => is_numeric($week['reviews'] ?? null) ? (int) $week['reviews'] : 0,
                'new_words' => is_numeric($week['new_words'] ?? null) ? (int) $week['new_words'] : 0,
            ];
        }

        return $weeks;
    }

    /**
     * The weeks as of `$now`, from the database (no cache).
     *
     * @return list<array{week_start: string, reviews: int, new_words: int}>
     */
    public function compute(CarbonImmutable $now): array
    {
        $first = self::monday($now)->subWeeks(self::WEEKS - 1);

        $rows = DB::table('learner_daily_stats')
            ->where('date', '>=', StoredDate::of($first->toDateString()))
            ->groupBy('date')
            ->selectRaw('date, SUM(reviews_count) AS reviews, SUM(new_words_count) AS new_words')
            ->get();

        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $weeks[$first->addWeeks($i)->toDateString()] = ['reviews' => 0, 'new_words' => 0];
        }

        foreach ($rows as $row) {
            // `Y-m-d` on PostgreSQL, `Y-m-d 00:00:00` on sqlite.
            $date = CarbonImmutable::parse(substr((string) $row->date, 0, 10));
            $start = $date->startOfWeek(CarbonInterface::MONDAY)->toDateString();
            if (! isset($weeks[$start])) {
                continue;
            }
            $weeks[$start]['reviews'] += (int) $row->reviews;
            $weeks[$start]['new_words'] += (int) $row->new_words;
        }

        $out = [];
        foreach ($weeks as $start => $week) {
            $out[] = ['week_start' => $start, 'reviews' => $week['reviews'], 'new_words' => min($week['new_words'], $week['reviews'])];
        }

        return $out;
    }

    private static function monday(CarbonImmutable $now): CarbonImmutable
    {
        return CarbonImmutable::parse($now->setTimezone(new DateTimeZone(RetentionReportService::REPORT_TIMEZONE))->toDateString())
            ->startOfWeek(CarbonInterface::MONDAY);
    }
}
