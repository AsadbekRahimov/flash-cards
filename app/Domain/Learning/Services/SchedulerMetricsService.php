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
     * @return array<string, array{cohort: int, retained: int}>
     */
    private function retentionD7(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $cohortEnd = $now->subDays(self::RETENTION_DAY);
        $default = $this->resolver->defaultKind()->value;

        $out = [];
        foreach (SchedulerKind::cases() as $kind) {
            $out[$kind->value] = ['cohort' => 0, 'retained' => 0];
        }

        Learner::query()
            ->whereBetween('created_at', [$since, $cohortEnd])
            ->select(['id', 'created_at', 'scheduler'])
            ->chunkById(1000, function ($learners) use (&$out, $default): void {
                /** @var array<int, CarbonImmutable> $createdAt */
                $createdAt = [];
                /** @var array<int, string> $bucket */
                $bucket = [];
                /** @var Learner $learner */
                foreach ($learners as $learner) {
                    $createdAt[$learner->id] = CarbonImmutable::instance($learner->created_at ?? CarbonImmutable::now());
                    $bucket[$learner->id] = $learner->scheduler?->value ?? $default;
                    $out[$bucket[$learner->id]]['cohort']++;
                }

                $lastReview = DB::table('training_reviews')
                    ->whereIn('learner_id', array_keys($createdAt))
                    ->groupBy('learner_id')
                    ->select('learner_id')
                    ->selectRaw('MAX(created_at) AS last_at')
                    ->get();

                foreach ($lastReview as $row) {
                    $learnerId = (int) $row->learner_id;
                    $lastAt = CarbonImmutable::parse((string) $row->last_at);
                    if (! $lastAt->lessThan($createdAt[$learnerId]->addDays(self::RETENTION_DAY))) {
                        $out[$bucket[$learnerId]]['retained']++;
                    }
                }
            });

        return $out;
    }
}
