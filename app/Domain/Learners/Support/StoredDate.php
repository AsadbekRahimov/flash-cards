<?php

declare(strict_types=1);

namespace App\Domain\Learners\Support;

use App\Models\LearnerDailyStats;

/**
 * A local calendar date (`Y-m-d`) the way a `date` column holds it: THE one place that knows the representation
 * for `learner_daily_stats.date` (daily stats, series, freezes, referrals, challenges, league points, the center
 * report) and for the `date` columns of the league weeks and challenges that are compared with it.
 *
 * Why not just the `Y-m-d` string: Eloquent stores a `date` cast as `Y-m-d 00:00:00` (the connection's date format).
 * PostgreSQL normalises that to DATE either way, but on sqlite the column is TEXT, so a `'2026-10-28'` and a
 * `'2026-10-28 00:00:00'` are two different values: every write and every comparison (`=`, `>=`, `<=`, the unique
 * `(learner_id, date)`) must use one representation, or the unique index would not see duplicates and a range would
 * drop its boundary day. Whoever builds a bound for such a column calls {@see of()}; the behaviour on both drivers is
 * pinned by StoredDateTest.
 */
final class StoredDate
{
    /**
     * @param  string  $localDate  `Y-m-d`
     * @return string `Y-m-d 00:00:00`, what an Eloquent write of the same date puts into the column
     */
    public static function of(string $localDate): string
    {
        return (string) (new LearnerDailyStats)->fromDateTime($localDate);
    }
}
