<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_runs.status` (docs/18 §12.2.1). */
enum RunStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
}
