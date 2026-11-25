<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\ScoreToday;
use App\Domain\Engagement\DTO\SessionScore;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Learners\Support\StoredDate;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The word points of a finished training session (docs/32 §3.5, §8.2, §12.3 "S12 API"): read, never written - the finish credits
 * nothing (§4), so a repeated `/finish` answers the same. Two statements, only in `live` and only for a session with answers:
 *
 *   1. the session's credits - `score_credits` of (learner, the rating days of the session) by the unique `(learner_id, rating_day,
 *      lexeme_id)`, filtered by `source_id` and grouped by day and kind (at most 2 days × 3 kinds of rows);
 *   2. the rating days of the current week - `learner_score_days` by the unique `(learner_id, rating_day)`, at most seven rows: the week
 *      sum of the best days with and without the session (§3.5), and today's state.
 *
 * `null` outside `live`, for the review of a lesson (it gives no points, docs/06 §15), for a session without answers and when no count
 * of the session's days is on v2 (the old clients and the v1 periods keep the shape of before).
 */
final class SessionScoreService
{
    public function __construct(
        private readonly ScoreRules $rules,
        private readonly ScoreTodayService $today,
    ) {}

    public function forSession(TrainingSession $session, int $reviewed, CarbonImmutable $now): ?SessionScore
    {
        if (! $this->today->shows() || $reviewed === 0 || $session->mode === TrainingSession::MODE_LESSON_REVIEW) {
            return null;
        }

        $todayDay = $this->rules->ratingDay($now);
        $firstDay = $this->rules->ratingDay(CarbonImmutable::instance($session->started_at));
        $monday = $this->rules->periodStart(ScorePeriod::Week, $todayDay);

        $credits = DB::table('score_credits')
            ->where('learner_id', $session->learner_id)
            ->where('rating_day', '>=', StoredDate::of(min($firstDay, $todayDay)->toDateString()))
            ->where('rating_day', '<=', StoredDate::of($todayDay->toDateString()))
            ->whereIn('source', [ScoreSource::Training->value, ScoreSource::GroupLesson->value])
            ->where('source_id', $session->id)
            ->whereNull('voided_at')
            ->groupBy('rating_day', 'kind')
            ->get(['rating_day', 'kind', DB::raw('count(*) as n'), DB::raw('sum(week_points) as week_points'), DB::raw('sum(month_points) as month_points')]);

        $days = DB::table('learner_score_days')
            ->where('learner_id', $session->learner_id)
            ->where('rating_day', '>=', StoredDate::of($monday->toDateString()))
            ->where('rating_day', '<=', StoredDate::of($monday->addDays(6)->toDateString()))
            ->get(['rating_day', 'week_version', 'week_category', 'week_points', 'week_new', 'week_review', 'month_version', 'month_category', 'month_points', 'month_new', 'month_review']);

        $todayRow = null;
        $weekPoints = [];
        foreach ($days as $row) {
            $date = substr((string) $row->rating_day, 0, 10);
            $weekPoints[$date] = (int) $row->week_points;
            if ($date === $todayDay->toDateString()) {
                $todayRow = $row;
            }
        }

        $weekVersion = $todayRow !== null ? (int) $todayRow->week_version : $this->rules->versionFor($monday);
        $monthVersion = $todayRow !== null ? (int) $todayRow->month_version : $this->rules->versionFor($this->rules->periodStart(ScorePeriod::Month, $todayDay));
        $reference = $this->rules->reference($weekVersion, $monthVersion);
        if (($weekVersion < 2 && $monthVersion < 2) || $reference === null) {
            return null;
        }

        $earned = 0;
        $weekAdded = 0;
        $monthAdded = 0;
        $sessionWeekByDay = [];
        foreach ($credits as $row) {
            $kind = ScoreKind::tryFrom((string) $row->kind);
            $earned += (int) $row->n * ($kind === null ? 0 : $reference->pointsFor($kind));
            $weekAdded += (int) $row->week_points;
            $monthAdded += (int) $row->month_points;
            $date = substr((string) $row->rating_day, 0, 10);
            $sessionWeekByDay[$date] = ($sessionWeekByDay[$date] ?? 0) + (int) $row->week_points;
        }

        $without = $weekPoints;
        foreach ($sessionWeekByDay as $date => $points) {
            if (array_key_exists($date, $without)) {
                $without[$date] = max(0, $without[$date] - $points);
            }
        }
        $bestDays = ($this->rules->version($weekVersion) ?? $reference)->bestDays;
        $weekCounts = $weekVersion >= 2;

        return new SessionScore(
            earned: $earned,
            weekAdded: $weekAdded,
            monthAdded: $monthAdded,
            cappedOff: max(0, $earned - ($weekCounts ? $weekAdded : $monthAdded)),
            weekRatingDelta: $weekCounts ? self::best($weekPoints, $bestDays) - self::best($without, $bestDays) : 0,
            weekCounts: $weekCounts,
            today: $todayRow === null ? null : new ScoreToday(
                $this->today->count($weekVersion, ScoreCategory::from((string) $todayRow->week_category), (int) $todayRow->week_points, (int) $todayRow->week_new, (int) $todayRow->week_review),
                $this->today->count($monthVersion, ScoreCategory::from((string) $todayRow->month_category), (int) $todayRow->month_points, (int) $todayRow->month_new, (int) $todayRow->month_review),
                $todayDay->addDay(),
            ),
        );
    }

    /**
     * The sum of the `$n` best days (docs/32 §3.5; the order of equal days does not change the sum).
     *
     * @param  array<string, int>  $points
     */
    private static function best(array $points, int $n): int
    {
        rsort($points);

        return array_sum(array_slice($points, 0, $n));
    }
}
