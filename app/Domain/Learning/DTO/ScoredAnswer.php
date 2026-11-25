<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\Enums\AnswerVerdict;
use Carbon\CarbonImmutable;
use Closure;

/**
 * One answer handed to the word points ({@see AnswerScoreRecorder}, docs/32 §3.1), built by the answering domain inside the transaction
 * of its answer.
 *
 * Two inputs may be LAZY, so that an answer that ends early (no lexeme, the attempt of the day spent, a failed answer) costs nothing for
 * them: the card before the answer (`$before` - the game reads it with one unlocked indexed query, docs/32 §3.6; the training has it in
 * hand) and the plan (`$plan` - only the first credit of a rating day fixes the category of a period; the training has the entitlement
 * in hand, the game resolves it from the cache). Each closure is called at most once.
 */
final class ScoredAnswer
{
    private bool $beforeResolved = false;

    private ?CardStateBefore $resolvedBefore = null;

    private ?Plan $resolvedPlan = null;

    /**
     * @param  CardStateBefore|(Closure(): ?CardStateBefore)|null  $before
     * @param  Plan|(Closure(): Plan)  $plan
     */
    public function __construct(
        public readonly int $learnerId,
        public readonly ?int $lexemeId,
        public readonly int $wordId,
        public readonly ScoreSource $source,
        public readonly int $sourceId,
        public readonly AnswerVerdict $verdict,
        private readonly CardStateBefore|Closure|null $before,
        private readonly Plan|Closure $plan,
        public readonly CarbonImmutable $now,
    ) {}

    public function before(): ?CardStateBefore
    {
        if (! $this->beforeResolved) {
            $this->resolvedBefore = $this->before instanceof Closure ? ($this->before)() : $this->before;
            $this->beforeResolved = true;
        }

        return $this->resolvedBefore;
    }

    public function plan(): Plan
    {
        return $this->resolvedPlan ??= $this->plan instanceof Closure ? ($this->plan)() : $this->plan;
    }
}
