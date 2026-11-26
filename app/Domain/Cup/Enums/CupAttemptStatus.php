<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cup_round_attempts.status` (docs/33 §2.5). */
enum CupAttemptStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
}
