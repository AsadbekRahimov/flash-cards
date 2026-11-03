<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\GameRun;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The events of a run (docs/18 §12.8), shared by {@see RunService} (a run closed `expired` / `abandoned`) and {@see RunFinisher} (a run
 * played to its end). Called after the commit, never inside; the recorder itself swallows and logs a failed insert.
 */
final class RunEventRecorder
{
    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LearnerStatsService $stats,
    ) {}

    /**
     * `game.run_finished`: once per run, by whoever moved it to `finished`. A daily caravan played to its end (`completed`) adds
     * `game.daily_completed` - never instead of `game.run_finished`.
     */
    public function finished(GameRun $run, ?Learner $learner): void
    {
        $finishedAt = $run->finished_at ?? CarbonImmutable::now();
        $events = is_array($run->snapshot['events'] ?? null) ? $run->snapshot['events'] : [];
        $event = is_array($events[0] ?? null) ? ($events[0]['type'] ?? null) : null;

        $this->events->record(ProductEventName::GameRunFinished, [
            'run_id' => $run->id,
            'kind' => $run->kind->value,
            'finish_reason' => $run->finish_reason?->value,
            'total' => $run->total,
            'answered' => $run->answered,
            'correct' => $run->correct,
            'typos' => $run->typos,
            'wrong' => $run->wrong,
            'hints' => $run->hints,
            'stars' => $run->stars,
            'score' => $run->score,
            'coins' => $run->coins_awarded,
            'league_points' => $run->league_points,
            'srs_pulled' => $run->srs_pulled,
            'duration_ms' => max(0, ($finishedAt->getTimestamp() - $run->started_at->getTimestamp()) * 1000),
            'event' => is_string($event) ? $event : null,
        ], learner: $learner);

        if ($learner !== null && $run->kind === RunKind::Daily && $run->finish_reason === RunFinishReason::Completed) {
            $this->events->record(ProductEventName::GameDailyCompleted, [
                'run_id' => $run->id,
                // The correct answers of the caravan leave the cards where they are (docs/18 §12.4), so this is the day's number.
                'due_count' => $this->stats->dueCount($learner, $finishedAt),
                'correct' => $run->correct,
            ], learner: $learner);
        }
    }

    /** `game.coins_earned`: an applied credit of the reward of a run. */
    public function coinsEarned(GameRun $run, Learner $learner, int $amount, LedgerKind $kind, int $balance): void
    {
        $this->events->record(ProductEventName::GameCoinsEarned, [
            'amount' => $amount,
            'kind' => $kind->value,
            'ref_type' => 'game_run',
            'ref_id' => $run->id,
            'balance' => $balance,
        ], learner: $learner);
    }
}
