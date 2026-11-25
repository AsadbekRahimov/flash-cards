<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Domain\Engagement\Enums\ScorePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The retention of the journal `score_credits` (docs/32 §2.7, §12.3, task S16): the rows of the rating days older than
 * `leagues.scoring.credits_retention_days` (180) go; the day totals (`learner_score_days`) stay for ever. Only days of closed months:
 * the journal is what a correction, the check of the prize candidates and the distinct long repetitions of a month read, so the
 * cut-off never passes the first day of the current month nor of a month that is not confirmed yet (`open`, `closing`,
 * `pending_review` - whatever the retention says).
 *
 * Batches of at most `$batch` rows, each its own statement (autocommit - no long transaction, the locks of a batch are released at
 * once): `DELETE … WHERE rating_day < ? AND id IN (SELECT id … WHERE rating_day < ? LIMIT ?)`. On PostgreSQL the BRIN
 * `(rating_day)` serves the range of the inner select (docs/32 §12.1, migration 2026_11_25_300101). Idempotent: the next run only
 * finds the days that aged since.
 */
final class ScoreCreditPruner
{
    public function __construct(private readonly ScoreRules $rules) {}

    /** The first rating day that is KEPT: everything strictly before it may go. */
    public function cutoff(CarbonImmutable $now): CarbonImmutable
    {
        $today = $this->rules->ratingDay($now);
        $cutoff = $today->subDays($this->rules->creditsRetentionDays());

        $currentMonth = $this->rules->periodStart(ScorePeriod::Month, $today);
        if ($currentMonth < $cutoff) {
            $cutoff = $currentMonth;
        }

        $unconfirmed = DB::table('league_months')
            ->whereIn('status', [LeagueMonthStatus::Open->value, LeagueMonthStatus::Closing->value, LeagueMonthStatus::PendingReview->value])
            ->min('starts_on');
        if ($unconfirmed !== null) {
            $month = CarbonImmutable::parse(substr((string) $unconfirmed, 0, 10), $this->rules->timezone());
            if ($month < $cutoff) {
                $cutoff = $month;
            }
        }

        return $cutoff;
    }

    /**
     * Deletes the expired rows in batches; the number deleted.
     *
     * @param  (callable(int $deleted): void)|null  $progress  after every batch that deleted something
     */
    public function prune(CarbonImmutable $now, int $batch = 5000, ?callable $progress = null): int
    {
        $batch = max(1, $batch);
        $before = $this->cutoff($now)->toDateString();
        $deleted = 0;

        do {
            $gone = DB::delete(
                'DELETE FROM score_credits WHERE rating_day < ? AND id IN (SELECT id FROM score_credits WHERE rating_day < ? LIMIT ?)',
                [$before, $before, $batch],
            );
            $deleted += $gone;

            if ($gone > 0 && $progress !== null) {
                $progress($deleted);
            }
        } while ($gone === $batch);

        return $deleted;
    }
}
