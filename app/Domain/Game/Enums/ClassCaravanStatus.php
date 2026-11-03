<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `class_caravans.status` (docs/18 §12.2.8). */
enum ClassCaravanStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
    case Canceled = 'canceled';
}
