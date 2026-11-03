<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_runs.kind` (docs/18 §12.2.1): a stop (a lesson of the map), the boss of a city, the daily caravan, the boss of a class caravan. */
enum RunKind: string
{
    case Stop = 'stop';
    case Boss = 'boss';
    case Daily = 'daily';
    case ClassBoss = 'class_boss';
}
