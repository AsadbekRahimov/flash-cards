<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Enums\Verdict;

/**
 * What a mode handler decides about one answer (docs/16 §1.2). The service turns it into the state of the element,
 * the counters of the session and the stored `result`.
 *
 * - `verdict`: of THIS answer (pairs: of this matching - correct / wrong);
 * - `reveal`: what the client may be shown after the answer (the correct text, the explanation...); null for pairs;
 * - `normalizedAnswer`: the answer as it is stored in `practice_attempts.payload` (pairs: `{left_id, right_id}`, and
 *   that is also what the service returns as `pair`);
 * - `final`: the element is done with this answer (always true, except pairs: the round is done when every word is matched);
 * - `points`: a battle, 0..100;
 * - `firstAttempt`: first try at the element (pairs: at this left word); only a correct first try counts in `correct_first`;
 * - `extra`: pairs - the new `state.pairs[element]` block; null = the block is not changed.
 */
final readonly class GradeResult
{
    /**
     * @param  array<string, mixed>|null  $reveal
     * @param  array<string, mixed>  $normalizedAnswer
     * @param  array<string, mixed>|null  $extra
     */
    public function __construct(
        public Verdict $verdict,
        public ?array $reveal,
        public array $normalizedAnswer,
        public bool $final = true,
        public int $points = 0,
        public bool $firstAttempt = true,
        public ?array $extra = null,
    ) {}
}
