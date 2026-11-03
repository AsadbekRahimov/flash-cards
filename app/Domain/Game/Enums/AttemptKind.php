<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_run_attempts.kind` (docs/18 §12.2.2): the kind of an accepted request of a run. */
enum AttemptKind: string
{
    case Answer = 'answer';
    case Pair = 'pair';
    case Skip = 'skip';
    case Hint = 'hint';
    case Booster = 'booster';
}
