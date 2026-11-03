<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_items.effect` (docs/18 §12.2.6): what a booster does in a run. */
enum BoosterEffect: string
{
    case Hint = 'hint';
    case ExtraFlask = 'extra_flask';
    case SecondChance = 'second_chance';
}
