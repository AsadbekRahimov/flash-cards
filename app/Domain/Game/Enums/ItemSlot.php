<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_items.slot` (docs/18 §12.2.6): where a cosmetic is worn; the keys of `game_profiles.equipped`. */
enum ItemSlot: string
{
    case Camel = 'camel';
    case Carpet = 'carpet';
    case Tent = 'tent';
    case Frame = 'frame';
}
