<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_items.status` (docs/18 §12.2.6): only `published` items are in the shop. */
enum ItemStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
