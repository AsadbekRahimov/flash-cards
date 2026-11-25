<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\ScoreDayCount;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Models\Learner;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The contract of the word points as the training and the game get it (docs/32 §3.1, §4 "after commit"): {@see WordScoreService}
 * decides and writes inside the transaction of the answer; this wrapper only reads its outcome and hooks the follow-ups after the
 * commit - one place for both callers, no statement of its own inside the answer's transaction:
 *
 *   - `newLongLexeme` → the achievement `long_recall_25` is looked at (§8.4, task S14; {@see AchievementService::onLongRecall});
 *   - a count of the rating day reached its cap with this answer → the product event `score.day_capped` (task S16), once per
 *     learner, day and count: the cap is crossed by exactly one answer (the counts only grow within a day).
 *
 * Nothing happens with the points off (the outcome carries neither). A failure of a hook is only reported: the answer is committed.
 */
final class WordScoreHooks implements AnswerScoreRecorder
{
    public function __construct(
        private readonly WordScoreService $scores,
        private readonly ScoreRules $rules,
        private readonly AchievementService $achievements,
        private readonly ProductEventRecorder $events,
    ) {}

    public function records(): bool
    {
        return $this->scores->records();
    }

    public function recordsGame(): bool
    {
        return $this->scores->recordsGame();
    }

    public function record(ScoredAnswer $answer): WordScoreOutcome
    {
        $outcome = $this->scores->record($answer);

        if ($outcome->newLongLexeme) {
            DB::afterCommit(function () use ($answer): void {
                try {
                    $this->achievements->onLongRecall($answer->learnerId, $answer->now);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        $capped = array_filter([
            'week' => self::justCapped($outcome->week, $outcome->weekAdded),
            'month' => self::justCapped($outcome->month, $outcome->monthAdded),
        ]);
        if ($capped !== []) {
            $day = $this->rules->ratingDay($answer->now)->toDateString();
            DB::afterCommit(function () use ($answer, $capped, $day): void {
                try {
                    $learner = Learner::query()->find($answer->learnerId);
                    foreach ($capped as $count => $row) {
                        $this->events->record(ProductEventName::ScoreDayCapped, [
                            'count' => $count, 'rating_day' => $day, 'category' => $row->category->value, 'cap' => $row->cap,
                        ], learner: $learner);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $outcome;
    }

    /** The count after the answer when exactly this answer took it to its cap (a v2 count: the v1 one has no cap); null otherwise. */
    private static function justCapped(?ScoreDayCount $count, int $added): ?ScoreDayCount
    {
        if ($count === null || $count->cap === null || $added <= 0) {
            return null;
        }

        return $count->points >= $count->cap && $count->points - $added < $count->cap ? $count : null;
    }
}
