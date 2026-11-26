<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cup_messages.kind` (docs/33 §2.8). */
enum CupMessageKind: string
{
    case Card = 'card';
    case Summary = 'summary';
    case Final = 'final';
    case Duel = 'duel';
    case Notice = 'notice';
}
