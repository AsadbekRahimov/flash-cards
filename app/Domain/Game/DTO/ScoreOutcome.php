<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * What one accepted answer did to the run (docs/18 §12.5.2, `RunScoring::apply()`): the points it earned, the combo after it,
 * whether a flask was lost (or spared by the "second chance"), the flasks after it, whether the oasis gave one back, and whether
 * the run is over (`failed`: the flasks are out). `counted` says which counter of `game_runs` the answer goes to:
 * `correct` / `typo` / `wrong` / `hint` (an answer after a hint, whatever its verdict) / `none` (a match of pairs before the round ends).
 */
final readonly class ScoreOutcome
{
    public const COUNTED_CORRECT = 'correct';

    public const COUNTED_TYPO = 'typo';

    public const COUNTED_WRONG = 'wrong';

    public const COUNTED_HINT = 'hint';

    public const COUNTED_NONE = 'none';

    public function __construct(
        public int $points,
        public int $combo,
        public bool $flaskLost,
        public bool $secondChanceUsed,
        public int $flasks,
        public bool $oasisApplied,
        public bool $failed,
        public string $counted,
    ) {}
}
