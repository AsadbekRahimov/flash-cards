<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * What the copy of Pro may say about the game "Caravan" (docs/18 §12.7.4), as decided by `ProPromises::game()`; the Mini App gets
 * the case in `game.pro_promise` of `/me` (the `value` of this enum):
 *
 *  - `None`: nothing (the game is not open to the reader, or Free has no limit there for Pro to lift);
 *  - `Full`: "a caravan without a limit of runs and all the bosses".
 */
enum ProGamePromise: string
{
    case None = 'none';
    case Full = 'full';
}
