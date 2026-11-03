<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Practice\Enums\Verdict;

/**
 * What a task handler decides about one answer of a run (docs/18 §12.3.2). The service turns it into the score, the flasks, the
 * state of the element, the journal row and the stored `result`; the points are NOT here - `RunScoring` counts them from the verdict.
 *
 * - `verdict`: of THIS answer (pairs: of this matching);
 * - `reveal`: what the client may be shown after the answer (the right answer in the form of the kind); null for an unfinished round;
 * - `normalizedAnswer`: the answer as it goes to `game_run_attempts.payload`;
 * - `final`: the element is done with this answer (always, except a round of pairs before its last match);
 * - `firstAttempt`: the first try at the element (pairs: at this left word);
 * - `extra`: the new `state.pairs[element]` block of a round (null: not changed);
 * - `srsErrorWordIds`: the words this answer was a mistake about - brought closer in the cards after the commit (docs/18 §12.4);
 *   empty for `correct` and `typo`.
 */
final readonly class TaskGrade
{
    /**
     * @param  array<string, mixed>|null  $reveal
     * @param  array<string, mixed>  $normalizedAnswer
     * @param  array<string, mixed>|null  $extra
     * @param  list<int>  $srsErrorWordIds
     */
    public function __construct(
        public Verdict $verdict,
        public ?array $reveal,
        public array $normalizedAnswer,
        public bool $final = true,
        public bool $firstAttempt = true,
        public ?array $extra = null,
        public array $srsErrorWordIds = [],
    ) {}
}
