<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cup_round_attempts.finish_reason` (docs/33 §2.5): every task answered, or the time ran out. */
enum CupAttemptFinishReason: string
{
    case Completed = 'completed';
    case Expired = 'expired';
}
