<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_items.availability` (docs/18 §12.2.6): `exclusive` and `seasonal` need the feature `game.all_bosses` (Pro) at the moment of the purchase. */
enum ItemAvailability: string
{
    case Base = 'base';
    case Achievement = 'achievement';
    case Seasonal = 'seasonal';
    case Exclusive = 'exclusive';

    /** The purchase needs Pro (what is bought stays after Pro ends). */
    public function needsPro(): bool
    {
        return $this === self::Seasonal || $this === self::Exclusive;
    }
}
