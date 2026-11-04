<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\SchedulerMetrics;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "SM-2 vs FSRS" comparison for the admin dashboard (docs/13 §2.5, docs/06 §9.6), computed per
 * `training_reviews.scheduler` over the last WINDOW_DAYS:
 *
 *  - pass-rate of due review cards: grades with `interval_before_days >= 1` (the card was graduated
 *    before the answer — a minute-step card always has interval 0) and `quality >= 3` / all such grades;
 *  - reviews per graduated word: grades in the window / distinct graduated cards (`repetitions >= 1`)
 *    the scheduler answered in the window (`word_repetitions.scheduler`, `last_reviewed_at`);
 *  - D7 retention per bucket (`learners.scheduler`, NULL = the config default): learners created
 *    between WINDOW_DAYS and 7 days ago who graded a card 7 or more days after their creation.
 */
final class SchedulerMetricsService
{
    public const WINDOW_DAYS = 28;

    public const RETENTION_DAY = 7;

    public function __construct(private readonly SchedulerResolver $resolver) {}

    /**
     * @return array<string, SchedulerMetrics> keyed by SchedulerKind value, both arms always present
     */
    public function compare(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays(self::WINDOW_DAYS);

        $reviews = $this->reviewCounts($since, $now);
        $graduated = $this->graduatedWords($since, $now);
        $retention = $this->retentionD7($since, $now);

        $out = [];
        foreach (SchedulerKind::cases() as $kind) {
            $key = $kind->value;
            $out[$key] = new SchedulerMetrics(
                kind: $kind,
                reviews: $reviews[$key]['total'] ?? 0,
                dueReviews: $reviews[$key]['due'] ?? 0,
                duePasses: $reviews[$key]['due_pass'] ?? 0,
                graduatedWords: $graduated[$key] ?? 0,
                cohortLearners: $retention[$key]['cohort'] ?? 0,
                retainedD7: $retention[$key]['retained'] ?? 0,
            );
        }

        return $out;
    }

    /**
     * @return array<string, array{total: int, due: int, due_pass: int}>
     */
    private function reviewCounts(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $rows = DB::table('training_reviews')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $now)
            ->groupBy('scheduler')
            ->select('scheduler')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN interval_before_days >= 1 THEN 1 ELSE 0 END) AS due')
            ->selectRaw('SUM(CASE WHEN interval_before_days >= 1 AND quality >= ? THEN 1 ELSE 0 END) AS due_pass', [SpacedRepetitionEngine::PASS_QUALITY])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->scheduler] = [
                'total' => (int) $row->total,
                'due' => (int) $row->due,
                'due_pass' => (int) $row->due_pass,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function graduatedWords(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $rows = DB::table('word_repetitions')
            ->where('repetitions', '>=', 1)
            ->where('last_reviewed_at', '>=', $since)
            ->where('last_reviewed_at', '<=', $now)
            ->groupBy('scheduler')
            ->select('scheduler')
            ->selectRaw('COUNT(*) AS total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->scheduler] = (int) $row->total;
        }

        return $out;
    }

    /**
     * The cohort and the retained of each arm in ONE statement, however many learners the cohort has. A learner is retained when
     * a grade exists at or after `created_at + RETENTION_DAY days` - the same as "the last grade is not before it", which the
     * earlier version fetched as `MAX(created_at)` for every chunk of 1000 learners (35 chunks, some 70 statements at 200 000
     * learners, docs/20 F7). Here that is an EXISTS per cohort learner: an index-only probe of
     * `training_reviews (learner_id, created_at)` that stops at the first grade past the deadline. The deleted learners stay out
     * (the soft-delete scope of `Learner::query()`), and a learner without a bucket (NULL) is counted in the arm the config names.
     *
     * @return array<string, array{cohort: int, retained: int}>
     */
    private function retentionD7(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $cohortEnd = $now->subDays(self::RETENTION_DAY);
        $default = $this->resolver->defaultKind()->value;
        [$deadline, $deadlineBindings] = $this->retentionDeadline();

        /** @var array<string, array{cohort: int, retained: int}> $out */
        $out = [];
        foreach (SchedulerKind::cases() as $kind) {
            $out[$kind->value] = ['cohort' => 0, 'retained' => 0];
        }

        // Grouped by the stored bucket, NULL included: the default is applied below, to a handful of rows.
        $rows = Learner::query()
            ->whereBetween('created_at', [$since, $cohortEnd])
            ->groupBy('scheduler')
            ->select('scheduler')
            ->selectRaw('COUNT(*) AS cohort')
            ->selectRaw(
                'SUM(CASE WHEN EXISTS (SELECT 1 FROM training_reviews WHERE training_reviews.learner_id = learners.id'
                ." AND training_reviews.created_at >= {$deadline}) THEN 1 ELSE 0 END) AS retained",
                $deadlineBindings,
            )
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $bucket = $row->scheduler === null ? $default : (string) $row->scheduler;

            // Only the arms: the column holds one of them or NULL, and a widget has no place for any other value.
            if (isset($out[$bucket])) {
                $out[$bucket]['cohort'] += (int) $row->cohort;
                $out[$bucket]['retained'] += (int) $row->retained;
            }
        }

        return $out;
    }

    /**
     * The SQL of "the learner's creation + RETENTION_DAY days" and its bindings. Both columns are wall-clock timestamps without a
     * zone, so this is plain calendar arithmetic, the same as `addDays()` of the application: an interval on PostgreSQL, the
     * `datetime()` modifier on SQLite (the database of the tests).
     *
     * @return array{0: literal-string, 1: list<int|string>}
     */
    private function retentionDeadline(): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return ['learners.created_at + make_interval(days => ?)', [self::RETENTION_DAY]];
        }

        return ['datetime(learners.created_at, ?)', ['+'.self::RETENTION_DAY.' days']];
    }
}
