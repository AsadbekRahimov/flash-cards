<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The phase of a round of a `playing` battle (`battles.phase`, docs/16 §2.7, §8.1). */
enum BattlePhase: string
{
    case Answering = 'answering';
    case Reveal = 'reveal';
}
