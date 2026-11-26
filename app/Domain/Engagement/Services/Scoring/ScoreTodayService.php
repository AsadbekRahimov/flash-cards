<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\DTO\ScoreToday;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Learning\DTO\ScoreDayCount;
use App\Domain\Operations\Support\ScalarBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Today N of 100" of the "Today" screen (docs/32 §8.2, `GET /today.score_today`): the learner's rating day (league zone) from its row
 * of `learner_score_days`, read by the unique `(learner_id, rating_day)` - WITHOUT a statement of its own: {@see addScalars()} puts
 * the reads into the screen's ONE statement of scalars ({@see ScalarBatch}, docs/19 K10), {@see fromScalars()} reads them back.
 *
 *   score_day   the day row as one string (`week_version:week_category:week_points:week_new:week_review:month_version:…`): one
 *               index probe instead of ten;
 *   score_wp / score_mp   `rules_version:category` of the learner's week / month (`learner_score_periods`, primary key) - the
 *               category is fixed by the first credit of the period, so a day without a credit yet shows the cap of that category;
 *   score_wv / score_mv   `rules_version` of the week / month row (unique `starts_on`) - the version before the learner's first
 *               credit of the period (no period row yet); neither row yet - the version that row will get ({@see ScoreRules::versionFor()}).
 *
 * Nothing is added outside `live` (no statement, no subquery): `off` and `shadow` show nothing, and neither does a day whose two counts
 * are still on v1 (`live` before the start).
 */
final class ScoreTodayService
{
    private const DAY_COLUMNS = ['week_version', 'week_category', 'week_points', 'week_new', 'week_review', 'month_version', 'month_category', 'month_points', 'month_new', 'month_review'];

    public function __construct(private readonly ScoreRules $rules) {}

    /** Whether the block can be shown at all (the mode is `live`). */
    public function shows(): bool
    {
        return $this->rules->mode() === ScoringMode::Live;
    }

    /** Adds the reads of the rating day to the caller's statement; false (nothing added) outside `live`. */
    public function addScalars(ScalarBatch $batch, int $learnerId, CarbonImmutable $now): bool
    {
        if (! $this->shows()) {
            return false;
        }

        $day = $this->rules->ratingDay($now);
        $week = $this->rules->periodStart(ScorePeriod::Week, $day)->toDateString();
        $month = $this->rules->periodStart(ScorePeriod::Month, $day)->toDateString();

        // A plain concatenation of the columns of one row (no input in it): `||` is the same operator on PostgreSQL and SQLite.
        $batch->value('score_day', DB::table('learner_score_days')
            ->selectRaw(implode(" || ':' || ", self::DAY_COLUMNS))
            ->where('learner_id', $learnerId)
            ->where('rating_day', StoredDate::of($day->toDateString())));
        foreach (['score_wp' => [ScorePeriod::Week, $week], 'score_mp' => [ScorePeriod::Month, $month]] as $alias => [$period, $startsOn]) {
            $batch->value($alias, DB::table('learner_score_periods')
                ->selectRaw("rules_version || ':' || category")
                ->where('learner_id', $learnerId)
                ->where('period', $period->value)
                ->where('starts_on', StoredDate::of($startsOn)));
        }
        $batch->value('score_wv', DB::table('league_weeks')->select('rules_version')->where('starts_on', StoredDate::of($week)));
        $batch->value('score_mv', DB::table('league_months')->select('rules_version')->where('starts_on', StoredDate::of($month)));

        return true;
    }

    /**
     * The block from the row of {@see addScalars()}; null when nothing was added or both counts of the day are on v1.
     *
     * @param  array<string, mixed>  $scalars
     * @param  Plan  $plan  the learner's plan now: the category of a period not fixed yet (the first credit fixes this one)
     */
    public function fromScalars(array $scalars, Plan $plan, CarbonImmutable $now): ?ScoreToday
    {
        if (! array_key_exists('score_day', $scalars)) {
            return null;
        }

        $day = $this->rules->ratingDay($now);
        $byPlan = ScoreCategory::fromPlan($plan);
        $row = self::parts($scalars['score_day'] ?? null, count(self::DAY_COLUMNS));

        if ($row !== null) {
            $week = $this->count((int) $row[0], ScoreCategory::tryFrom($row[1]) ?? $byPlan, (int) $row[2], (int) $row[3], (int) $row[4]);
            $month = $this->count((int) $row[5], ScoreCategory::tryFrom($row[6]) ?? $byPlan, (int) $row[7], (int) $row[8], (int) $row[9]);
        } else {
            $week = $this->emptyCount($scalars['score_wp'] ?? null, $scalars['score_wv'] ?? null, ScorePeriod::Week, $day, $byPlan);
            $month = $this->emptyCount($scalars['score_mp'] ?? null, $scalars['score_mv'] ?? null, ScorePeriod::Month, $day, $byPlan);
        }

        if ($week->version < 2 && $month->version < 2) {
            return null;
        }

        return new ScoreToday($week, $month, $day->addDay());
    }

    /** One count of the day under the limits of its version (a v1 count has none). */
    public function count(int $version, ScoreCategory $category, int $points, int $new, int $review): ScoreDayCount
    {
        $limits = $version >= 2 ? $this->rules->version($version)?->limitsFor($category) : null;

        return new ScoreDayCount($category, $version, $points, $limits?->dayCap, $new, $limits?->new, $review, $limits?->review);
    }

    /** A count of a day without a row: the period's fixed category and version, else the period row's version and the plan. */
    private function emptyCount(mixed $period, mixed $periodRow, ScorePeriod $kind, CarbonImmutable $day, ScoreCategory $byPlan): ScoreDayCount
    {
        $fixed = self::parts($period, 2);
        if ($fixed !== null) {
            return $this->count((int) $fixed[0], ScoreCategory::tryFrom($fixed[1]) ?? $byPlan, 0, 0, 0);
        }

        $version = is_numeric($periodRow) ? (int) $periodRow : $this->rules->versionFor($this->rules->periodStart($kind, $day));

        return $this->count($version, $byPlan, 0, 0, 0);
    }

    /** @return list<string>|null the `:`-separated parts of a concatenated scalar, null unless there are exactly `$n` */
    private static function parts(mixed $value, int $n): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parts = explode(':', $value);

        return count($parts) === $n ? $parts : null;
    }
}
