<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The status of a solo session (`practice_sessions.status`, docs/16 §2.5, §6.1). */
enum SessionStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
}
