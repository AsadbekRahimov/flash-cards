<?php

declare(strict_types=1);

namespace App\Domain\Learning\Contracts;

use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;

/**
 * The word points of an answer (docs/32 §3.1): the one place every answering domain (the training, the game) hands its answers to.
 * The training and the game do not depend on the scoring service itself - the default binding is
 * `App\Domain\Engagement\Services\Scoring\WordScoreService` (AppServiceProvider), and a test may bind a double, as with
 * {@see NewCardBudgetResolver}.
 *
 * Contract of {@see record()}:
 *   - called INSIDE the transaction of the answer, after the answer's own locks (`training_sessions → word_repetitions`, or
 *     `game_runs`) and BEFORE `learner_daily_stats` is touched: it locks `learner_lexemes → (learner_score_periods) → learner_score_days`
 *     (docs/32 §3.7), so no transaction ever takes those in another order;
 *   - never called twice for one accepted answer: a repeated request is refused (training: 409 `card_not_due`) or answered from the
 *     journal (game: `game_run_attempts`) before it gets here;
 *   - with the word points off it returns at once, without a statement.
 */
interface AnswerScoreRecorder
{
    /** Whether answers are recorded at all (`leagues.scoring.mode` is `shadow` or `live` and a valid rules version exists). */
    public function records(): bool;

    /** Whether the answers of the game are recorded (the above and `leagues.scoring.game_enabled`, owner's decision В6). */
    public function recordsGame(): bool;

    public function record(ScoredAnswer $answer): WordScoreOutcome;
}
