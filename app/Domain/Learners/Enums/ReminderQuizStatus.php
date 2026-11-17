<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

enum ReminderQuizStatus: string
{
    case Accepted = 'accepted';
    case Expired = 'expired';
}
