<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

/** What a `/start <payload>` deep link carries (docs/12 §7.2). */
enum StartPayloadKind: string
{
    case None = 'none';
    case Ref = 'ref';
    case Src = 'src';
    case Course = 'course';
    case Org = 'org';
    case Friend = 'friend';
    /** `link_<token>`: a staff user connects their Telegram account — not a learner's link (docs/13 §6.2). */
    case AccountLink = 'account_link';
}
