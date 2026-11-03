<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_inventory.source` (docs/18 §12.2.6): how the learner got the item. */
enum InventorySource: string
{
    case Purchase = 'purchase';
    case Achievement = 'achievement';
    case Admin = 'admin';
    case Default = 'default';
}
