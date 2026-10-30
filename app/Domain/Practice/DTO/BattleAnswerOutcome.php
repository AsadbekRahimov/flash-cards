<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Enums\Verdict;
use Carbon\CarbonImmutable;

/**
 * The answer to a round of a battle was taken (docs/16 §7.4): the verdict and the points of THIS player, the server time of
 * the acceptance, whether the round is resolved with it (the opponent had answered already), and the poll of the match as it
 * is now. No review here: the right answer and the opponent's come with the resolved round (`BattleReader::round`).
 */
final readonly class BattleAnswerOutcome
{
    public function __construct(
        public Verdict $verdict,
        public int $points,
        public CarbonImmutable $acceptedAt,
        public bool $roundResolved,
        public BattlePollView $poll,
    ) {}
}
