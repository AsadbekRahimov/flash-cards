<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The side of a player in a battle (`battle_participants.role`, docs/16 §2.7). */
enum BattleRole: string
{
    case Challenger = 'challenger';
    case Opponent = 'opponent';

    public function other(): self
    {
        return $this === self::Challenger ? self::Opponent : self::Challenger;
    }
}
