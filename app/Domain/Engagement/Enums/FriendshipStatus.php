<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** State of a `friendships` row (docs/13 §1.5). A declined or removed friendship has no row. */
enum FriendshipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Blocked = 'blocked';
}
