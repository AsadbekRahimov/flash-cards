<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_items.kind` (docs/18 §12.2.6). */
enum ItemKind: string
{
    case Cosmetic = 'cosmetic';
    case Booster = 'booster';
}
