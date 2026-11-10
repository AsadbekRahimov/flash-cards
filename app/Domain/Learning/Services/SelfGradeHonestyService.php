<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learning\Enums\ExerciseType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Honesty of self-grading" for the admin dashboard (docs/06 §12.2): how many of the WRONG objectively graded answers
 * (a choice or a typed one) followed a self-grade "easy" on the same word — the `review.self_grade_mismatch` events — over
 * the last {@see SchedulerMetricsService::WINDOW_DAYS} days.
 *
 * Two statements, both a range of an existing index: the events by `product_events (name, occurred_at)` (they are only the
 * mismatches, a small set), the denominator by `training_reviews (created_at, learner_id)` — the range the scheduler
 * comparison of the same dashboard already reads; the figure is cached by the widget.
 */
final class SelfGradeHonestyService
{
    /** @return array{mismatches: int, wrong_objective: int} */
    public function window(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays(SchedulerMetricsService::WINDOW_DAYS);

        $mismatches = DB::table('product_events')
            ->where('name', ProductEventName::ReviewSelfGradeMismatch->value)
            ->where('occurred_at', '>=', $since)
            ->where('occurred_at', '<=', $now)
            ->count();

        $wrong = DB::table('training_reviews')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $now)
            ->where('exercise', '<>', ExerciseType::Flash->value)
            ->where('quality', '<', SpacedRepetitionEngine::PASS_QUALITY)
            ->count();

        return ['mismatches' => $mismatches, 'wrong_objective' => $wrong];
    }
}
